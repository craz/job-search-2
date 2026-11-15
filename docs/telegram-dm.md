# Telegram DM (капча / письмо / анкета)

**Зачем:** один chat на владельца; без сериализации letter и captcha из разных apply «спорят» за ответы.  
**Что делает:** flock на весь диалог send→wait; журнал исходящих/входящих в Core Postgres (+ JSONL fallback).  
**Где код:** `TelegramDmClient`, `TelegramDmGate`, `TelegramDmJournal`; Core `telegram_dm_events`; solvers letter/captcha/screening.

Капча сначала идёт в **2captcha** ([captcha.md](captcha.md)); TG — fallback и журнал auto-попыток (`kind=captcha`).

## Последовательность

В **одном** apply порядок уже жёсткий: screening → letter → submit → captcha.  
Гонка бывает между **процессами** (два apply / schedule + ручной): оба шлют в один DM.

`TelegramDmGate` (`data/locks/telegram_dm.lock`): пока один процесс ждёт ответ в TG, второй блокируется на lock (в журнале `lock_wait` → `lock_acquired` с `wait_ms`).

## Журнал

| Куда | Путь |
|--|--|
| Core Postgres | таблица `telegram_dm_events` · `POST/GET /api/v1/telegram-dm-events` |
| Fallback | `data/telegram_dm.jsonl` (если Core недоступен) |

| direction | смысл |
|--|--|
| `out` | сообщение/фото в TG |
| `in` | ответ владельца |
| `meta` | lock_wait / lock_acquired / wait_start / wait_timeout |

`kind`: `letter` | `captcha` | `screening` | …

```bash
php bin/hh.php tg-log 50
```

Запись best-effort: сбой Core/файла не роняет apply (stderr `TG_DM_LOG … fail`).

## Связанное

- [Сопроводительные](cover-letters.md)
- [Анкеты](screening-forms.md)
- [Ошибки](errors.md)
