# Оценка вакансий через DevAI (из этого репо)

**Зачем:** не зависеть от Python scoring `job_search_ref` в потоке «Оценить».  
**Что делает:** pre-gate EXCLUDE-01/02 → prompt из `config/scoring/` → DevAI → Core `assessments` **и** финальный owner-резюме в `vacancy_resume_owner` (`source=score`).  
**Где код:** `LocalDevAiScoring`, `CurlDevAiClient`, `CommercialDutyExclusions`, `ScoreTen`.

## Термины (не путать)

| Слово | Что это | Где живёт |
|--|--|--|
| **Вердикт APPLY / MAYBE / SKIP** | Оценка DevAI: брать в шортлист / на ревью / не брать | Core `assessments` |
| **Owner** | Какому резюме (TPM/PM/PHP) принадлежит вакансия в UI/отклике | FileCache `vacancy_resume_owner` |
| **Отклик** | Реальная отправка на HH (кнопка «Откликнуться» / `busy=apply`) | FileCache `applied_by_vacancy` (в Core `applications` в v1 не пишем) |
| **Смотрим** | UI-фильтр: ещё не откликнутые после оценки (не SKIP) | страница вакансий |

`APPLY` ≠ «уже откликнулись». Вердикт APPLY значит «можно/нужно откликнуться»; факт отклика — отдельное событие.

## Owner: provisional → score

| Момент | Источник | `source` |
|--|--|--|
| Ingest suitable | title-rules или первый suitable-peer | `rules` / `first` |
| После DevAI score | JSON `owner_resume_id` (+ fallback title-rules) | `score` |
| Вручную | UI override | `manual` |

Отдельного DevAI-assign больше нет: модель при оценке сразу выбирает резюме. UI-дедуп остаётся — одна HH-вакансия → один owner (чтение стора).

## Поток

1. UI/CLI: ScoreTen выбирает до 40 unscored vacancy UUID — **все** suitable-run резюме + owner-stub'ы (не только последний run). Кнопка «Оценить оставшиеся» → `/?job=score_ten` без `resume`.
2. Для каждой: `getVacancy` + candidate context + блок OWNER_CANDIDATES (TPM/PM/PHP из кеша `resumes`).
3. PHP EXCLUDE-01/02 (`CommercialDutyExclusions`) → при hard_skip сразу assessment `skip` без DevAI (owner не трогаем).
4. Иначе prompt `config/scoring/prompts/score_vacancy.txt` + policy → DevAI.
5. `CommercialPrimaryGuard` — снимает ложный `FIT.commercial_primary` у product-economy (CPO/HoP/PO + GMV/P&L), sales-primary оставляет.
6. JSON → Core assessment (`source=job-search-2-devai`) + запись owner (`source=score`), если не `manual`.

## Конфиг

| Файл / env | Роль |
|--|--|
| `config/scoring/prompts/score_vacancy.txt` | Вердикт + `owner_resume_id` / `owner_reason` |
| `config/scoring/profile.json` | hard exclusions flags, prompt_version |
| `docs/scoring-policy.md` | канон политики SKIP |
| `DEVAI_BASE_URL` | default `http://127.0.0.1:8190` |
| `DEVAI_ROUTER_KEY` | Bearer |
| `DEVAI_MODEL` | default `devai/auto` |

`SCORING_API_URL` в этом потоке **не** используется.

## Связанные docs

- [Политика отбора](scoring-policy.md)
- [Вакансии → Core](vacancies-fetch.md)
