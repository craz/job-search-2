# Журнал ошибок

**Зачем:** не терять тексты сбоев отклика после flash.  
**Что делает:** пишет ошибки `apply` / `apply_consider` в локальный FileCache и показывает список.  
**Где код:** `AppErrorStore`, `ListAppErrors`, `ErrorsController`, `ErrorsPageView`, `/?page=errors`.

## Поток

1. Шапка справа → **Ошибки** → `/?page=errors` (по умолчанию фильтр **Открытые**).
2. Сбой отклика (`hh.php apply` / `apply-consider`) → `AppErrorStore::append*` → ключ кеша `app_errors`.
3. Фильтры: `open` / `unavailable` / `skipped` / `resolved` / `all`. Источник (apply / apply-consider) — иконка у заголовка.
4. У открытой записи с `resume_id` + `vacancy_id` — **Повторить** / **Открыть на hh.ru**.
5. **Повторить все (N)** → `busy=apply_errors` → `hh.php apply-errors` (`RetryFailedApplies`). Skip TG в retry не попадает.
6. Успешный отклик → `markApplied` — «Исправлено — откликнуто», `resolved_at`.
7. Skip анкеты/письма в TG → `markSkipped` — статус **скип** (`skipped_at` + `resolved_at`), уходит из открытых ошибок и из фильтра «Ошибки» на вакансиях; вкладки **Скип** / **Скип TG**.
8. Ручной отклик → чат в `hh_chats` по той же vacancy: `ReconcileApplyErrorsFromChats` закрывает ошибку (resume берём из ошибки — у chatik часто нет resume_id) и пишет `applied_by_vacancy`. Триггеры: sync чатов, страница Ошибки, страница вакансий.

Не путать с вердиктом DevAI `skip` — туда попадают только реальные сбои Playwright/HH. Скип формы TG — отдельный статус.

Частый ложный «Кнопка Откликнуться не найдена»: HH увёл на `/account/captcha` («не робот»). Inspect теперь пишет `captcha` и при apply зовёт solve (TG/auto), а не missing.

## Поля записи

`at`, `source` (`apply` | `apply_consider`), `resume_*`, `vacancy_*`, `message`, `resolved_at`, `skipped_at`.

Одна запись на пару resume+vacancy: повторный сбой — upsert (обновляет message/статус), не дубль. `compactDuplicates` при открытии журнала.

Потолок: ~300 записей.

## Связанные docs

- [Вакансии → Core](vacancies-fetch.md)
- [HTML UI и кеш](html-ui-cache.md)
