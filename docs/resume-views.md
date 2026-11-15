# Просмотры резюме HH

**Зачем:** видеть на странице вакансий, что работодатель смотрел резюме.  
**Что делает:** Playwright тянет `/applicant/resumeview/history?resumeHash=…` → FileCache → бейдж/фильтр на вакансиях.  
**Где код:** `ApplicantPages::listResumeViews`, `SyncResumeViews`, `ResumeViewsStore`, `VacancyPage` (+ `VacancyPageRows`), `VacanciesPageView`, `hh.php resume-views`.

## Поток

1. Вакансии → **Просмотры резюме** → `busy=resume_views` → `php bin/hh.php resume-views`.  
   Авто: задача `resume_views` в `config/schedule.json` (каждые 10 мин, демон `hh.php schedule`).
2. Для каждого резюме из FileCache `resumes` открывается history URL, из Lux `applicantResumeViewHistory.historyViews` читаются компании.
3. Запись в FileCache `resume_views`: `resume_hash → employer_id → { company_name, viewed_at[] }`.
4. На `/?page=vacancies&resume=` матч по `company_external_id` (fallback — имя компании) → бейдж «Смотрели · дата» и фильтр **Смотрели**.

HH **не** отдаёт `vacancy_id` в истории просмотров — только работодателя и время. Stub-карточек без вакансии в списке нет.

## UI

| Элемент | Где |
|--|--|
| Кнопка «Просмотры резюме» | шапка страницы вакансий |
| Бейдж в списке/детали | `resume_viewed_at` через `RuDate` |
| Фильтр `filter=viewed` | вкладка «Смотрели (N)» |

## Связанные docs

- [Вакансии → Core](vacancies-fetch.md)
- [Чаты HH](chats.md)
- [Browser-сессия](browser-session.md)
