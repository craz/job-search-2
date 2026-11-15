# Browser-сессия (Playwright)

**Зачем:** UI hh.ru там, где OAuth API недоступен (резюме, город).  
**Что делает:** headed login → `storageState` → headless чтение страниц.  
**Где код:** `Domain/Browser/*`, `Infrastructure/Browser`, `Infrastructure/Applicant/ApplicantPages`, `Application/Browser|Resume`, `Presentation/Controller/{Login,City,SetCity,Resumes}Controller`, `php bin/hh.php login|city|set-city|resumes`.

## Конфиг

| Ключ | Назначение |
|--|--|
| `HH_STORAGE_STATE` | путь к cookies JSON (по умолчанию `data/hh_storage_state.json`) |
| `HH_BROWSER_HEADLESS` | `1` headless; login сам открывает окно |
| `HH_PROXY` | тот же прокси, что для API (`socks5h://` → Playwright `socks5://`) |
| `HH_LOGIN` / `HH_PASSWORD` | опционально автозаполнение; капчу — руками |
| `HH_LOGIN_TIMEOUT_SEC` | ожидание ручного входа |
| `TELEGRAM_DM_BOT_TOKEN` / `TELEGRAM_DM_CHAT_ID` | DM-бот для кода капчи при `apply` |
| `HH_CAPTCHA_TG` | `1` вкл. (по умолчанию при наличии DM-ключей); `0` выкл. |
| `HH_CAPTCHA_TG_TIMEOUT_S` | ожидание кода в TG (сек), по умолчанию 600 |

## Потоки

1. **Login:** `npm run hh:browser:login` → окно Chromium → после входа пишется storageState.  
2. **Город:** `hh:browser:city` / `hh:browser:set-city` / `hh:browser:set-moscow` — geoSwitcher на hh.ru.  
3. **Список резюме:** `npm run hh:resumes` → страница `/applicant/resumes` → парсинг карточек (`data-qa`).

Все сценарии открывают Chromium через `PlaywrightBrowserGateway` + storageState.

## Состояния списка резюме (`ResumeList::kind`)

| kind | Смысл |
|--|--|
| `ok` | карточки прочитаны |
| `login_required` | нет сессии / форма логина |
| `captcha_or_action_required` | капча / антибот — **стоп**, пройти вручную, не солвер |
| другое | UI не распознан |

## CLI exit (типично)

| Exit | Смысл |
|--|--|
| 0 | OK |
| 1 | исключение / нет аргумента |
| 2 | нужен login |
| 4 | сценарий завершился «не ок» |

## Файлы сценариев

| CLI | npm | Действие |
|--|--|--|
| `php bin/hh.php login` | `hh:browser:login` | вход, запись storageState |
| `php bin/hh.php city` | `hh:browser:city` | прочитать город |
| `php bin/hh.php set-city` | `hh:browser:set-city` | сменить город |
| `php bin/hh.php resumes` | `hh:resumes` | список резюме JSON |

Парсинг DOM — в `Infrastructure\Applicant\ApplicantPages` (селекторы `data-qa`); сценарии — в Application use cases, Controllers тонкие.

## Капча → Telegram DM

При `apply` / `apply-consider`, если HH показывает текстовую капчу (или isBot → модалка):

1. Playwright ждёт UI капчи (до ~12 с после «Произошла ошибка») → скрин → `sendPhoto` в DM.  
2. Long-poll `getUpdates` ждёт код из `TELEGRAM_DM_CHAT_ID` (offset фиксируется **до** фото, быстрый ответ не теряется).  
3. Код вводится в поле, submit. Неверный код → новая картинка в тот же `HH_CAPTCHA_TG_TIMEOUT_S`, без отдельного «Повторить».  
4. Если за таймаут капча не снята — ошибка в журнале; «Повторить» снова шлёт скрин, когда UI появится.

Код: `Domain/Applicant/CaptchaSolver`, `Infrastructure/Telegram/TelegramDmClient`, `Infrastructure/Applicant/TelegramCaptchaSolver` (подключается в `HhCli::applicantPages`). Скрины: `data/captcha/`.

## Важно

- `php -S` однопоточный: Playwright **не** внутри HTTP-запроса UI — только фоновые `php bin/hh.php refresh|vacancies|apply|…` (см. [html-ui-cache](html-ui-cache.md)).
- Капчу не обходим пакетами-солверами; текстовую — через TG DM; invisible/hybrid без поля ввода — вручную / новая browser-сессия.

## Связанные docs

- [Текст резюме](resume-text.md)
- [HTML UI и кеш](html-ui-cache.md)
- [HH API](hh-api.md)
