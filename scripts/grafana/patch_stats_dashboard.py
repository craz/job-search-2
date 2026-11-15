#!/usr/bin/env python3
"""Добавляет row «Статистика» на дашборд job-search-2 (Postgres DS)."""

from __future__ import annotations

import json
import urllib.request

GRAFANA = "http://127.0.0.1:3000"
AUTH = ("admin", "admin")
DASH_UID = "job-search-2"
DS = {"type": "grafana-postgresql-datasource", "uid": "bg49606iro7pcd"}


def api(method: str, path: str, body: dict | None = None) -> dict:
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(
        GRAFANA + path,
        data=data,
        method=method,
        headers={"Content-Type": "application/json", "Accept": "application/json"},
    )
    import base64

    token = base64.b64encode(f"{AUTH[0]}:{AUTH[1]}".encode()).decode()
    req.add_header("Authorization", f"Basic {token}")
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode())


def target(sql: str, ref_id: str = "A", format_as: str = "time_series") -> dict:
    return {
        "datasource": DS,
        "editorMode": "code",
        "format": format_as,
        "rawQuery": True,
        "rawSql": sql,
        "refId": ref_id,
    }


def timeseries_panel(pid: int, title: str, sql: str, y: int, h: int = 9, w: int = 12, x: int = 0) -> dict:
    return {
        "id": pid,
        "type": "timeseries",
        "title": title,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "datasource": DS,
        "targets": [target(sql)],
        "fieldConfig": {
            "defaults": {
                "custom": {
                    "drawStyle": "line",
                    "lineInterpolation": "smooth",
                    "fillOpacity": 15,
                    "showPoints": "never",
                    "spanNulls": True,
                },
                "unit": "short",
            },
            "overrides": [],
        },
        "options": {
            "legend": {"displayMode": "list", "placement": "bottom", "showLegend": True},
            "tooltip": {"mode": "multi", "sort": "desc"},
        },
    }


def table_panel(pid: int, title: str, sql: str, y: int, h: int = 8, w: int = 12, x: int = 0) -> dict:
    return {
        "id": pid,
        "type": "table",
        "title": title,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "datasource": DS,
        "targets": [target(sql, format_as="table")],
        "options": {"showHeader": True, "cellHeight": "sm"},
        "fieldConfig": {"defaults": {}, "overrides": []},
    }


def stat_panel(pid: int, title: str, sql: str, y: int, w: int = 4, x: int = 0) -> dict:
    return {
        "id": pid,
        "type": "stat",
        "title": title,
        "gridPos": {"h": 4, "w": w, "x": x, "y": y},
        "datasource": DS,
        "targets": [target(sql, format_as="table")],
        "options": {
            "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": False},
            "colorMode": "value",
            "graphMode": "none",
            "textMode": "auto",
        },
        "fieldConfig": {
            "defaults": {"color": {"mode": "thresholds"}, "thresholds": {"mode": "absolute", "steps": [{"color": "blue", "value": None}]}},
            "overrides": [],
        },
    }


def main() -> int:
    raw = api("GET", f"/api/dashboards/uid/{DASH_UID}")
    dash = raw["dashboard"]
    panels = list(dash.get("panels") or [])

    # Убрать предыдущий row Статистика (идемпотентно).
    panels = [
        p
        for p in panels
        if not (
            (p.get("type") == "row" and p.get("id") == 300)
            or (isinstance(p.get("id"), int) and 301 <= int(p["id"]) <= 320)
        )
    ]

    # Сдвинуть остальные панели вниз под новый row (~34).
    for p in panels:
        gp = p.get("gridPos") or {}
        if "y" in gp:
            gp["y"] = int(gp["y"]) + 34
            p["gridPos"] = gp

    y0 = 0
    new_panels: list[dict] = [
        {
            "id": 300,
            "type": "row",
            "title": "Статистика",
            "gridPos": {"h": 1, "w": 24, "x": 0, "y": y0},
            "collapsed": False,
            "panels": [],
        },
        stat_panel(
            301,
            "Отклики сегодня (все резюме)",
            "SELECT COALESCE(SUM(applied_today),0)::int AS value FROM ui_resume_stats",
            y0 + 1,
            w=4,
            x=0,
        ),
        stat_panel(
            302,
            "Цель × резюме (10)",
            "SELECT (COUNT(*) FILTER (WHERE resume_label IN ('TPM','PM','PHP')) * 10)::int AS value FROM ui_resume_stats",
            y0 + 1,
            w=4,
            x=4,
        ),
        # Не суммируем serp_cards за неделю — на каждом тике одни и те же карточки.
        stat_panel(
            303,
            "Прогонов suitable (7 дн.)",
            """SELECT count(*)::int AS value
FROM ui_suitable_run_stats
WHERE finished_at >= now() - interval '7 days'""",
            y0 + 1,
            w=4,
            x=8,
        ),
        stat_panel(
            304,
            "Скачано новых (7 дн.)",
            """SELECT COALESCE(SUM(ingested),0)::int AS value
FROM ui_suitable_run_stats
WHERE finished_at >= now() - interval '7 days'""",
            y0 + 1,
            w=4,
            x=12,
        ),
        stat_panel(
            305,
            "Среднее SERP за прогон",
            """SELECT COALESCE(ROUND(AVG(serp_cards)::numeric, 0),0)::int AS value
FROM ui_suitable_run_stats
WHERE finished_at >= now() - interval '7 days'""",
            y0 + 1,
            w=4,
            x=16,
        ),
        stat_panel(
            306,
            "SKIP DevAI (7 дн.)",
            """SELECT count(*)::int AS value
FROM assessments a
WHERE lower(a.verdict) = 'skip'
  AND a.assessed_at >= now() - interval '7 days'""",
            y0 + 1,
            w=4,
            x=20,
        ),
        timeseries_panel(
            310,
            "Скачивание suitable: SERP / уже были / скачано",
            """SELECT
  date_trunc('hour', finished_at) AS time,
  metric,
  sum(value)::int AS value
FROM (
  SELECT finished_at, 'SERP'::text AS metric, serp_cards AS value FROM ui_suitable_run_stats
  WHERE $__timeFilter(finished_at)
  UNION ALL
  SELECT finished_at, 'уже были', skipped_known FROM ui_suitable_run_stats
  WHERE $__timeFilter(finished_at)
  UNION ALL
  SELECT finished_at, 'скачано', ingested FROM ui_suitable_run_stats
  WHERE $__timeFilter(finished_at)
) s
GROUP BY 1, 2
ORDER BY 1""",
            y0 + 5,
            h=9,
            w=12,
            x=0,
        ),
        timeseries_panel(
            311,
            "Скачано (ingest) по резюме",
            """SELECT
  date_trunc('hour', finished_at) AS time,
  COALESCE(NULLIF(resume_label,''), left(resume_id,8)) AS metric,
  sum(ingested)::int AS value
FROM ui_suitable_run_stats
WHERE $__timeFilter(finished_at)
GROUP BY 1, 2
ORDER BY 1""",
            y0 + 5,
            h=9,
            w=12,
            x=12,
        ),
        timeseries_panel(
            312,
            "Отклики (apply) по резюме",
            """SELECT
  date_trunc('day', applied_at AT TIME ZONE 'Europe/Moscow') AS time,
  COALESCE(NULLIF(resume_label,''), left(resume_id,8)) AS metric,
  count(*)::int AS value
FROM ui_applied_events
WHERE $__timeFilter(applied_at)
GROUP BY 1, 2
ORDER BY 1""",
            y0 + 14,
            h=9,
            w=12,
            x=0,
        ),
        timeseries_panel(
            313,
            "Оценки DevAI по резюме (SKIP / APPLY / MAYBE)",
            """SELECT
  date_trunc('day', a.assessed_at AT TIME ZONE 'Europe/Moscow') AS time,
  (COALESCE(NULLIF(o.resume_label,''), 'без owner') || ': ' ||
    CASE lower(a.verdict)
      WHEN 'apply' THEN 'apply'
      WHEN 'maybe' THEN 'maybe'
      WHEN 'skip' THEN 'skip'
      ELSE lower(a.verdict)
    END) AS metric,
  count(*)::int AS value
FROM assessments a
JOIN vacancies v ON v.id = a.vacancy_id
LEFT JOIN ui_vacancy_owner o ON o.vacancy_external_id = v.external_id
WHERE $__timeFilter(a.assessed_at)
  AND lower(a.verdict) IN ('apply','maybe','skip')
GROUP BY 1, 2
ORDER BY 1""",
            y0 + 14,
            h=9,
            w=12,
            x=12,
        ),
        table_panel(
            314,
            "Сейчас по резюме (отклики / owner)",
            """SELECT
  resume_label AS резюме,
  left(resume_title, 48) AS название,
  applied_today AS \"отклики сегодня\",
  goal AS цель,
  applied_total AS \"отклики всего\",
  owned AS owner,
  shared AS shared,
  sole AS sole
FROM ui_resume_stats
WHERE resume_label IN ('TPM','PM','PHP')
   OR applied_total > 0 OR owned > 0
ORDER BY resume_label""",
            y0 + 23,
            h=7,
            w=12,
            x=0,
        ),
        table_panel(
            315,
            "Последние suitable-прогоны",
            """SELECT
  to_char(finished_at AT TIME ZONE 'Europe/Moscow', 'DD.MM HH24:MI') AS когда,
  resume_label AS резюме,
  status,
  serp_cards AS serp,
  skipped_known AS \"уже были\",
  skipped_old AS старые,
  ingested AS скачано,
  scored AS оценено,
  failed AS сбой
FROM ui_suitable_run_stats
ORDER BY finished_at DESC NULLS LAST
LIMIT 40""",
            y0 + 23,
            h=7,
            w=12,
            x=12,
        ),
        table_panel(
            316,
            "История daily_metrics",
            """SELECT
  to_char(metric_date, 'DD.MM.YYYY') AS день,
  applications AS отклики,
  views_total AS просмотры,
  replies AS ответы,
  invitations AS приглашения,
  rejections AS отказы,
  left(coalesce(notes,''), 80) AS заметки
FROM daily_metrics
ORDER BY metric_date DESC
LIMIT 21""",
            y0 + 30,
            h=7,
            w=24,
            x=0,
        ),
    ]

    dash["panels"] = new_panels + panels
    dash["version"] = raw["dashboard"].get("version")
    dash["time"] = dash.get("time") or {"from": "now-7d", "to": "now"}
    dash["timezone"] = "Europe/Moscow"
    dash["tags"] = sorted(set((dash.get("tags") or []) + ["статистика", "hh"]))

    result = api(
        "POST",
        "/api/dashboards/db",
        {
            "dashboard": dash,
            "folderUid": raw.get("meta", {}).get("folderUid") or None,
            "message": "add Статистика row (suitable/apply/resume time series)",
            "overwrite": True,
        },
    )
    print(json.dumps({"status": result.get("status"), "uid": result.get("uid"), "url": result.get("url"), "version": result.get("version")}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
