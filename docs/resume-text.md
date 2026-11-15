# Текст резюме (TXT)

**Зачем:** показать полный текст резюме в UI по клику на иконку листа.  
**Что делает:** Playwright открывает UI-конвертер hh.ru (`Скачать → txt`), забирает текст, кладёт в кеш.  
**Где код:** `ApplicantPages::readResumeTxt`, `Application/Resume/DownloadTexts`, `Domain/Resume/ResumeText`, `php bin/hh.php resume-text`, модалка в `HtmlPageView` + `assets/ts/ui/ResumeTextModal.ts`.

## Почему не API

`GET /resumes/{id}` у приложения часто **403**. В UI hh.ru тот же поток: меню «⋯» → «Скачать» → форматы; TXT ведёт на:

`/resume_converter/{title}.txt?hash={id}&type=txt`

Ответ часто HTML с телом резюме — берём `innerText` (`.resume__body` / `body`).

## Поток

```
список резюме (id + title из кеша)
  → Playwright + storageState
  → goto resume_converter … type=txt
  → текст → FileCache key resume_texts
  → HTML: JSON #resume-texts-data
  → клик по .js-resume-text → модалка (крестик / backdrop / Escape)
```

## CLI

```bash
php bin/hh.php resume-text              # все из кеша resumes
php bin/hh.php resume-text <resume_id>  # одно
```

Пишет `data/cache/resume_texts.json`. При «Обновить данные» то же делает `php bin/hh.php refresh` после списка резюме.

| Exit | Смысл |
|--|--|
| 0 | OK |
| 1 | ошибка скачивания |
| 2 | нет списка в кеше |
| 3 | id не найден в кеше |

## UI

- Иконка листа справа в таблице резюме — **только клик** (не hover).
- Нет текста в кеше → подсказка «Обновить данные».
- Тексты встраиваются в страницу как `<script type="application/json" id="resume-texts-data">` — без повторного Playwright при открытии модалки.

## Связанные docs

- [Browser-сессия](browser-session.md)
- [HTML UI и кеш](html-ui-cache.md)
- [Frontend (Vite)](frontend-vite.md)
