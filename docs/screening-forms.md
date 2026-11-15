# Анкеты работодателя (screening)

**Зачем:** на части вакансий HH уводит на `/applicant/vacancy_response` с вопросами (`task_*`). Без ответов отклик не уходит.

**Что делает:** при apply детектит анкету → заполняет типовые/learned ответы → неизвестные спрашивает в Telegram DM → продолжает submit. Fail-closed: не выдумывает ответы.

## Поток

1. Click «Откликнуться» → модалка или `vacancy_response`.
2. `ScreeningFormFiller`: `collectTasks` → memory → `CommonScreeningRules` → TG.
3. Если ещё `letter_required` — [сопроводительные](cover-letters.md) (DevAI → Core → TG).
4. Submit / проверка кнопки как раньше.

## Где код

| Зона | Файл |
|--|--|
| Оркестратор | `Infrastructure/Applicant/ScreeningFormFiller.php` |
| DOM fill | `HhScreeningForm.php` |
| Типовые правила | `CommonScreeningRules.php` + `config/screening/common_answers.json` |
| TG | `TelegramScreeningSolver.php` (`TELEGRAM_DM_*`, `HH_SCREENING_TG`) |
| Memory | `Application/Screening/ScreeningAnswerStore.php` → FileCache `screening_answers_learned` (сид из `resume/drafts/screening-answers-learned.json`) |
| Врезка | `ApplicantPages::applyToVacancy` при `questions_required` |

## Env

- `TELEGRAM_DM_BOT_TOKEN` / `TELEGRAM_DM_CHAT_ID` — как у капчи.
- `HH_SCREENING_TG` — опционально; по умолчанию on, если есть DM-токены.
- `HH_SCREENING_TG_TIMEOUT_S` — default 900.

Формат ответа в TG: `1: a` / `2: текст` / `skip`.

## Источник ответов

- Типовые правила: `config/screening/common_answers.json` + `CommonScreeningRules` (порт `resume/scripts/hh_screening_fill.py`).
- Сид в репо: `config/screening/learned_seed.json` (подтверждённые анкеты, напр. «10 ситуаций PM»).
- Runtime memory: FileCache `screening_answers_learned` (+ сид из `resume/drafts/screening-answers-learned.json`).
- Новые ответы из TG дописываются в FileCache.
