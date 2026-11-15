# Оценка вакансий через DevAI (из этого репо)

**Зачем:** не зависеть от Python scoring `job_search_ref` в потоке «Оценить».  
**Что делает:** pre-gate EXCLUDE-01/02 → собирает prompt из `config/scoring/` → `POST DevAI /v1/chat/completions` → `POST Core /api/v1/assessments`.  
**Где код:** `LocalDevAiScoring`, `CurlDevAiClient`, `CommercialDutyExclusions`, `ScoreTen`.

## Термины (не путать)

| Слово | Что это | Где живёт |
|--|--|--|
| **Вердикт APPLY / MAYBE / SKIP** | Оценка DevAI: брать в шортлист / на ревью / не брать | Core `assessments` |
| **Отклик** | Реальная отправка на HH (кнопка «Откликнуться» / `busy=apply`) | FileCache `applied_by_vacancy` (в Core `applications` в v1 не пишем) |
| **Смотрим** | UI-фильтр: ещё не откликнутые после оценки (не SKIP) | страница вакансий |

`APPLY` ≠ «уже откликнулись». Вердикт APPLY значит «можно/нужно откликнуться»; факт отклика — отдельное событие.

## Поток

1. UI/CLI: ScoreTen выбирает до 10 unscored vacancy UUID.
2. Для каждой: `getVacancy` + candidate context.
3. PHP EXCLUDE-01/02 (`CommercialDutyExclusions`) → при hard_skip сразу assessment `skip` без DevAI.
4. Иначе prompt `config/scoring/prompts/score_vacancy.txt` + policy → DevAI.
5. `CommercialPrimaryGuard` — снимает ложный `FIT.commercial_primary` у product-economy (CPO/HoP/PO + GMV/P&L), sales-primary оставляет.
6. JSON → Core assessment (`source=job-search-2-devai`). Force-rescore: `source=force_rescore`.

## Конфиг

| Файл / env | Роль |
|--|--|
| `config/scoring/prompts/score_vacancy.txt` | Текст запроса к модели |
| `config/scoring/profile.json` | hard exclusions flags, prompt_version |
| `docs/scoring-policy.md` | канон политики SKIP |
| `DEVAI_BASE_URL` | default `http://127.0.0.1:8190` |
| `DEVAI_ROUTER_KEY` | Bearer |
| `DEVAI_MODEL` | default `devai/auto` |

`SCORING_API_URL` в этом потоке **не** используется.

## Связанные docs

- [Политика отбора](scoring-policy.md)
- [Вакансии → Core](vacancies-fetch.md)
