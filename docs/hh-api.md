# HH API (OAuth)

> **Deprecated для новых сценариев.** `api.hh.ru` / OpenAPI не источник правды: дырявый, много 403, hide/delete/чаты/отклики там не искать. Канон — Playwright + applicant UI / chatik (`ApplicantPages`). См. `.cursor/rules/project.mdc` → «HH: не опираться на официальный API».

**Зачем:** узкий legacy — `GET /me`, где OAuth ещё хватает.  
**Что делает:** `GET /me` через curl + Bearer.  
**Где код:** `Domain/Account/{HhApi,Me}`, `Infrastructure/Hh/HhClient`, `Application/Account/GetCurrentUser`, `Presentation/Controller/MeController`, `php bin/hh.php me`.

## Конфиг (`.env`)

| Ключ | Назначение |
|--|--|
| `HH_ACCESS_TOKEN` | OAuth bearer |
| `HH_USER_AGENT` | обязательный UA для api.hh.ru |
| `HH_PROXY` | опционально, напр. `socks5h://127.0.0.1:2080` |

См. `.env.example`. Файл `.env` не коммитить.

## Поток

```
php bin/hh.php me
  → Env::load → HhClient::fromConfig (HhApi)
  → MeController → GetCurrentUser
  → HhClient::me() → CurlHttpClient GET https://api.hh.ru/me
  → Me → JsonStdoutView
```

## Ограничения

- `/resumes/mine` и `/resumes/{id}` у текущего приложения часто отвечают **403** — список и текст резюме идут через browser UI (см. [browser-session](browser-session.md), [resume-text](resume-text.md)).
- Сеть/TLS/прокси → `HhTransportException`; плохой HTTP/тело → `HhHttpException`.

## CLI

```bash
php bin/hh.php me
# или
npm run hh:me
```

| Exit | Смысл |
|--|--|
| 0 | OK |
| 1 | необработанная ошибка |
| 2 | сеть / транспорт |
| 3 | конфиг / env |
| 4 | HTTP ответ «не ок» (`Me::isOk() === false`) |

## HTTP-клиент

`CurlHttpClient` принимает опциональный proxy string. `HhClient::request` сейчас только `GET`. Заголовки: `Authorization`, `User-Agent`, `HH-User-Agent`, `Accept: application/json`.

Прокси лучше `socks5h://` — стабильнее TLS, чем HTTP CONNECT на том же порту (см. историю проекта).

## Связанные docs

- [Архитектура MVC](architecture-mvc.md)
- [Browser-сессия](browser-session.md)
