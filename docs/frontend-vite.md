# Frontend (TypeScript + Vite)

**Зачем:** интерактив UI без inline JS в PHP View.  
**Что делает:** OOP-классы в `assets/ts/**`, сборка Vite в `public/build`.  
**Где код:** `assets/ts/`, `vite.config.ts`, `Presentation/View/ViteAssets.php`, `package.json`.

## Entrypoints

| Entry | Назначение |
|--|--|
| `ui` | главная: город, текст резюме, live-reload |
| `busy` | экран ожидания: poll job status |
| `vacancies` | страница вакансий + live-reload |

Классы с одной ответственностью: `UiApp`, `CityModal`, `ResumeTextModal`, `BusyPage`, `JobApi`, `LiveReload`.

## Команды

| Script | Что |
|--|--|
| `npm run ui` | прокси `:8888` → PHP `:18789` + `vite build --watch` |
| `npm run ui:hmr` | то же + Vite `:5173` + файл `public/build/hot` |
| `npm run build` | разовая prod-сборка |

`ViteAssets`: если есть `hot` → скрипты с `:5173`; иначе manifest → `<script type="module" src="/build/...">` (нужен module: Vite режет shared-чанки с `import`).

## Live reload (режим `ui`)

После каждой сборки и при правках PHP/CSS пишется `public/build/reload.json`.  
`LiveReload` поллит stamp и делает `location.reload()` (entry `ui`, `vacancies`, `chats`, `companies`). Без BrowserSync и без WebSocket на `:5173`.

Watcher: каталог `public/` (style.css / index.php — атомарные save на Linux), `src/Presentation/**`, `src/Application/**`.

## Структура TS

```
assets/ts/
  ui.ts / busy.ts          entrypoints
  ui/UiApp.ts              boot главной
  ui/CityModal.ts
  ui/CitySwitchForm.ts
  ui/ResumeTextModal.ts    клик по листу → модалка
  busy/BusyPage.ts
  shared/JobApi.ts
  shared/LiveReload.ts
```

## Ограничения

- Не править руками файлы в `public/build/assets/`.
- Busy: критичный CSS/таймер — inline в PHP (бандл может не успеть); логика статуса — в `BusyPage`.
- Открывать **127.0.0.1:8888**, не `localhost` (прокси часто ломает localhost).

## Связанные docs

- [HTML UI и кеш](html-ui-cache.md)
- [Архитектура MVC](architecture-mvc.md)
- [Текст резюме](resume-text.md)
