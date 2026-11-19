#!/usr/bin/env python3
"""Зеркало live UI-job + schedule + статистика → Core Postgres (для Grafana)."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[2]
CACHE = ROOT / "data" / "cache"
JOB_PATH = CACHE / "job.status.json"
SCHEDULE_PATH = CACHE / "schedule_state.json"
APPLIED_PATH = CACHE / "applied_by_vacancy.json"
APP_ERRORS_PATH = CACHE / "app_errors.json"
REJECTIONS_PATH = CACHE / "chat_rejections.json"
SUITABLE_STATS_PATH = CACHE / "suitable_run_stats.json"
OWNER_PATH = CACHE / "vacancy_resume_owner.json"
RESUMES_PATH = CACHE / "resumes.json"
SCHEDULE_CFG = ROOT / "config" / "schedule.json"
ENV_PATH = ROOT / ".env"

# Короткие ярлыки как ListPage::RESUME_LABELS.
RESUME_LABELS = {
    "d511f1d1ff1145b22e0039ed1f4e6946685076": "TPM",
    "f3e5e5f7ff0f50d3e50039ed1f4436664d7338": "PM",
    "d7cf4e9eff11521f2e0039ed1f504a67754631": "PHP",
}

PGHOST = os.environ.get("LIVE_PGHOST", "172.20.0.8")
PGPORT = os.environ.get("LIVE_PGPORT", "5432")
PGUSER = os.environ.get("LIVE_PGUSER", "job_search")
PGPASSWORD = os.environ.get("LIVE_PGPASSWORD", "job_search")
PGDATABASE = os.environ.get("LIVE_PGDATABASE", "job_search")

_BALANCE_CACHE: dict[str, object] = {"at": 0.0, "value": None, "provider": ""}
_BALANCE_TTL_SEC = 60.0


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def read_json(path: Path) -> dict:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return data if isinstance(data, dict) else {}


def pid_alive(pid: int) -> bool:
    if pid <= 0:
        return False
    try:
        os.kill(pid, 0)
        return True
    except OSError:
        return False


def sql_literal(value: object) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "TRUE" if value else "FALSE"
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return str(value)
    text = str(value).replace("\x00", "")
    return "'" + text.replace("'", "''") + "'"


def ts_literal(value: object) -> str:
    if value is None or value == "":
        return "NULL"
    if isinstance(value, (int, float)):
        dt = datetime.fromtimestamp(float(value), tz=timezone.utc)
        return sql_literal(dt.isoformat())
    text = str(value).strip()
    if not text:
        return "NULL"
    return sql_literal(text)


def upsert_rows(rows: list[dict]) -> None:
    if not rows:
        return
    values_sql = []
    for row in rows:
        payload = json.dumps(row.get("payload") or {}, ensure_ascii=False)
        values_sql.append(
            "("
            + ", ".join(
                [
                    sql_literal(row["key"]),
                    sql_literal(row["kind"]),
                    sql_literal(row.get("label") or ""),
                    sql_literal(row.get("state")),
                    sql_literal(row.get("step")),
                    sql_literal(row.get("ok")),
                    sql_literal(row.get("message")),
                    sql_literal(row.get("progress_index")),
                    sql_literal(row.get("progress_total")),
                    sql_literal(row.get("pid")),
                    ts_literal(row.get("last_run_at")),
                    ts_literal(row.get("updated_at")) + "::timestamptz",
                    sql_literal(payload) + "::jsonb",
                ]
            )
            + ")"
        )
    sql = f"""
INSERT INTO ui_live_jobs AS t (
  key, kind, label, state, step, ok, message,
  progress_index, progress_total, pid, last_run_at, updated_at, payload
) VALUES
{',\n'.join(values_sql)}
ON CONFLICT (key) DO UPDATE SET
  kind = EXCLUDED.kind,
  label = EXCLUDED.label,
  state = EXCLUDED.state,
  step = EXCLUDED.step,
  ok = EXCLUDED.ok,
  message = EXCLUDED.message,
  progress_index = EXCLUDED.progress_index,
  progress_total = EXCLUDED.progress_total,
  pid = EXCLUDED.pid,
  last_run_at = EXCLUDED.last_run_at,
  updated_at = EXCLUDED.updated_at,
  payload = EXCLUDED.payload;
"""
    env = os.environ.copy()
    env["PGPASSWORD"] = PGPASSWORD
    proc = subprocess.run(
        [
            "psql",
            "-h",
            PGHOST,
            "-p",
            PGPORT,
            "-U",
            PGUSER,
            "-d",
            PGDATABASE,
            "-v",
            "ON_ERROR_STOP=1",
            "-c",
            sql,
        ],
        env=env,
        capture_output=True,
        text=True,
    )
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip() or proc.stdout.strip() or "psql failed")


def schedule_labels() -> dict[str, str]:
    cfg = read_json(SCHEDULE_CFG)
    out: dict[str, str] = {}
    tasks = cfg.get("tasks")
    if not isinstance(tasks, list):
        return out
    for item in tasks:
        if not isinstance(item, dict):
            continue
        tid = str(item.get("id") or "").strip()
        if tid:
            out[tid] = str(item.get("label") or tid)
    return out


def read_env_key(name: str) -> str:
    if not ENV_PATH.is_file():
        return ""
    prefix = name + "="
    for line in ENV_PATH.read_text(encoding="utf-8", errors="replace").splitlines():
        if line.startswith(prefix):
            return line.split("=", 1)[1].strip().strip('"').strip("'")
    return ""


def captcha_provider() -> tuple[str, str]:
    """host, label — 2captcha приоритетнее RuCaptcha (как в ServiceCaptchaSolver)."""
    two = read_env_key("TWOCAPTCHA_API_KEY")
    if two:
        return "https://api.2captcha.com", "2captcha"
    ru = read_env_key("RUCAPTCHA_API_KEY")
    if ru:
        return "https://api.rucaptcha.com", "rucaptcha"
    return "", ""


def captcha_api_key() -> str:
    return read_env_key("TWOCAPTCHA_API_KEY") or read_env_key("RUCAPTCHA_API_KEY")


def fetch_captcha_balance() -> tuple[float | None, str]:
    """Живой баланс провайдера капчи; кеш 60 с, чтобы не долбить API каждые 2 с."""
    now = time.time()
    cached_at = float(_BALANCE_CACHE.get("at") or 0.0)
    if now - cached_at < _BALANCE_TTL_SEC and _BALANCE_CACHE.get("value") is not None:
        return (
            float(_BALANCE_CACHE["value"]),  # type: ignore[arg-type]
            str(_BALANCE_CACHE.get("provider") or ""),
        )
    host, label = captcha_provider()
    key = captcha_api_key()
    if not host or not key:
        return None, ""
    try:
        import urllib.request

        req = urllib.request.Request(
            host + "/getBalance",
            data=json.dumps({"clientKey": key}).encode(),
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=12) as resp:
            data = json.loads(resp.read().decode())
        # errorId=0 — успех; нельзя писать `or -1` (0 ложный).
        if int(data.get("errorId", -1)) != 0:
            return None, label
        bal = data.get("balance")
        if not isinstance(bal, (int, float)):
            return None, label
        value = round(float(bal), 4)
        _BALANCE_CACHE["at"] = now
        _BALANCE_CACHE["value"] = value
        _BALANCE_CACHE["provider"] = label
        return value, label
    except Exception as exc:  # noqa: BLE001
        print(f"captcha balance fetch: {exc}", file=sys.stderr, flush=True)
        return None, label


def psql_scalar(sql: str) -> str:
    env = os.environ.copy()
    env["PGPASSWORD"] = PGPASSWORD
    proc = subprocess.run(
        [
            "psql",
            "-h",
            PGHOST,
            "-p",
            PGPORT,
            "-U",
            PGUSER,
            "-d",
            PGDATABASE,
            "-At",
            "-c",
            sql,
        ],
        env=env,
        capture_output=True,
        text=True,
    )
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip() or "psql scalar failed")
    return (proc.stdout or "").strip()


def captcha_counts_today() -> tuple[int, int]:
    """auto_ok / fallback_tg за календарный день Europe/Moscow."""
    sql = """
SELECT
  count(*) FILTER (WHERE text = 'auto_ok')::text || '|' ||
  count(*) FILTER (WHERE text = 'fallback_tg')::text
FROM telegram_dm_events
WHERE kind = 'captcha'
  AND direction = 'meta'
  AND at >= date_trunc('day', now() AT TIME ZONE 'Europe/Moscow')
             AT TIME ZONE 'Europe/Moscow'
"""
    raw = psql_scalar(sql)
    if "|" not in raw:
        return 0, 0
    a, b = raw.split("|", 1)
    return int(a or 0), int(b or 0)


def build_rows() -> list[dict]:
    now = utc_now()
    rows: list[dict] = []

    job = read_json(JOB_PATH)
    pid = int(job.get("pid") or 0)
    state = str(job.get("state") or "idle")
    alive = pid_alive(pid)
    if state == "running" and not alive:
        state = "stale"
    progress = job.get("progress") if isinstance(job.get("progress"), dict) else {}
    p_index = progress.get("index")
    p_total = progress.get("total")
    if isinstance(job.get("progress"), str):
        # иногда progress — строка
        p_index = None
        p_total = None
    rows.append(
        {
            "key": "busy",
            "kind": "busy",
            "label": "Текущий фоновый процесс",
            "state": state or "idle",
            "step": str(job.get("step") or ""),
            "ok": job.get("ok") if isinstance(job.get("ok"), bool) else None,
            "message": str(job.get("message") or ""),
            "progress_index": int(p_index) if isinstance(p_index, (int, float)) else None,
            "progress_total": int(p_total) if isinstance(p_total, (int, float)) else None,
            "pid": pid or None,
            "last_run_at": None,
            "updated_at": job.get("updated_at") or now.isoformat(),
            "payload": {
                k: job.get(k)
                for k in ("resumes", "employers", "resume_id", "vacancy_id", "applied", "errors")
                if k in job
            },
        }
    )

    sched_wrap = read_json(SCHEDULE_PATH)
    payload = sched_wrap.get("payload") if isinstance(sched_wrap.get("payload"), dict) else {}
    labels = schedule_labels()
    daemon_pid = int(payload.get("pid") or 0)
    daemon_alive = pid_alive(daemon_pid)
    started_at = payload.get("started_at")
    tasks = payload.get("tasks") if isinstance(payload.get("tasks"), dict) else {}
    # last_run демона = последний тик задачи (не started_at от 10.11 — иначе путаница в Grafana).
    task_last_runs = [
        str(row.get("last_run_at") or "").strip()
        for row in tasks.values()
        if isinstance(row, dict) and str(row.get("last_run_at") or "").strip()
    ]
    daemon_last_run = max(task_last_runs) if task_last_runs else None
    rows.append(
        {
            "key": "schedule:daemon",
            "kind": "schedule_daemon",
            "label": "Планировщик",
            "state": "running" if daemon_alive else "stopped",
            "step": "daemon",
            "ok": daemon_alive,
            "message": (
                f"pid={daemon_pid}; started {started_at}"
                if daemon_pid
                else "нет pid"
            ),
            "progress_index": None,
            "progress_total": None,
            "pid": daemon_pid or None,
            "last_run_at": daemon_last_run,
            "updated_at": sched_wrap.get("saved_at") or now.isoformat(),
            "payload": {"started_at": started_at},
        }
    )

    for tid, row in tasks.items():
        if not isinstance(row, dict):
            continue
        last_ok = row.get("last_ok")
        rows.append(
            {
                "key": f"schedule:{tid}",
                "kind": "schedule_task",
                "label": labels.get(str(tid), str(tid)),
                "state": "ok" if last_ok is True else ("fail" if last_ok is False else "unknown"),
                "step": str(tid),
                "ok": last_ok if isinstance(last_ok, bool) else None,
                "message": str(row.get("last_message") or ""),
                "progress_index": None,
                "progress_total": None,
                "pid": None,
                "last_run_at": row.get("last_run_at"),
                "updated_at": sched_wrap.get("saved_at") or now.isoformat(),
                "payload": {},
            }
        )

    auto_today, tg_today = 0, 0
    try:
        auto_today, tg_today = captcha_counts_today()
    except Exception as exc:  # noqa: BLE001
        print(f"captcha counts: {exc}", file=sys.stderr, flush=True)
    balance, provider = fetch_captcha_balance()
    applied_today, applied_total, last_applied_at = applied_counts()
    rows.append(
        {
            "key": "apply:today",
            "kind": "apply",
            "label": "Отклики отправлено сегодня",
            "state": "ok",
            "step": "apply",
            "ok": True,
            "message": f"{applied_today} сегодня · всего {applied_total}",
            "progress_index": applied_today,
            "progress_total": 10 if applied_today <= 10 else applied_today,
            "pid": None,
            "last_run_at": last_applied_at,
            "updated_at": now.isoformat(),
            "payload": {
                "applied_today": applied_today,
                "applied_total": applied_total,
            },
        }
    )
    rows.append(
        {
            "key": "captcha:stats",
            "kind": "captcha",
            "label": "Капча сегодня",
            "state": "ok",
            "step": "captcha",
            "ok": True,
            "message": f"сам {auto_today} · в TG {tg_today}",
            "progress_index": auto_today,
            "progress_total": auto_today + tg_today if (auto_today + tg_today) > 0 else None,
            "pid": None,
            "last_run_at": now.isoformat(),
            "updated_at": now.isoformat(),
            "payload": {
                "auto_ok_today": auto_today,
                "tg_today": tg_today,
                "balance": balance,
                "provider": provider,
            },
        }
    )
    rows.append(
        {
            "key": "captcha:balance",
            "kind": "captcha",
            "label": f"Баланс {provider or 'капчи'}",
            "state": "ok" if balance is not None else "unknown",
            "step": "balance",
            "ok": balance is not None,
            "message": f"{balance:.4f} ₽" if balance is not None else "нет данных",
            "progress_index": None,
            "progress_total": None,
            "pid": None,
            "last_run_at": now.isoformat(),
            "updated_at": now.isoformat(),
            "payload": {"balance": balance, "provider": provider},
        }
    )

    return rows


def applied_counts() -> tuple[int, int, str | None]:
    """Реальные отклики (кнопка «Откликнуться» / apply) из FileCache — не вердикт DevAI APPLY."""
    wrap = read_json(APPLIED_PATH)
    payload = wrap.get("payload") if isinstance(wrap.get("payload"), dict) else wrap
    if not isinstance(payload, dict):
        return 0, 0, None
    # MSK calendar day as UTC date prefix YYYY-MM-DD for applied_at ISO.
    msk = ZoneInfo("Europe/Moscow")
    day = datetime.now(timezone.utc).astimezone(msk).strftime("%Y-%m-%d")
    today = 0
    last: str | None = None
    total = 0
    for row in payload.values():
        if not isinstance(row, dict):
            continue
        # Только кнопка apply — chatik (импорт чатов) не считаем откликом.
        if str(row.get("source") or "").strip() != "apply":
            continue
        total += 1
        ts = str(row.get("applied_at") or "").strip()
        if not ts:
            continue
        # applied_at хранится в UTC ISO; календарный день — Europe/Moscow.
        try:
            dt = datetime.fromisoformat(ts.replace("Z", "+00:00"))
            local_day = dt.astimezone(msk).strftime("%Y-%m-%d")
        except ValueError:
            local_day = ts[:10]
        if local_day == day:
            today += 1
            if last is None or ts > last:
                last = ts
    return today, total, last


def resume_label(resume_id: str) -> str:
    rid = (resume_id or "").strip()
    if rid in RESUME_LABELS:
        return RESUME_LABELS[rid]
    return rid[:8] if rid else "—"


def run_psql(sql: str) -> None:
    env = os.environ.copy()
    env["PGPASSWORD"] = PGPASSWORD
    proc = subprocess.run(
        [
            "psql",
            "-h",
            PGHOST,
            "-p",
            PGPORT,
            "-U",
            PGUSER,
            "-d",
            PGDATABASE,
            "-v",
            "ON_ERROR_STOP=1",
            "-c",
            sql,
        ],
        env=env,
        capture_output=True,
        text=True,
    )
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip() or proc.stdout.strip() or "psql failed")


def sync_suitable_run_stats() -> int:
    wrap = read_json(SUITABLE_STATS_PATH)
    payload = wrap.get("payload") if isinstance(wrap.get("payload"), dict) else wrap
    by_id = payload.get("by_id") if isinstance(payload, dict) else None
    if not isinstance(by_id, dict) or not by_id:
        return 0
    values: list[str] = []
    for run_id, row in by_id.items():
        if not isinstance(row, dict):
            continue
        rid = str(row.get("resume_id") or "").strip()
        values.append(
            "("
            + ", ".join(
                [
                    sql_literal(str(run_id)),
                    sql_literal(rid),
                    sql_literal(str(row.get("resume_title") or "")),
                    sql_literal(resume_label(rid)),
                    ts_literal(row.get("started_at")),
                    ts_literal(row.get("finished_at")),
                    sql_literal(str(row.get("status") or "")),
                    sql_literal(int(row.get("serp_cards") or 0)),
                    "NULL" if row.get("hh_total") is None else sql_literal(int(row.get("hh_total") or 0)),
                    sql_literal(int(row.get("in_db") or 0)),
                    sql_literal(int(row.get("skipped_known") or 0)),
                    sql_literal(int(row.get("skipped_old") or 0)),
                    sql_literal(int(row.get("ingested") or 0)),
                    sql_literal(int(row.get("created") or 0)),
                    sql_literal(int(row.get("updated") or 0)),
                    sql_literal(int(row.get("unchanged") or 0)),
                    sql_literal(int(row.get("failed") or 0)),
                    sql_literal(int(row.get("scored") or 0)),
                    "now()",
                ]
            )
            + ")"
        )
    if not values:
        return 0
    # Пачками, чтобы не раздувать один INSERT.
    run_psql(
        """
ALTER TABLE ui_suitable_run_stats
  ADD COLUMN IF NOT EXISTS hh_total integer,
  ADD COLUMN IF NOT EXISTS in_db integer NOT NULL DEFAULT 0;
"""
    )
    n = 0
    for i in range(0, len(values), 80):
        chunk = values[i : i + 80]
        sql = f"""
INSERT INTO ui_suitable_run_stats AS t (
  search_run_id, resume_id, resume_title, resume_label,
  started_at, finished_at, status,
  serp_cards, hh_total, in_db, skipped_known, skipped_old, ingested,
  created_count, updated_count, unchanged_count, failed, scored, synced_at
) VALUES
{',\n'.join(chunk)}
ON CONFLICT (search_run_id) DO UPDATE SET
  resume_id = EXCLUDED.resume_id,
  resume_title = EXCLUDED.resume_title,
  resume_label = EXCLUDED.resume_label,
  started_at = EXCLUDED.started_at,
  finished_at = EXCLUDED.finished_at,
  status = EXCLUDED.status,
  serp_cards = EXCLUDED.serp_cards,
  hh_total = EXCLUDED.hh_total,
  in_db = EXCLUDED.in_db,
  skipped_known = EXCLUDED.skipped_known,
  skipped_old = EXCLUDED.skipped_old,
  ingested = EXCLUDED.ingested,
  created_count = EXCLUDED.created_count,
  updated_count = EXCLUDED.updated_count,
  unchanged_count = EXCLUDED.unchanged_count,
  failed = EXCLUDED.failed,
  scored = EXCLUDED.scored,
  synced_at = EXCLUDED.synced_at;
"""
        run_psql(sql)
        n += len(chunk)
    return n


def sync_applied_events() -> int:
    wrap = read_json(APPLIED_PATH)
    payload = wrap.get("payload") if isinstance(wrap.get("payload"), dict) else wrap
    if not isinstance(payload, dict) or not payload:
        return 0
    values: list[str] = []
    for vac_id, row in payload.items():
        if not isinstance(row, dict):
            continue
        ts = str(row.get("applied_at") or "").strip()
        if not ts:
            continue
        rid = str(row.get("resume_external_id") or row.get("resume_id") or "").strip()
        values.append(
            "("
            + ", ".join(
                [
                    sql_literal(str(vac_id)),
                    sql_literal(rid),
                    sql_literal(str(row.get("resume_title") or "")),
                    sql_literal(resume_label(rid)),
                    ts_literal(ts) + "::timestamptz",
                    sql_literal(str(row.get("source") or "")),
                    "now()",
                ]
            )
            + ")"
        )
    if not values:
        return 0
    n = 0
    for i in range(0, len(values), 100):
        chunk = values[i : i + 100]
        sql = f"""
INSERT INTO ui_applied_events AS t (
  vacancy_external_id, resume_id, resume_title, resume_label,
  applied_at, source, synced_at
) VALUES
{',\n'.join(chunk)}
ON CONFLICT (vacancy_external_id) DO UPDATE SET
  resume_id = EXCLUDED.resume_id,
  resume_title = EXCLUDED.resume_title,
  resume_label = EXCLUDED.resume_label,
  applied_at = EXCLUDED.applied_at,
  source = EXCLUDED.source,
  synced_at = EXCLUDED.synced_at;
"""
        run_psql(sql)
        n += len(chunk)
    return n


def live_resume_ids() -> set[str]:
    """Актуальные resume_id с HH (кеш resumes); fallback — RESUME_LABELS."""
    titles: set[str] = set()
    wrap = read_json(RESUMES_PATH)
    payload = wrap.get("payload") if isinstance(wrap.get("payload"), dict) else {}
    items = payload.get("items") if isinstance(payload, dict) else []
    if isinstance(items, list):
        for item in items:
            if not isinstance(item, dict):
                continue
            rid = str(item.get("external_id") or "").strip()
            if rid:
                titles.add(rid)
    if titles:
        return titles
    return set(RESUME_LABELS)


def ensure_rejection_events_table() -> None:
    run_psql(
        """
CREATE TABLE IF NOT EXISTS ui_rejection_events (
  vacancy_external_id text PRIMARY KEY,
  chat_id text NOT NULL DEFAULT '',
  resume_id text NOT NULL DEFAULT '',
  resume_label text NOT NULL DEFAULT '',
  resume_title text NOT NULL DEFAULT '',
  company_name text NOT NULL DEFAULT '',
  rejected_at timestamptz NOT NULL,
  synced_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ui_rejection_events_rejected_at_idx
  ON ui_rejection_events (rejected_at DESC);
CREATE INDEX IF NOT EXISTS ui_rejection_events_resume_idx
  ON ui_rejection_events (resume_id, rejected_at DESC);
"""
    )


def sync_rejection_events() -> int:
    """Архив chat_rejections → Postgres. Без даты и мёртвые резюме пропускаем."""
    ensure_rejection_events_table()
    wrap = read_json(REJECTIONS_PATH)
    payload = wrap.get("payload") if isinstance(wrap.get("payload"), dict) else wrap
    items = payload.get("items") if isinstance(payload, dict) else None
    if not isinstance(items, list) or not items:
        return 0
    known = live_resume_ids()
    values: list[str] = []
    for row in items:
        if not isinstance(row, dict):
            continue
        vac = str(row.get("vacancy_external_id") or "").strip()
        if vac == "":
            continue
        ts = str(row.get("last_message_at") or row.get("last_activity_at") or "").strip()
        if not ts:
            continue
        rid = str(row.get("resume_external_id") or row.get("resume_id") or "").strip()
        if rid == "" or rid not in known:
            continue
        values.append(
            "("
            + ", ".join(
                [
                    sql_literal(vac),
                    sql_literal(str(row.get("chat_id") or "")),
                    sql_literal(rid),
                    sql_literal(resume_label(rid)),
                    sql_literal(str(row.get("resume_title") or "")),
                    sql_literal(str(row.get("company_name") or "")[:500]),
                    ts_literal(ts) + "::timestamptz",
                    "now()",
                ]
            )
            + ")"
        )
    if not values:
        return 0
    n = 0
    for i in range(0, len(values), 100):
        chunk = values[i : i + 100]
        sql = f"""
INSERT INTO ui_rejection_events AS t (
  vacancy_external_id, chat_id, resume_id, resume_label, resume_title,
  company_name, rejected_at, synced_at
) VALUES
{',\n'.join(chunk)}
ON CONFLICT (vacancy_external_id) DO UPDATE SET
  chat_id = EXCLUDED.chat_id,
  resume_id = EXCLUDED.resume_id,
  resume_label = EXCLUDED.resume_label,
  resume_title = EXCLUDED.resume_title,
  company_name = EXCLUDED.company_name,
  rejected_at = EXCLUDED.rejected_at,
  synced_at = EXCLUDED.synced_at;
"""
        run_psql(sql)
        n += len(chunk)
    # Убрать отказы по удалённым резюме (SM/HIT и т.п.).
    keep = ", ".join(sql_literal(rid) for rid in sorted(known))
    if keep:
        run_psql(f"DELETE FROM ui_rejection_events WHERE resume_id NOT IN ({keep});")
    return n


def ensure_apply_fail_table() -> None:
    run_psql(
        """
CREATE TABLE IF NOT EXISTS ui_apply_fail_events (
  id text PRIMARY KEY,
  vacancy_external_id text NOT NULL,
  resume_id text NOT NULL DEFAULT '',
  resume_label text NOT NULL DEFAULT '',
  kind text NOT NULL,
  message text NOT NULL DEFAULT '',
  failed_at timestamptz NOT NULL,
  synced_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ui_apply_fail_events_failed_at_idx
  ON ui_apply_fail_events (failed_at);
"""
    )


def sync_apply_fail_events() -> int:
    """Сбои/скип TG из app_errors — чтобы на графике откликов было видно «15 попыток → 14+1»."""
    ensure_apply_fail_table()
    wrap = read_json(APP_ERRORS_PATH)
    payload = wrap.get("payload") if isinstance(wrap.get("payload"), dict) else wrap
    items = payload.get("items") if isinstance(payload, dict) else None
    if not isinstance(items, list) or not items:
        return 0
    values: list[str] = []
    for row in items:
        if not isinstance(row, dict):
            continue
        eid = str(row.get("id") or "").strip()
        vac = str(row.get("vacancy_id") or "").strip()
        ts = str(row.get("at") or "").strip()
        msg = str(row.get("message") or "").strip()
        if eid == "" or vac == "" or ts == "" or msg == "":
            continue
        # Только apply-поток (не прочие UI-ошибки).
        src = str(row.get("source") or "").strip().lower()
        if src not in ("", "apply", "apply_consider", "apply_errors"):
            continue
        rid = str(row.get("resume_id") or "").strip()
        skipped = str(row.get("skipped_at") or "").strip()
        kind = "скип TG" if skipped else "не ушло"
        values.append(
            "("
            + ", ".join(
                [
                    sql_literal(eid),
                    sql_literal(vac),
                    sql_literal(rid),
                    sql_literal(resume_label(rid)),
                    sql_literal(kind),
                    sql_literal(msg[:300]),
                    ts_literal(ts) + "::timestamptz",
                    "now()",
                ]
            )
            + ")"
        )
    if not values:
        return 0
    n = 0
    for i in range(0, len(values), 100):
        chunk = values[i : i + 100]
        sql = f"""
INSERT INTO ui_apply_fail_events AS t (
  id, vacancy_external_id, resume_id, resume_label, kind, message, failed_at, synced_at
) VALUES
{',\n'.join(chunk)}
ON CONFLICT (id) DO UPDATE SET
  vacancy_external_id = EXCLUDED.vacancy_external_id,
  resume_id = EXCLUDED.resume_id,
  resume_label = EXCLUDED.resume_label,
  kind = EXCLUDED.kind,
  message = EXCLUDED.message,
  failed_at = EXCLUDED.failed_at,
  synced_at = EXCLUDED.synced_at;
"""
        run_psql(sql)
        n += len(chunk)
    return n


def sync_vacancy_owner() -> int:
    wrap = read_json(OWNER_PATH)
    payload = wrap.get("payload") if isinstance(wrap.get("payload"), dict) else wrap
    if not isinstance(payload, dict) or not payload:
        return 0
    values: list[str] = []
    for vac_id, row in payload.items():
        if not isinstance(row, dict):
            continue
        rid = str(row.get("resume_id") or "").strip()
        if rid == "":
            continue
        values.append(
            "("
            + ", ".join(
                [
                    sql_literal(str(vac_id)),
                    sql_literal(rid),
                    sql_literal(resume_label(rid)),
                    sql_literal(str(row.get("resume_title") or "")),
                    ts_literal(row.get("decided_at")),
                    "now()",
                ]
            )
            + ")"
        )
    if not values:
        return 0
    n = 0
    for i in range(0, len(values), 150):
        chunk = values[i : i + 150]
        sql = f"""
INSERT INTO ui_vacancy_owner AS t (
  vacancy_external_id, resume_id, resume_label, resume_title, decided_at, synced_at
) VALUES
{',\n'.join(chunk)}
ON CONFLICT (vacancy_external_id) DO UPDATE SET
  resume_id = EXCLUDED.resume_id,
  resume_label = EXCLUDED.resume_label,
  resume_title = EXCLUDED.resume_title,
  decided_at = EXCLUDED.decided_at,
  synced_at = EXCLUDED.synced_at;
"""
        run_psql(sql)
        n += len(chunk)
    return n


def sync_resume_stats() -> int:
    """Снимок по резюме: owner + отклики сегодня/всего (skip/consider — в Grafana из assessments)."""
    msk = ZoneInfo("Europe/Moscow")
    day = datetime.now(timezone.utc).astimezone(msk).strftime("%Y-%m-%d")

    titles: dict[str, str] = {}
    resumes_wrap = read_json(RESUMES_PATH)
    rpayload = resumes_wrap.get("payload") if isinstance(resumes_wrap.get("payload"), dict) else {}
    items = rpayload.get("items") if isinstance(rpayload, dict) else []
    if isinstance(items, list):
        for item in items:
            if not isinstance(item, dict):
                continue
            rid = str(item.get("external_id") or "").strip()
            if rid:
                titles[rid] = str(item.get("title") or rid)

    owned: dict[str, int] = {}
    shared: dict[str, int] = {}
    owner_wrap = read_json(OWNER_PATH)
    opayload = owner_wrap.get("payload") if isinstance(owner_wrap.get("payload"), dict) else owner_wrap
    if isinstance(opayload, dict):
        for row in opayload.values():
            if not isinstance(row, dict):
                continue
            rid = str(row.get("resume_id") or "").strip()
            if rid == "":
                continue
            owned[rid] = owned.get(rid, 0) + 1
            peers = row.get("suitable_peers") or row.get("candidates") or []
            if isinstance(peers, list) and len([p for p in peers if str(p).strip()]) > 1:
                shared[rid] = shared.get(rid, 0) + 1

    applied_today: dict[str, int] = {}
    applied_total: dict[str, int] = {}
    applied_wrap = read_json(APPLIED_PATH)
    apayload = applied_wrap.get("payload") if isinstance(applied_wrap.get("payload"), dict) else applied_wrap
    if isinstance(apayload, dict):
        for row in apayload.values():
            if not isinstance(row, dict):
                continue
            rid = str(row.get("resume_external_id") or row.get("resume_id") or "").strip()
            if rid == "":
                continue
            if str(row.get("source") or "").strip() != "apply":
                continue
            applied_total[rid] = applied_total.get(rid, 0) + 1
            ts = str(row.get("applied_at") or "").strip()
            if not ts:
                continue
            try:
                dt = datetime.fromisoformat(ts.replace("Z", "+00:00"))
                local_day = dt.astimezone(msk).strftime("%Y-%m-%d")
            except ValueError:
                local_day = ts[:10]
            if local_day == day:
                applied_today[rid] = applied_today.get(rid, 0) + 1

    # Только актуальные резюме с HH (кеш resumes) — без SM/HIT и прочих мёртвых id.
    resume_ids = sorted(set(titles))
    if not resume_ids:
        resume_ids = sorted(RESUME_LABELS)
    if not resume_ids:
        return 0
    values: list[str] = []
    for rid in resume_ids:
        o = owned.get(rid, 0)
        sh = shared.get(rid, 0)
        values.append(
            "("
            + ", ".join(
                [
                    sql_literal(rid),
                    sql_literal(titles.get(rid) or rid),
                    sql_literal(resume_label(rid)),
                    sql_literal(o),
                    sql_literal(sh),
                    sql_literal(max(0, o - sh)),
                    sql_literal(0),
                    sql_literal(0),
                    sql_literal(0),
                    sql_literal(applied_today.get(rid, 0)),
                    sql_literal(applied_total.get(rid, 0)),
                    sql_literal(10),
                    "now()",
                ]
            )
            + ")"
        )
    sql = f"""
INSERT INTO ui_resume_stats AS t (
  resume_id, resume_title, resume_label,
  owned, shared, sole, skip_count, consider_count, unscored_count,
  applied_today, applied_total, goal, synced_at
) VALUES
{',\n'.join(values)}
ON CONFLICT (resume_id) DO UPDATE SET
  resume_title = EXCLUDED.resume_title,
  resume_label = EXCLUDED.resume_label,
  owned = EXCLUDED.owned,
  shared = EXCLUDED.shared,
  sole = EXCLUDED.sole,
  applied_today = EXCLUDED.applied_today,
  applied_total = EXCLUDED.applied_total,
  goal = EXCLUDED.goal,
  synced_at = EXCLUDED.synced_at;
"""
    run_psql(sql)
    keep = ", ".join(sql_literal(rid) for rid in resume_ids)
    run_psql(f"DELETE FROM ui_resume_stats WHERE resume_id NOT IN ({keep});")
    return len(values)


_STATS_EVERY_SEC = 30.0
_last_stats_at = 0.0


def sync_once() -> int:
    global _last_stats_at
    rows = build_rows()
    upsert_rows(rows)
    now = time.time()
    # Тяжёлые таблицы статистики — раз в 30 с (live job остаётся каждые 2 с).
    if now - _last_stats_at >= _STATS_EVERY_SEC:
        sync_suitable_run_stats()
        sync_applied_events()
        sync_apply_fail_events()
        sync_rejection_events()
        sync_vacancy_owner()
        sync_resume_stats()
        _last_stats_at = now
    return len(rows)


def main(argv: list[str]) -> int:
    loop = "--loop" in argv
    interval = 2.0
    force_stats = "--stats" in argv
    for arg in argv:
        if arg.startswith("--interval="):
            interval = float(arg.split("=", 1)[1])
    if force_stats:
        global _last_stats_at
        _last_stats_at = 0.0
    if not loop:
        if force_stats:
            _last_stats_at = 0.0
        n = sync_once()
        print(f"synced {n} live rows (+stats)")
        return 0
    print(
        f"live sync loop every {interval}s → {PGHOST}/{PGDATABASE}"
        f" (stats every {_STATS_EVERY_SEC:.0f}s)",
        flush=True,
    )
    while True:
        try:
            sync_once()
        except Exception as exc:  # noqa: BLE001 — daemon must keep running
            print(f"sync error: {exc}", file=sys.stderr, flush=True)
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
