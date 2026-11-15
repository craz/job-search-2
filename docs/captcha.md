# Капча HH (авто + TG)

**Зачем:** при apply HH часто показывает «Пройдите капчу» (текст с картинки).  
**Что делает:** crop img → 2captcha/rucaptcha ImageToText → ввод; при fail — TG DM.  
**Где код:** `ServiceCaptchaSolver`, `CaptchaForm`, `TelegramCaptchaSolver`; wiring `HhCli::applicantPages`.

## Поток

1. Detect модалки (`CaptchaForm::pageHasCaptcha`).
2. `element.screenshot` только `img` капчи (не весь viewport).
3. `createTask` / `getTaskResult` (`ImageToTextTask`, `languagePool=rn`) — в ответе `cost`.
4. `CaptchaForm::typeAndSubmit` → если капча осталась — refresh + retry (до `HH_CAPTCHA_AUTO_ATTEMPTS`).
5. TG: «пройдена + стоимость + баланс» в ₽ (`cost` из getTaskResult + `getBalance`).
6. Если авто не сняло / нет ключа → `TelegramCaptchaSolver` (скрин в DM).

## Конфиг

| Env | Роль |
|--|--|
| `TWOCAPTCHA_API_KEY` | ключ → `api.2captcha.com` |
| `RUCAPTCHA_API_KEY` | если нет 2captcha → `api.rucaptcha.com` |
| `HH_CAPTCHA_AUTO` | default on при ключе; `0` = off |
| `HH_CAPTCHA_AUTO_TIMEOUT_S` | ожидание ответа API (default 120) |
| `HH_CAPTCHA_AUTO_ATTEMPTS` | попыток auto (default 4) |
| `HH_CAPTCHA_TG` / `TELEGRAM_DM_*` | fallback |

## Bot-wall при скачивании

«Подтвердите, что вы не робот» на **странице вакансии** (не account captcha) → id в `bot_wall_rehydrate`, без TG. Перекачка: `/?page=rehydrate` / `hh.php rehydrate-bot-wall` — см. [vacancies-fetch.md](vacancies-fetch.md).

## Связанное

- [Telegram DM](telegram-dm.md) — flock + журнал
- [Ошибки](errors.md)
- [Вакансии → Core](vacancies-fetch.md)
