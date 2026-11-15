# HTML UI и кеш

**Зачем:** смотреть аккаунт / город / резюме без блокировки страницы на Playwright.  
**Что делает:** читает `FileCache`; долгие операции — фон + экран busy.  
**Где код:** `public/index.php`, `Infrastructure/Cache/*`, `Presentation/View/HtmlPageView`, `bin/hh.php` (`refresh` / `job-set-city` / `vacancies`), `assets/ts/busy/*`.

## Запуск

```bash
npm run ui
# открыть http://127.0.0.1:8888/  (не localhost, не :8890)
```

PHP отдаёт `public/`; JS — same-origin `/build` (см. [frontend-vite](frontend-vite.md)).

## Ключи кеша (`data/cache/`)

| Key | Содержимое |
|--|--|
| `account` | снимок `/me` |
| `city` | текущий город сессии |
| `resumes` | список резюме |
| `resume_texts` | тексты TXT |
| `job.status.json` | состояние фонового job |

Вакансии хранятся в **Core/Postgres** (SearchRun `resume_suitable`), не в FileCache — см. [vacancies-fetch.md](vacancies-fetch.md).

## Busy / jobs

Долгое (refresh, set_city, vacancies, apply) **никогда** не выполняется внутри запроса отрисовки UI:

1. `/?busy=refresh` (или `set_city` / `vacancies` / `apply` / `apply_consider`) → PHP сразу стартует `php bin/hh.php …` в фоне → `renderBusy`.
2. Inline-таймер секунд + бандл `busy.js` поллит `/?job=status&format=json`.
3. По `done` — редирект на `/` с flash (`apply` → обратно на карточку вакансии).

JSON: `/?job=refresh|set_city|vacancies|apply|status&format=json` (см. `JobApi.ts`).

## Экран

- Шапка: бренд + город (модалка Москва/Самара).
- Аккаунт + кнопка «Обновить данные».
- Цель и прогресс: сегодня N/10 + пачки; снимок дня в Core `daily_metrics` (Postgres).
- Таблица: название+прогресс | вакансии | «До 10 смотрим» / «Оценить N» / «Смотреть N» → `/?page=vacancies&resume=` (розовый skip, зелёный рассматриваем).
- Правила UI (без дублей CTA, без чёрного экрана): `.cursor/rules/project.mdc`.

## Ловушка `php -S`

Один поток: пока HTTP-запрос крутит Playwright, CSS/JS не отдаются → тёмный «пустой» экран. Поэтому job только в `bin/`, UI только читает кеш/статус.

## Фоновые скрипты

| Скрипт | Когда |
|--|--|
| `php bin/hh.php refresh` | load / refresh: account → city → resumes → resume_texts |
| `php bin/hh.php job-set-city` | смена города + запись city в кеш |
| `php bin/hh.php vacancies` | 10 подходящих → Core + сразу DevAI |
| `php bin/hh.php apply` | отклик на HH через Playwright (`busy=apply`) |
| `php bin/hh.php apply-consider` | пакет «Смотрим» (`busy=apply_consider`) |

См. также [vacancies-fetch.md](vacancies-fetch.md).

Статус пишут в `job.status.json` (`state`, `step`, `ok`, `message`, `updated_at`).

## Связанные docs

- [Текст резюме](resume-text.md)
- [Frontend (Vite)](frontend-vite.md)
- [Browser-сессия](browser-session.md)
