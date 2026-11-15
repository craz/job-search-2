# Компании

**Зачем:** один список работодателей из скачанных вакансий и кто уже отказал.  
**Что делает:** `/?page=companies` — агрегация suitable SearchRun + отказы из `hh_chats` ∪ архива `chat_rejections`.  
**Где код:** `Application/Company/ListCompanies`, `CompaniesController`, `CompaniesPageView`.

## Поток

1. Шапка → **Компании**.
2. Компании из `serp_meta` всех suitable-run (имя, employer id, счётчик вакансий).
3. Отказы: live `hh_chats` ∪ архив `chat_rejections` (`DISCARD` / «Отказ») → бейдж и список вакансий.
4. Фильтр **Отказы**, поиск по названию компании/вакансии. Сверху — кто отказал.

## Исключения

Отдельная страница `/?page=exclusions` (шапка **Исключения**): компании, куда не откликаемся.
Сид «Подтянуть Сберы» берёт из этого же справочника имена `Сбер*` / `Sber*` / Сбербанк / Sberbank / SberTech.
В фильтре «Смотрим» / отклике `CompanyExclusionStore::hintFor` режет и по списку, и по `looksLikeSber` (например «ООО Страховая компания Сбербанк страхование»), даже если сид ещё не записал имя.
Хранение: FileCache `company_exclusions`. Такие вакансии не в «Смотрим».

## Связанные docs

- [Чаты HH](chats.md)
- [Вакансии → Core](vacancies-fetch.md)
