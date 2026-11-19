# Скачивание вакансий → Core

**Зачем:** подходящие вакансии с описанием в Postgres через Core; сразу оценка DevAI.  
**Что делает:** Playwright SERP + detail → ingest → **сразу ScoreTen**. Стор: пустой SERP / старше ≤7д / `maxIngest` (40). Уже набранные apply/maybe **не** останавливают fetch.  
**Где код:** `Application/Vacancy/{FetchSuitable,ScoreTen,ListForResume}`, `Application/JobApplication/ApplyToVacancy`, `HhCli::{vacancies,apply}`, `Domain/Core/CoreApi`, `ApplicantPages::applyToVacancy`.

## Хранение

| Место | Что |
|--|--|
| **Postgres via Core** `POST /vacancies/ingest` | Вакансия (title, description+skills, company…); `archived=false` → `source_status=active` |
| **SearchRun** `acquisition_kind=resume_suitable` | Связка «резюме → эти N вакансий» (`candidate_context_snapshot.hh_resume_external_id` + items) |

Локальный `FileCache` для тел вакансий **не** используется.

## Поток скачивания

1. UI: **Качать suitable** / всем → `busy=vacancies`. Авто: schedule `vacancies` каждые **30 мин** (тот же `hh.php vacancies`).
2. Job на резюме: `startResumeSuitableRun` → SERP `?resume=&order_by=publication_time&items_on_page=50` (пропуск известных `external_id`) → detail → ≤**7 дней** → ingest → **сразу** `ScoreTen::scoreFreshVacancy`. Стор: пустой SERP, **3+ старых подряд** (окно возраста) или `maxIngest` (40) — **не** по счётчику apply/maybe. Без `order_by` HH даёт relevance — нельзя рвать выдачу на первой старой. Detail с «Подтвердите, что вы не робот» → `captcha_or_action_required` (не ingest, **без** TG-капчи); уже залитый мусор scoring режет `FETCH.hh_bot_wall` → skip. Id в FileCache `bot_wall_rehydrate` → UI `/?page=rehydrate` / `busy=rehydrate_bot_wall` / `hh.php rehydrate-bot-wall` — прямая перекачка detail по id (без SERP) + force rescore; при 5 bot-wall подряд — стоп. «Вам недоступна эта вакансия» → снимаем с очереди (не сбой сессии). Если id снова попал в SERP — FetchSuitable тоже force rescore. Список вакансий: если в SearchRun залип title «не робот», `ListForResume` подтягивает актуальный title из Core; вердикты — одним bulk `GET /assessments` (не «первые 100», иначе ложные «без оценки»). Owner-stub без `vacancy_id` (сбой detail) — lookup в Core / SERP title, пустые «Без названия» не показываем. TG-капча только при apply.
3. Оценка в том же проходе (не ждать «Оценить»). Догон: «Оценить N» у резюме / «Оценить оставшиеся» на главной — все suitable-run + owner, не только последний прогон (`ScoreTen`).

4. Просмотр: **Смотреть N** → `/?page=vacancies&resume=…` — скачанные suitable за **3 дня** **плюс** история откликов по этому резюме (applications / `applied_by_vacancy` / чаты); переключатель резюме; **Ещё** → `scope=all` с **28.10.2026** (`ListForResume::HISTORY_SINCE`, как owner-cutoff; старше в Core остаются, в UI нет).
5. **Откликнуться** → `busy=apply` → `hh.php apply <resumeId> <vacancyExternalId>` (Playwright; Core applications в v1 не пишем). «Открыть на hh.ru» — отдельная ссылка.
6. Фильтр **Смотрим (N)** → **Откликнуться на 15** (пачка, `limit=15`) / **все N** → `busy=apply_consider` → `hh.php apply-consider <resumeId> [recent|all] [limit]`.

## Резюме на бейдже «Отклик»

Core applications из Playwright в v1 не пишем. Связка вакансия ↔ резюме — FileCache `applied_by_vacancy` (`AppliedByVacancyStore`):

- запись из `ApplyToVacancy` (наш apply);
- дополнение из синка чатов (chatik `RESUME` → hash/title), см. [chats.md](chats.md).

`AppliedVacancyIndex` мержит карту + чаты Core + (если есть) старые applications. На странице вакансий бейдж: `Отклик · <название резюме> · <статус чата>`.

Просмотры резюме работодателями: [resume-views.md](resume-views.md) (`busy=resume_views` → FileCache → бейдж «Смотрели» / фильтр `viewed`).

## Дедуп между резюме

Одна HH-вакансия = одна сущность Core (`ingest` upsert). Owner: при ingest provisional (`AssignVacancyResume`: title-rules / `first`); финал — из DevAI score (`owner_resume_id`, `source=score`). UI-дедуп читает `vacancy_resume_owner`.

Внутри run: карточки, уже в `peerIndex` (любое резюме), **не** качаем снова — счётчик `skipped_known`. Журнал метрик run: FileCache `suitable_run_stats` (`SuitableRunStatsStore`) → sync в `ui_suitable_run_stats` для Grafana.
