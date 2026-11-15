# Статистика (Grafana)

**Зачем:** временные ряды скачивания suitable, откликов по резюме, SKIP/APPLY/MAYBE, owner и `daily_metrics`.  
**Где смотреть:** [Поиск работы](http://127.0.0.1:3000/d/job-search-2/poisk-raboty) → row **Статистика**.  
**В UI:** ссылка «Статистика» в шапке / `/?page=statistics` → редирект на Grafana (`GRAFANA_STATS_URL`).

## Источники

| Что | Откуда | Таблица / серия |
|--|--|--|
| SERP / уже были / скачано | FileCache `suitable_run_stats` → sync | `ui_suitable_run_stats` |
| Отклики по резюме | FileCache `applied_by_vacancy` → sync | `ui_applied_events` |
| Owner вакансии→резюме | FileCache `vacancy_resume_owner` → sync | `ui_vacancy_owner` |
| Снимок по резюме | sync из кешей | `ui_resume_stats` |
| Вердикты DevAI | Core `assessments` (+ owner) | SQL на дашборде |
| История дня | Core `daily_metrics` | SQL на дашборде |

Синк: `scripts/grafana/sync_live_jobs.py` (live job каждые 2 с, статистика каждые 30 с).  
Дашборд дописывается скриптом `scripts/grafana/patch_stats_dashboard.py` (под каждым блоком — text/markdown «что считается»).

## Что убрано из PHP

Страница `/?page=statistics` и классы `ListStatistics` / `StatisticsPageView` / `ListSuitableBatches` / `RejectionRatesByResume` — больше не в приложении.  
`SuitableRunStatsStore` и `SyncDailyProgress` остались (пишут кеш / `daily_metrics`).
