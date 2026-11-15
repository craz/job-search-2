# job_search_2 — документация

PHP-приложение для работы с аккаунтом HeadHunter: OAuth `/me`, browser-сессия (Playwright), HTML UI с кешем и текстами резюме.

Стек: **PHP 8.5 · MVC + Onion · playwright-php · TypeScript + Vite**. Секреты только в `.env`.

Правила отбора (SKIP/OK) — [scoring-policy.md](scoring-policy.md) + [project.mdc](../.cursor/rules/project.mdc). Оценка: [scoring-devai.md](scoring-devai.md) (промпты в `config/scoring/`, без Python scoring).

## Быстрый старт

1. Скопировать `.env.example` → `.env`, заполнить токен / UA / прокси.  
2. `composer install` && `npm install`.  
3. `npm run hh:browser:login` — сохранить cookies.  
4. `npm run ui` → открыть [http://127.0.0.1:8888/](http://127.0.0.1:8888/) (не `localhost`).

## Как читать

Сначала этот файл (оглавление). Дальше — **один** тематический doc по нужной зоне. Детали не дублируем: правда в тематическом файле, здесь только указатель.

## Оглавление

| Документ | О чём |
|--|--|
| [Архитектура MVC + Onion](architecture-mvc.md) | Presentation / Application / Domain / Infrastructure, Canvas |
| [HH API](hh-api.md) | OAuth, `GET /me`, прокси, коды выхода CLI |
| [Browser-сессия](browser-session.md) | Login, storageState, город, список резюме |
| [Текст резюме](resume-text.md) | Скачивание TXT через UI-конвертер, кеш, модалка |
| [HTML UI и кеш](html-ui-cache.md) | Экран, FileCache, busy/jobs, однопоточный `php -S` |
| [Вакансии → Core](vacancies-fetch.md) | новые suitable → Core → DevAI; дедуп owner; `busy=apply`; перекачка bot-wall `/?page=rehydrate` |
| [Оценка DevAI](scoring-devai.md) | Промпты/правила в репо → DevAI → Core assessments |
| [Политика отбора](scoring-policy.md) | Канон SKIP/OK для агента и промпта |
| [Чаты HH](chats.md) | Шаг 1: список чатов → Core `hh_chats`; журнал отказов `/?page=rejections` |
| [Планировщик](schedule.md) | Авто-задачи (`schedule`): чаты каждые 10 мин |
| [Компании](companies.md) | Справочник работодателей из suitable + отказы; исключения (`/?page=exclusions`) |
| [Просмотры резюме](resume-views.md) | History HH → FileCache → бейдж/фильтр на вакансиях |
| [Журнал ошибок](errors.md) | Сбои apply* → FileCache `app_errors` → `/?page=errors` |
| [Статистика](statistics.md) | Grafana row «Статистика» (suitable / apply / skip) · `/?page=statistics` → редирект |
| [Анкеты HH](screening-forms.md) | Screening fill: common/learned/TG при apply |
| [Сопроводительные](cover-letters.md) | DevAI черновик → Core → TG ok/правка/skip → apply |
| [Telegram DM](telegram-dm.md) | flock letter/captcha/screening + журнал Core/`tg-log` |
| [Капча HH](captcha.md) | 2captcha ImageToText → TG fallback |
| [Frontend (Vite)](frontend-vite.md) | TS OOP, `npm run ui`, live-reload, HMR |

Правила объёма docs и стиля кода: [`.cursor/rules/project.mdc`](../.cursor/rules/project.mdc).  
Схема слоёв: Cursor Canvas `architecture.canvas.tsx` (шаги 1–15).

Корневой [README.md](../README.md) только указывает сюда — не дублирует оглавление.
