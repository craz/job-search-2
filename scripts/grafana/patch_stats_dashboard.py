#!/usr/bin/env python3
"""Патч row «Статистика» на дашборд job-search-2 (Postgres DS).

По умолчанию (--prune): только убрать REMOVED_PANEL_IDS — без пересборки SQL/layout.
--sync-queries: точечный merge targets/title/fieldConfig по id (time/refresh/gridPos
пользователя не трогаем, кроме явного w=24 один раз через --widen).
Bootstrap — только если row id=300 ещё нет.
"""

from __future__ import annotations

import json
import sys
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


def stat_panel(pid: int, title: str, sql: str, y: int, w: int = 4, x: int = 0, h: int = 4) -> dict:
    return {
        "id": pid,
        "type": "stat",
        "title": title,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
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


def text_panel(pid: int, title: str, markdown: str, y: int, h: int = 5, w: int = 24, x: int = 0) -> dict:
    return {
        "id": pid,
        "type": "text",
        "title": title,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "options": {
            "mode": "markdown",
            "code": {"language": "markdown", "showLineNumbers": False, "showMiniMap": False},
            "content": markdown.strip() + "\n",
        },
        "transparent": False,
    }


# Высота блока «Статистика» (для сдвига остальных row; последний text ~y=76 h=4).
STATS_BLOCK_H = 82


def _rejection_rate_panel(y0: int) -> dict:
    """Недельная доля отказов/откликов × резюме (главная метрика эффекта правок)."""
    panel = barchart_panel(
        324,
        "Отказы / отклики % по неделям × резюме",
        """WITH weeks AS (
  SELECT generate_series(
    date_trunc('week', $__timeFrom()::timestamptz AT TIME ZONE 'Europe/Moscow'),
    date_trunc('week', $__timeTo()::timestamptz AT TIME ZONE 'Europe/Moscow'),
    interval '1 week'
  ) AS week
),
labels AS (
  SELECT unnest(ARRAY['PHP', 'PM', 'TPM']) AS resume_label
),
grid AS (
  SELECT w.week, l.resume_label FROM weeks w CROSS JOIN labels l
),
applies AS (
  SELECT
    date_trunc('week', applied_at AT TIME ZONE 'Europe/Moscow') AS week,
    resume_label,
    count(*)::int AS n
  FROM ui_applied_events
  WHERE $__timeFilter(applied_at)
    AND resume_label IN ('TPM', 'PM', 'PHP')
    AND source = 'apply'
  GROUP BY 1, 2
),
rejections AS (
  SELECT
    date_trunc('week', rejected_at AT TIME ZONE 'Europe/Moscow') AS week,
    resume_label,
    count(*)::int AS n
  FROM ui_rejection_events
  WHERE $__timeFilter(rejected_at)
    AND resume_label IN ('TPM', 'PM', 'PHP')
  GROUP BY 1, 2
)
SELECT
  g.week AS time,
  g.resume_label AS metric,
  CASE
    WHEN coalesce(a.n, 0) = 0 THEN NULL
    ELSE round(100.0 * coalesce(r.n, 0) / a.n, 1)
  END AS value
FROM grid g
LEFT JOIN applies a ON a.week = g.week AND a.resume_label = g.resume_label
LEFT JOIN rejections r ON r.week = g.week AND r.resume_label = g.resume_label
ORDER BY 1, 2""",
        y0 + 33,
        h=9,
        w=24,
        x=0,
    )
    panel["description"] = (
        "Отказы / отклики за календарную неделю × живые резюме. "
        "Дата отказа ≈ сообщение в чате HH (лаг 1–14 дн.). "
        "Без даты в архиве и старые резюме не входят. "
        "Смотри % после правки резюме при стабильных откликах."
    )
    panel["fieldConfig"]["defaults"]["unit"] = "percent"
    panel["fieldConfig"]["defaults"]["decimals"] = 1
    panel["fieldConfig"]["defaults"]["min"] = 0
    return panel


def barchart_panel(
    pid: int,
    title: str,
    sql: str,
    y: int,
    h: int = 8,
    w: int = 12,
    x: int = 0,
    *,
    stacking: str = "none",
    plan_line: int | None = None,
) -> dict:
    """Столбцы по времени (день/час) — удобно читать «за 14.11 столько»."""
    custom: dict = {
        "lineWidth": 1,
        "fillOpacity": 80,
        "gradientMode": "none",
        "axisPlacement": "auto",
    }
    thresholds: dict | None = None
    if plan_line is not None:
        # Горизонталь «план» (для стека — по сумме столбца).
        custom["thresholdsStyle"] = {"mode": "line"}
        thresholds = {
            "mode": "absolute",
            "steps": [
                {"color": "transparent", "value": None},
                {"color": "orange", "value": plan_line},
            ],
        }
    defaults: dict = {
        "color": {"mode": "palette-classic"},
        "unit": "none",
        "decimals": 0,
        "custom": custom,
    }
    if thresholds is not None:
        defaults["thresholds"] = thresholds
    return {
        "id": pid,
        "type": "barchart",
        "title": title,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "datasource": DS,
        "targets": [target(sql)],
        "fieldConfig": {
            "defaults": defaults,
            "overrides": [],
        },
        "options": {
            "orientation": "vertical",
            "xTickLabelRotation": 0,
            "showValue": "always",
            "stacking": stacking,
            "groupWidth": 0.7,
            "barWidth": 0.9,
            "legend": {"displayMode": "list", "placement": "bottom", "showLegend": True},
            "tooltip": {"mode": "multi", "sort": "desc"},
        },
    }


def build_stats_panels(y0: int = 0) -> list[dict]:
    """Каноничные панели row «Статистика» (bootstrap / источник SQL)."""
    return [
        {
            "id": 300,
            "type": "row",
            "title": "Статистика",
            "gridPos": {"h": 1, "w": 24, "x": 0, "y": y0},
            "collapsed": False,
            "panels": [],
        },
        # Крупно «0/30»; дата — отдельная панель сразу под ним (Stat name снизу на wide не умеет).
        {
            "id": 301,
            "type": "stat",
            "title": "Отклики / норма сегодня",
            "gridPos": {"h": 3, "w": 8, "x": 0, "y": y0 + 1},
            "datasource": DS,
            "targets": [
                target(
                    """
SELECT
  (COALESCE(SUM(applied_today), 0)::text
    || '/'
    || (COUNT(*) FILTER (WHERE resume_label IN ('TPM','PM','PHP')) * 10)::text)
    AS progress
FROM ui_resume_stats
""".strip(),
                    format_as="table",
                )
            ],
            "options": {
                "reduceOptions": {
                    "calcs": [],
                    "fields": "/.*/",
                    "values": True,
                },
                "orientation": "auto",
                "textMode": "value",
                "colorMode": "none",
                "graphMode": "none",
                "justifyMode": "center",
                "text": {"valueSize": 42},
            },
            "fieldConfig": {
                "defaults": {"color": {"mode": "fixed", "fixedColor": "blue"}},
                "overrides": [],
            },
        },
        {
            "id": 302,
            "type": "stat",
            "title": "",
            "gridPos": {"h": 2, "w": 8, "x": 0, "y": y0 + 4},
            "datasource": DS,
            "transparent": True,
            "targets": [
                target(
                    """
SELECT to_char(
  (now() AT TIME ZONE 'Europe/Moscow')::date,
  'DD.MM.YYYY'
) AS day_label
""".strip(),
                    format_as="table",
                )
            ],
            "options": {
                "reduceOptions": {
                    "calcs": [],
                    "fields": "/.*/",
                    "values": True,
                },
                "orientation": "auto",
                "textMode": "value",
                "colorMode": "none",
                "graphMode": "none",
                "justifyMode": "center",
                "text": {"valueSize": 14},
            },
            "fieldConfig": {
                "defaults": {
                    "color": {"mode": "fixed", "fixedColor": "#9aa5b5"},
                },
                "overrides": [],
            },
        },
        # Верхний ряд: отклики | новых | N/M. SKIP только в воронке.
        stat_panel(
            304,
            "Новых вакансий (7 дн.)",
            """SELECT count(*)::int AS value
FROM vacancies v
WHERE v.created_at >= now() - interval '7 days'
  AND NOT EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id)""",
            y0 + 1,
            w=8,
            x=8,
            h=5,
        ),
        # N/M столбиком: PHP / PM / TPM — по строке.
        {
            "id": 305,
            "type": "table",
            "title": "В базе / на HH",
            "gridPos": {"h": 5, "w": 8, "x": 16, "y": y0 + 1},
            "datasource": DS,
            "targets": [
                target(
                    """
SELECT
  resume_label AS resume,
  in_db::text || '/' || hh_total::text AS coverage
FROM (
  SELECT DISTINCT ON (resume_label)
    resume_label,
    in_db,
    hh_total
  FROM ui_suitable_run_stats
  WHERE resume_label IN ('TPM', 'PM', 'PHP')
    AND hh_total IS NOT NULL
    AND hh_total > 0
  ORDER BY resume_label, finished_at DESC
) last_run
ORDER BY CASE resume_label
  WHEN 'PHP' THEN 1
  WHEN 'PM' THEN 2
  ELSE 3
END
""".strip(),
                    format_as="table",
                )
            ],
            "options": {
                "showHeader": False,
                "cellHeight": "md",
                "footer": {"show": False},
            },
            "fieldConfig": {
                "defaults": {
                    "custom": {
                        "align": "center",
                        "filterable": False,
                        "inspect": False,
                    },
                    "color": {"mode": "fixed", "fixedColor": "text"},
                },
                "overrides": [
                    {
                        "matcher": {"id": "byName", "options": "resume"},
                        "properties": [
                            {"id": "custom.width", "value": 72},
                            {
                                "id": "custom.align",
                                "value": "right",
                            },
                        ],
                    },
                    {
                        "matcher": {"id": "byName", "options": "coverage"},
                        "properties": [
                            {
                                "id": "custom.align",
                                "value": "left",
                            },
                            {
                                "id": "color",
                                "value": {"mode": "fixed", "fixedColor": "blue"},
                            },
                        ],
                    },
                ],
            },
        },
        # Одна когорта = вакансии, появившиеся в Core за 7 дней.
        # bargauge + wide fields: красный override на поле с «⚠».
        {
            "id": 309,
            "type": "bargauge",
            "title": "Воронка 7 дн.: новые → оценка → skip → смотрим → отклик",
            "gridPos": {"h": 9, "w": 24, "x": 0, "y": y0 + 6},
            "datasource": DS,
            # По одному полю на query; порядок targets = порядок полос после merge
            # (пустой XOR-кадр не даёт null-столбец).
            "targets": [
                {
                    **target(
                        """
SELECT count(*)::int AS "1. Новые в Core"
FROM vacancies v
WHERE v.created_at >= now() - interval '7 days'
  AND NOT EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id)
""".strip(),
                        format_as="table",
                    ),
                    "refId": "A",
                },
                {
                    **target(
                        """
WITH new_v AS (
  SELECT v.id FROM vacancies v
  WHERE v.created_at >= now() - interval '7 days'
    AND NOT EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id)
),
assessed_n AS (
  SELECT count(DISTINCT a.vacancy_id)::int AS n
  FROM assessments a JOIN new_v n ON n.id = a.vacancy_id
),
new_n AS (SELECT count(*)::int AS n FROM new_v)
SELECT a.n AS "2. Оценены ⚠ меньше Core"
FROM assessed_n a, new_n n
WHERE a.n < n.n
""".strip(),
                        format_as="table",
                    ),
                    "refId": "B",
                },
                {
                    **target(
                        """
WITH new_v AS (
  SELECT v.id FROM vacancies v
  WHERE v.created_at >= now() - interval '7 days'
    AND NOT EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id)
),
assessed_n AS (
  SELECT count(DISTINCT a.vacancy_id)::int AS n
  FROM assessments a JOIN new_v n ON n.id = a.vacancy_id
),
new_n AS (SELECT count(*)::int AS n FROM new_v)
SELECT a.n AS "2. Оценены"
FROM assessed_n a, new_n n
WHERE a.n >= n.n
""".strip(),
                        format_as="table",
                    ),
                    "refId": "C",
                },
                {
                    **target(
                        """
WITH new_v AS (
  SELECT v.id FROM vacancies v
  WHERE v.created_at >= now() - interval '7 days'
    AND NOT EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id)
),
latest AS (
  SELECT DISTINCT ON (a.vacancy_id)
    a.vacancy_id, lower(a.verdict) AS verdict, a.model, a.source
  FROM assessments a JOIN new_v n ON n.id = a.vacancy_id
  ORDER BY a.vacancy_id, a.assessed_at DESC
)
SELECT count(*)::int AS "3. SKIP авто"
FROM latest
WHERE verdict = 'skip'
  AND coalesce(model, '') <> 'manual'
  AND coalesce(source, '') <> 'job_search_2_manual'
""".strip(),
                        format_as="table",
                    ),
                    "refId": "D",
                },
                {
                    **target(
                        """
WITH new_v AS (
  SELECT v.id FROM vacancies v
  WHERE v.created_at >= now() - interval '7 days'
    AND NOT EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id)
),
latest AS (
  SELECT DISTINCT ON (a.vacancy_id)
    a.vacancy_id, lower(a.verdict) AS verdict, a.model, a.source
  FROM assessments a JOIN new_v n ON n.id = a.vacancy_id
  ORDER BY a.vacancy_id, a.assessed_at DESC
)
SELECT count(*)::int AS "4. SKIP вручную"
FROM latest
WHERE verdict = 'skip'
  AND (model = 'manual' OR source = 'job_search_2_manual')
""".strip(),
                        format_as="table",
                    ),
                    "refId": "E",
                },
                {
                    **target(
                        """
WITH new_v AS (
  SELECT v.id FROM vacancies v
  WHERE v.created_at >= now() - interval '7 days'
    AND NOT EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id)
),
latest AS (
  SELECT DISTINCT ON (a.vacancy_id)
    a.vacancy_id, lower(a.verdict) AS verdict
  FROM assessments a JOIN new_v n ON n.id = a.vacancy_id
  ORDER BY a.vacancy_id, a.assessed_at DESC
)
SELECT count(*)::int AS "5. Смотрим (apply+maybe)"
FROM latest
WHERE verdict IN ('apply', 'maybe')
""".strip(),
                        format_as="table",
                    ),
                    "refId": "F",
                },
                {
                    **target(
                        """
WITH new_v AS (
  SELECT v.id, v.external_id FROM vacancies v
  WHERE v.created_at >= now() - interval '7 days'
    AND NOT EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id)
),
consider_n AS (
  SELECT count(*)::int AS n FROM (
    SELECT DISTINCT ON (a.vacancy_id) lower(a.verdict) AS verdict
    FROM assessments a JOIN new_v n ON n.id = a.vacancy_id
    ORDER BY a.vacancy_id, a.assessed_at DESC
  ) l WHERE verdict IN ('apply', 'maybe')
),
applied_n AS (
  SELECT count(*)::int AS n FROM (
    SELECT DISTINCT vacancy_external_id FROM ui_applied_events
    WHERE applied_at >= now() - interval '7 days'
  ) e
  JOIN new_v n ON n.external_id = e.vacancy_external_id
)
SELECT a.n AS "6. Отклик на HH ⚠ меньше смотрим"
FROM applied_n a, consider_n c
WHERE a.n < c.n
""".strip(),
                        format_as="table",
                    ),
                    "refId": "G",
                },
                {
                    **target(
                        """
WITH new_v AS (
  SELECT v.id, v.external_id FROM vacancies v
  WHERE v.created_at >= now() - interval '7 days'
    AND NOT EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id)
),
consider_n AS (
  SELECT count(*)::int AS n FROM (
    SELECT DISTINCT ON (a.vacancy_id) lower(a.verdict) AS verdict
    FROM assessments a JOIN new_v n ON n.id = a.vacancy_id
    ORDER BY a.vacancy_id, a.assessed_at DESC
  ) l WHERE verdict IN ('apply', 'maybe')
),
applied_n AS (
  SELECT count(*)::int AS n FROM (
    SELECT DISTINCT vacancy_external_id FROM ui_applied_events
    WHERE applied_at >= now() - interval '7 days'
  ) e
  JOIN new_v n ON n.external_id = e.vacancy_external_id
)
SELECT a.n AS "6. Отклик на HH"
FROM applied_n a, consider_n c
WHERE a.n >= c.n
""".strip(),
                        format_as="table",
                    ),
                    "refId": "H",
                },
            ],
            "options": {
                "reduceOptions": {
                    "calcs": ["lastNotNull"],
                    "fields": "",
                    "values": False,
                },
                "orientation": "horizontal",
                "displayMode": "lcd",
                "sizing": "manual",
                "minVizHeight": 22,
                "minVizWidth": 8,
                "showUnfilled": True,
                "namePlacement": "left",
                "valueMode": "color",
            },
            "transformations": [
                {"id": "merge", "options": {}},
            ],
            "fieldConfig": {
                "defaults": {
                    "min": 0,
                    "max": 1600,
                    "color": {"mode": "fixed", "fixedColor": "blue"},
                    # Без short (1.36 K) — полные числа до 10k удобнее читать.
                    "unit": "none",
                    "decimals": 0,
                },
                "overrides": [
                    {
                        "matcher": {
                            "id": "byName",
                            "options": "2. Оценены ⚠ меньше Core",
                        },
                        "properties": [
                            {
                                "id": "color",
                                "value": {"mode": "fixed", "fixedColor": "red"},
                            },
                            {
                                "id": "thresholds",
                                "value": {
                                    "mode": "absolute",
                                    "steps": [{"color": "red", "value": None}],
                                },
                            },
                        ],
                    },
                    {
                        "matcher": {
                            "id": "byName",
                            "options": "5. Смотрим (apply+maybe)",
                        },
                        "properties": [
                            {
                                "id": "color",
                                "value": {"mode": "fixed", "fixedColor": "green"},
                            },
                            {
                                "id": "thresholds",
                                "value": {
                                    "mode": "absolute",
                                    "steps": [{"color": "green", "value": None}],
                                },
                            },
                        ],
                    },
                    {
                        "matcher": {
                            "id": "byName",
                            "options": "6. Отклик на HH ⚠ меньше смотрим",
                        },
                        "properties": [
                            {
                                "id": "color",
                                "value": {"mode": "fixed", "fixedColor": "red"},
                            },
                            {
                                "id": "thresholds",
                                "value": {
                                    "mode": "absolute",
                                    "steps": [{"color": "red", "value": None}],
                                },
                            },
                        ],
                    },
                ],
            },
        },

        (
            lambda p: (
                p["fieldConfig"]["overrides"].extend(
                    [
                        {
                            "matcher": {"id": "byName", "options": "не ушло"},
                            "properties": [
                                {
                                    "id": "color",
                                    "value": {"mode": "fixed", "fixedColor": "red"},
                                }
                            ],
                        },
                        {
                            "matcher": {"id": "byName", "options": "скип TG"},
                            "properties": [
                                {
                                    "id": "color",
                                    "value": {
                                        "mode": "fixed",
                                        "fixedColor": "orange",
                                    },
                                }
                            ],
                        },
                    ]
                )
                or p
            )
        )(
            barchart_panel(
                322,
                "Отклики по дням × резюме",
                """WITH days AS (
  SELECT generate_series(
    date_trunc('day', $__timeFrom()::timestamptz AT TIME ZONE 'Europe/Moscow'),
    date_trunc('day', $__timeTo()::timestamptz AT TIME ZONE 'Europe/Moscow'),
    interval '1 day'
  ) AS day
),
labels AS (
  SELECT unnest(ARRAY['PHP', 'PM', 'TPM', 'не ушло', 'скип TG']) AS metric
),
grid AS (
  SELECT d.day, l.metric FROM days d CROSS JOIN labels l
),
ok AS (
  SELECT
    date_trunc('day', applied_at AT TIME ZONE 'Europe/Moscow') AS day,
    resume_label AS metric,
    count(*)::int AS n
  FROM ui_applied_events
  WHERE $__timeFilter(applied_at)
    AND resume_label IN ('TPM', 'PM', 'PHP')
    AND source = 'apply'
  GROUP BY 1, 2
),
fail AS (
  SELECT
    date_trunc('day', failed_at AT TIME ZONE 'Europe/Moscow') AS day,
    kind AS metric,
    count(*)::int AS n
  FROM ui_apply_fail_events
  WHERE $__timeFilter(failed_at)
    AND kind IN ('не ушло', 'скип TG')
  GROUP BY 1, 2
),
counts AS (
  SELECT * FROM ok
  UNION ALL
  SELECT * FROM fail
)
SELECT g.day AS time, g.metric, coalesce(c.n, 0)::int AS value
FROM grid g
LEFT JOIN counts c ON c.day = g.day AND c.metric = g.metric
ORDER BY 1, 2""",
                y0 + 15,
                h=9,
                w=24,
                x=0,
                stacking="normal",
                plan_line=30,
            )
        ),
        timeseries_panel(
            311,
            "Скачано → owner-резюме",
            """WITH hours AS (
  SELECT generate_series(
    date_trunc('hour', $__timeFrom()::timestamptz),
    date_trunc('hour', $__timeTo()::timestamptz),
    interval '1 hour'
  ) AS hour
),
labels AS (
  SELECT unnest(ARRAY['PHP', 'PM', 'TPM']) AS resume_label
),
grid AS (
  SELECT h.hour, l.resume_label FROM hours h CROSS JOIN labels l
),
counts AS (
  SELECT
    date_trunc('hour', v.first_seen_at) AS hour,
    o.resume_label,
    count(*)::int AS n
  FROM vacancies v
  JOIN ui_vacancy_owner o ON o.vacancy_external_id = v.external_id
  WHERE $__timeFilter(v.first_seen_at)
    AND o.resume_label IN ('TPM', 'PM', 'PHP')
  GROUP BY 1, 2
)
SELECT g.hour AS time, g.resume_label AS metric, coalesce(c.n, 0)::int AS value
FROM grid g
LEFT JOIN counts c ON c.hour = g.hour AND c.resume_label = g.resume_label
ORDER BY 1, 2""",
            y0 + 24,
            h=9,
            w=24,
            x=0,
        ),
        _rejection_rate_panel(y0),
        barchart_panel(
            325,
            "Отказы по дням × резюме",
            """WITH days AS (
  SELECT generate_series(
    date_trunc('day', $__timeFrom()::timestamptz AT TIME ZONE 'Europe/Moscow'),
    date_trunc('day', $__timeTo()::timestamptz AT TIME ZONE 'Europe/Moscow'),
    interval '1 day'
  ) AS day
),
labels AS (
  SELECT unnest(ARRAY['PHP', 'PM', 'TPM']) AS resume_label
),
grid AS (
  SELECT d.day, l.resume_label FROM days d CROSS JOIN labels l
),
counts AS (
  SELECT
    date_trunc('day', rejected_at AT TIME ZONE 'Europe/Moscow') AS day,
    resume_label,
    count(*)::int AS n
  FROM ui_rejection_events
  WHERE $__timeFilter(rejected_at)
    AND resume_label IN ('TPM', 'PM', 'PHP')
  GROUP BY 1, 2
)
SELECT g.day AS time, g.resume_label AS metric, coalesce(c.n, 0)::int AS value
FROM grid g
LEFT JOIN counts c ON c.day = g.day AND c.resume_label = g.resume_label
ORDER BY 1, 2""",
            y0 + 42,
            h=9,
            w=24,
            x=0,
            stacking="normal",
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
ORDER BY resume_label""",
            y0 + 51,
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
            y0 + 51,
            h=7,
            w=12,
            x=12,
        ),
        text_panel(
            319,
            "Что в таблицах",
            """
**По резюме:** снимок `ui_resume_stats` — отклики сегодня/всего и сколько вакансий закреплено за резюме после дедупа (`owner` / `shared` / `sole`).

**Последние прогоны:** сырой журнал suitable — `serp` vs `уже были` vs `скачано` по каждому запуску. Колонка `старые` — отсечены по дате публикации.

**Отказы / отклики %:** `ui_rejection_events` / `ui_applied_events` по неделе × живые резюме (PHP/PM/TPM). Дата отказа ≈ сообщение в чате HH (лаг 1–14 дн. нормален). Без даты в архиве и старые резюме (SM/HIT) не входят. Падает % при стабильных откликах после правки резюме — хороший сигнал; падает только абсолют при меньшем apply — не вывод.
""",
            y0 + 58,
            h=5,
        ),
    ]


# Панели, которые больше не возвращаем при bootstrap/patch (пользователь убрал).
REMOVED_PANEL_IDS = frozenset({
    11,   # «Прогоны подбора по статусу» — ритм крона, не аналитика
    12,   # «События Telegram по дням» — сырой count журнала, не «ушло в TG»
    13,   # «Сопроводительные: статусы» — pie схлопнут в value, не читается
    14,   # «Чаты HH: новых в день» — replace-all hh_chats сбрасывает created_at
    20,   # «Дневные метрики (пачка…)» — snapshot daily_metrics; отклики=0, дубль темы UI
    21,   # «Все оценки DevAI» — pie схлопнут в value; дубль timeseries оценок
    22,   # «Новые вакансии по дням» — грубый created_at; живее «Скачано → owner»
    307,  # «Плитки сверху и воронка»
    310,  # SERP vs «уже были» — сумма прогонов крона, не аналитика
    312,  # дубль линии apply — есть столбцы × резюме
    313,  # оценки DevAI skip/apply/maybe — нечитаемый спагетти
    316,  # История daily_metrics — нули, правда в ui_applied_events
    317,  # «Что на графике скачивания»
    318,  # «Что на графиках откликов и оценок»
    320,  # текст daily_metrics
    321,  # дубль «Отклики по дням (всего)» — остаётся только × резюме
    323,  # «Отклики по дням» (пояснение про source=apply)
})


def _ensure_today_msk_var(dash: dict) -> None:
    """Добавить today_msk только если её ещё нет — не затирать остальные variables."""
    templating = dash.get("templating")
    if not isinstance(templating, dict):
        templating = {"list": []}
        dash["templating"] = templating
    items = templating.get("list")
    if not isinstance(items, list):
        items = []
        templating["list"] = items
    if any(isinstance(v, dict) and v.get("name") == "today_msk" for v in items):
        return
    items.append(
        {
            "name": "today_msk",
            "label": "Сегодня (МСК)",
            "type": "query",
            "datasource": DS,
            "query": (
                "SELECT to_char("
                "(now() AT TIME ZONE 'Europe/Moscow')::date, "
                "'DD.MM.YYYY')"
            ),
            "regex": "",
            "includeAll": False,
            "multi": False,
            "refresh": 2,
            "hide": 2,
            "current": {},
            "options": [],
            "definition": (
                "SELECT to_char("
                "(now() AT TIME ZONE 'Europe/Moscow')::date, "
                "'DD.MM.YYYY')"
            ),
        }
    )


def _merge_panel(existing: dict, desired: dict) -> None:
    """Обновить query/тип контента, сохранив gridPos и ручные опции пользователя."""
    gp = existing.get("gridPos")
    existing["targets"] = desired.get("targets", existing.get("targets"))
    if desired.get("title") is not None:
        existing["title"] = desired["title"]
    if desired.get("type") and desired["type"] != existing.get("type"):
        # смена типа (stat→table и т.п.) — берём канон, но оставляем позицию
        for key in ("type", "options", "fieldConfig", "transformations", "datasource"):
            if key in desired:
                existing[key] = desired[key]
    else:
        # тот же тип: подтянуть SQL-зависимые куски, не трогая layout
        if "transformations" in desired:
            existing["transformations"] = desired["transformations"]
        # funnel colors / barchart stacking — нужен fieldConfig/options из канона
        if existing.get("id") in (309, 311, 322, 305, 324, 325):
            if "fieldConfig" in desired:
                existing["fieldConfig"] = desired["fieldConfig"]
            if "options" in desired:
                existing["options"] = desired["options"]
            if "description" in desired:
                existing["description"] = desired["description"]
    if gp is not None:
        existing["gridPos"] = gp


def main(argv: list[str] | None = None) -> int:
    """По умолчанию prune; полный merge SQL — только с --sync-queries."""
    argv = list(sys.argv[1:] if argv is None else argv)
    sync_queries = "--sync-queries" in argv or "--full" in argv
    widen = "--widen" in argv
    add_missing = "--add-missing" in argv or sync_queries

    raw = api("GET", f"/api/dashboards/uid/{DASH_UID}")
    dash = raw["dashboard"]
    panels: list[dict] = list(dash.get("panels") or [])

    before = len(panels)
    panels = [p for p in panels if p.get("id") not in REMOVED_PANEL_IDS]
    pruned = before - len(panels)
    by_id = {
        int(p["id"]): p
        for p in panels
        if isinstance(p.get("id"), int)
    }

    desired_list = [p for p in build_stats_panels(0) if p.get("id") not in REMOVED_PANEL_IDS]
    desired = {int(p["id"]): p for p in desired_list if isinstance(p.get("id"), int)}

    if 300 not in by_id:
        for p in panels:
            gp = p.get("gridPos") or {}
            gp["y"] = int(gp.get("y", 0)) + STATS_BLOCK_H
            p["gridPos"] = gp
        panels = desired_list + panels
        message = "bootstrap Статистика row (first install)"
        mode = "bootstrap"
    elif sync_queries:
        for pid, want in desired.items():
            if pid in by_id:
                _merge_panel(by_id[pid], want)
            elif add_missing:
                panels.append(want)
                by_id[pid] = want
        if widen:
            for pid in (311, 322):
                if pid in by_id:
                    gp = by_id[pid].setdefault("gridPos", {})
                    gp["x"] = 0
                    gp["w"] = 24
        message = "sync-queries Статистика (keep user time/layout)"
        mode = "sync-queries"
    else:
        # Только prune (+ опционально недостающие панели). Без перезаписи SQL.
        if add_missing:
            for pid, want in desired.items():
                if pid not in by_id:
                    panels.append(want)
                    by_id[pid] = want
        message = f"prune Статистика (−{pruned} panels)" if pruned else "noop Статистика (nothing to prune)"
        mode = "prune"

    dash["panels"] = panels
    dash["version"] = raw["dashboard"].get("version")
    if not dash.get("timezone"):
        dash["timezone"] = "Europe/Moscow"
    dash["tags"] = sorted(set((dash.get("tags") or []) + ["статистика", "hh"]))
    _ensure_today_msk_var(dash)

    if mode == "prune" and pruned == 0 and not add_missing:
        print(json.dumps({"status": "ok", "mode": mode, "pruned": 0, "saved": False}, ensure_ascii=False))
        return 0

    result = api(
        "POST",
        "/api/dashboards/db",
        {
            "dashboard": dash,
            "folderUid": raw.get("meta", {}).get("folderUid") or None,
            "message": message,
            "overwrite": True,
        },
    )
    print(json.dumps({
        "status": result.get("status"),
        "uid": result.get("uid"),
        "url": result.get("url"),
        "version": result.get("version"),
        "mode": mode,
        "pruned": pruned,
        "saved": True,
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
