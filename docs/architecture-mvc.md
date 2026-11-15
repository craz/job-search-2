# Архитектура MVC + Onion

**Зачем:** MVC снаружи (запрос/ответ), Onion внутри (зависимости к ядру).  
**Что делает:** Controller → UseCase → Domain-порт; Infrastructure реализует порты; View только рисует.  
**Где код:** `src/Presentation`, `src/Application`, `src/Domain`, `src/Infrastructure`.

## Слои

| Слой | Роль | Примеры |
|--|--|--|
| **Presentation** | HTTP/CLI + View; тонкий Controller | `MeController`, `HtmlPageView`, `HhCli` |
| **Application** | Сценарий без HTML/curl | `GetCurrentUser`, `ListMine`, `FetchSuitable`, `ListPage` |
| **Domain** | Сущности и порты | `Me`, `ResumeList`, `HhApi`, `CoreApi`, `ApplicantPages` |
| **Infrastructure** | Реализации портов | `HhClient`, `ApplicantPages`, `CurlCoreClient`, `Env` |

Зависимости **только внутрь**: Presentation → Application → Domain; Infrastructure → Domain.

Entrypoint / `HhCli` — bootstrap + wiring + коды выхода, без бизнес-логики.

## Programming to an Interface

Публичные зависимости типизируют контрактом Domain/Infrastructure (`HhApi`, `CoreApi`, `BrowserGateway`, `HttpClient`, `Config`, `Cache`, `View`). Concrete создают на краю (`HhClient::fromConfig`, `PlaywrightBrowserGateway::fromConfig`).

Без свалки `Interfaces/` и без DI-контейнера.

## Карта каталогов

```
bin/hh.php     единый CLI → Presentation\Cli\HhCli
public/        HTML UI (index.php, style.css, build/)
src/
  Presentation/  Controller, View, Cli
  Application/   Use cases по зонам
  Domain/        Entities + ports
  Infrastructure/ Hh, Applicant, Core, Http, Browser, Cache, Config
assets/ts/     TypeScript UI (Vite)
data/          storageState, cache (не в git)
docs/          эта документация
```

Домен «отклики» = `JobApplication` (не путать со слоем `Application/`).

## Типичный вызов

```php
/** @var HhApi $api */
$api = HhClient::fromConfig(Env::load(__DIR__ . '/../.env'));
$me = (new MeController(new GetCurrentUser($api)))->currentUser();
(new JsonStdoutView())->render($me->toArray());
```

## Canvas

Пошаговая схема: Cursor Canvas `architecture.canvas.tsx` — Onion, `/me`, интерфейсы, wiring, browser, HTML UI, отклики, вакансии. Обновлять при смене слоёв, публичных контрактов или wiring.

## Связанные docs

- [HH API](hh-api.md)
- [Browser-сессия](browser-session.md)
- [HTML UI и кеш](html-ui-cache.md)
