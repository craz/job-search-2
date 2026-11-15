# Чаты HH (шаг 1)

**Зачем:** видеть переписки с работодателями у себя, не только на hh.ru.  
**Что делает сейчас:** скачивает **карточки списка** (вакансия, компания, статус, время, unread) в **Core Postgres**.  
**Где код:** `ApplicantPages::listChats`, `SyncChatList` / `ListChats`, `CoreChatStore`, Core `hh_chats` + `/api/v1/hh-chats`, `ChatsPageView`, `hh.php chats`.

## Поток

1. Шапка справа → **Чат** → `/?page=chats`.
2. **Скачать с HH** → `busy=chats` → `php bin/hh.php chats` (Playwright + chatik API, пагинация `nextFrom`/`from`).
3. Авто: `php bin/hh.php schedule` (демон) читает `config/schedule.json` — задача `chats` каждые **10 мин**. Лог: `data/cache/schedule.log`. Стоп: `schedule-stop`. Один тик (cron): `schedule-once`.
4. `PUT /api/v1/hh-chats` → таблица `hh_chats` в Postgres контейнера Core. Сообщения в v1 не тянем.
5. **Покинуть все отказы (N)** → `busy=leave_rejections` → `hh.php leave-rejections` → архив + chatik `/leave` + пересинк.
6. **Покинуть старше месяца (N)** → `busy=leave_old` → `hh.php leave-old` → `ChatHistoryArchive` + chatik `/leave` + пересинк. В UI остаются с бейджем «архив».

## Поля карточки

`chat_id`, `url`, `vacancy_title`, `company_name`, `status_label`, `list_position` (порядок как в chatik/HH), `last_message_at` (время в карточке HH), `last_activity_at`, `last_message_preview`, `unread_count`, `synced_at`.

Шаблон HH «уже закрыли эту позицию» в preview → отказ (`DISCARD`) + бейдж **Закрыта** (`ChatSummary::isVacancyClosed`), в компании/журнал отказов тоже.

Список в UI сортируется по `list_position`, не по `last_activity_at` (у HH свой порядок).

Даты в UI — через `RuDate` (`dd.mm.YYYY HH:mm:ss`, МСК).

## Резюме отклика (без Core applications)

Связка `vacancy_external_id → resume` живёт в локальном FileCache `applied_by_vacancy` (`AppliedByVacancyStore`), **не** в Core `applications` / `hh_chats`.

| Источник | Когда |
|--|--|
| Playwright `apply` | после успешного / already_applied — известный `resumeId` |
| chatik sync | `item.resources.RESUME` + `resources.resumeHashById` / `resumes[].title` |

В карточке чата UI: `резюме: …` (из карты по `vacancy_external_id`). Resume в Core PUT не пишем (миграции `hh_chats` нет в этом шаге).

## Журнал отказов

Шапка → **Отказы** → `/?page=rejections`: журнал `DISCARD` / «Отказ» = live `hh_chats` ∪ локальный архив `chat_rejections` (`ChatRejectionArchive`).  
Архив пишется при синке чатов и **до** leave — иначе `replaceAll` после «Покинуть» стирает DISCARD из Core, и компании теряют пометки.  
Действие «покинуть» — на странице **Чат**. Код: `ListRejections`, `LeaveRejectionChats`, `ChatRejectionArchive`.

Не путать с:
- **Компании → Отказы** — агрегат по работодателю;
- **Вакансии → Отказы** — suitable-вакансии компаний с отказом;
- **Исключения** — ручной список (Сбер и т.п.).

## Связанные docs

- [Скачивание вакансий](vacancies-fetch.md)
- [Browser-сессия](browser-session.md)
- [HTML UI и кеш](html-ui-cache.md)
- [Компании](companies.md)
