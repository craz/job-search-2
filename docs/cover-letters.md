# Сопроводительные письма (DevAI → Core → TG)

**Зачем:** HH часто требует письмо при отклике; v1 больше не abort’ит.  
**Что делает:** DevAI пишет черновик → Core `cover_letters` → Telegram ok/правка/skip → fill textarea → submit.  
**Где код:** `GenerateCoverLetter`, `CoverLetterFiller`, `TelegramCoverLetterSolver`, Core `/api/v1/cover-letters`.

## Поток

1. `applyToVacancy` видит `letter_required` (модалка открыта).
2. Текст резюме из FileCache `resume_texts`; описание вакансии с DOM страницы.
3. Промпт `config/cover_letter/prompt.txt` (`prompt_version=cover_v12`) + опционально `verified_context.txt` → DevAI.
   Текст вакансии: DOM, при слабом скрапе (форма «Отклик») — Core `lookup-by-external-ids` + `getVacancy`. NEEDS_INPUT «полный текст вакансии» в TG не уходит.
4. Прямые инструкции работодателя к письму — обязательные. Статусы по пунктам: `CONFIRMED` / `CONFIRMED_GAP` / `PARTIAL` / `MISSING`.
   - `CONFIRMED_GAP` — подтверждённая граница/отсутствие опыта с готовой формулировкой в контексте; пункт закрыт, не `NEEDS_INPUT` и не повторный TG;
   - все Qi `CONFIRMED`/`CONFIRMED_GAP` → готовое письмо (даже если модель вернула `DRAFT_WITH_GAPS`);
   - пробелы `PARTIAL`/`MISSING` → `DRAFT_WITH_GAPS` → TG `askGaps` (только незакрытые Qi) → одна повторная генерация;
   - один персональный факт → `NEEDS_INPUT` → TG `askMissingFact` → одна повторная генерация;
   - повторный gaps/NEEDS_INPUT → fail-closed (не отправляем неполный отклик).
5. `POST /api/v1/cover-letters` (`status=draft`) — только когда есть готовый черновик письма (`status=ok`).
   `Idempotency-Key` = fingerprint всего body (не только prompt): повтор с тем же письмом → replay; новый черновик после retry → новая запись. `HTTP 409` → PATCH существующей / новый ключ.
6. TG DM: фрагмент вакансии + черновик. Ответ:
   - `ok` — отправить как есть;
   - любой другой текст — правка;
   - `skip` — пропуск вакансии.
7. `PATCH` → `accepted` / `skipped` / `failed`.
8. Fill letter textarea → `completeResponsePopup` submit.

Fail-closed: нет резюме / DevAI / TG / таймаут / повторный gaps → apply fail, запись в `app_errors` (letter-флаг).

## Конфиг

| Файл / env | Роль |
|--|--|
| `config/cover_letter/prompt.txt` | Шаблон (`cover_v12`: кейсы + CONFIRMED_GAP + DRAFT_WITH_GAPS) |
| `config/cover_letter/verified_context.txt` | Доп. подтверждённые факты вне резюме |
| `config/cover_letter/profile.json` | `prompt_version`, лимиты |
| `HH_LETTER_TG` | on при `TELEGRAM_DM_*`; `0` = off |
| `HH_LETTER_TG_TIMEOUT_S` | default 900 |
| `DEVAI_*` | тот же router, что scoring |

## Core API

| Метод | Путь |
|--|--|
| POST | `/api/v1/cover-letters` + `Idempotency-Key` |
| PATCH | `/api/v1/cover-letters/{id}` |
| GET | `/api/v1/cover-letters?vacancy_external_id=&limit=` |

Таблица `cover_letters` в Postgres Core (не `applications.cover_letter_text`).

## TG DM

Диалог письма держит flock на весь gaps+ask; журнал — [telegram-dm.md](telegram-dm.md).

## Связанное

- [Анкеты HH](screening-forms.md)
- [Оценка DevAI](scoring-devai.md)
- [Журнал ошибок](errors.md)
