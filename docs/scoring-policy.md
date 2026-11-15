# Политика отбора вакансий (канон job_search_2)

**Зачем:** единые правила SKIP/OK для агента и DevAI-промпта.  
**Где править:** этот файл + `config/scoring/profile.json` + `config/scoring/prompts/`.  
**Не источник:** Python scoring в `job_search_ref` (больше не в потоке ScoreTen).

## Поток оценки

Скачанные suitable → pre-gate PHP (EXCLUDE-01/02) → иначе DevAI с локальным промптом → `POST /api/v1/assessments` в Core.

Подробности вызова: [scoring-devai.md](scoring-devai.md).

## Жёсткий SKIP

| Код | Когда |
|--|--|
| `FIT.1c_engineering_required` | Руководство разработкой/внедрением **1С** (не интеграция Bitrix↔1С) |
| `FIT.crm_manager` | CRM-manager / ведение базы, не PM |
| `FIT.commercial_primary` | Sales / BDM / KAM / account / «развитие бизнеса» как **основная** функция (план продаж, квота, закрытие сделок) |
| `DESIRABILITY.sales_managers_leadership.skip` (EXCLUDE-01) | Обязательное управление РОПами / руководителями продаж |
| `DESIRABILITY.leadgen_partnerships_primary.skip` (EXCLUDE-02) | Обязательная лидоген / коммерческое развитие партнёрской сети / фандрайзинг и поиск корпоративных партнёров (в т.ч. НКО) |
| `DESIRABILITY.dedicated_crm_analyst.skip` | Выделенный CRM-аналитик |
| `DESIRABILITY.infrastructure_primary.skip` | Инфра/ops как основная суть |
| `FIT.non_it_product_primary` | PM / «разработка» **физического / не-IT продукта** как суть роли (бытовая техника, аэроигрили, мебель, стройматериалы, станки, одежда и т.п.) — не software/digital delivery |
| International relocation | Работа/релокация за рубеж |

Также SKIP по смыслу: CIO/CTO/CMO, team lead разработки, SA/BA как основная роль, PO/Product без delivery, маркетинг, support-only, junior «начни карьеру PM».

**Не резать** только за: существующих B2B-клиентов; подрядчиков; CRM/процессы продаж как контекст; sales/лидоген как контекст проекта; e-com/ритейл **IT**-delivery; IoT/embedded/MES/ERP-цифровизацию на заводе. Неоднозначное → `maybe`, не авто-SKIP.

### Non-IT product (`FIT.non_it_product_primary`)

| SKIP | Не SKIP |
|--|--|
| «Менеджер проектов (Разработка аэрогрилей / МБТ / мебели)» — продукт = железо/товар | PM IT в компании-производителе (сайт, CRM, ERP, мобильное приложение) |
| Руководство R&D / конструкторским бюро бытовой техники без digital | IoT / firmware / embedded software delivery |
| PM запуска линейки физических SKU, сертификации железа, производства | E-com / marketplace delivery товаров |

### Sales vs product-economy (`FIT.commercial_primary`)

| Не commercial_primary | Commercial_primary / leadgen SKIP |
|--|--|
| CPO / Head of Product / PO с GMV, CAC, LTV, Take Rate, P&L продукта, юнит-экономикой, монетизацией, пилотными продажами платформы | Head of Sales, BDM с личной лидоген, KAM с квотой, директор по продажам |
| Развитие продукта, интеграции, backlog, delivery | Фандрайзинг / поиск корпоративных партнёров / развитие партнёрских инициатив (в т.ч. НКО) |
|  | CPO, у которого **основная** обязанность лично привлекать продавцов/клиентов |

Слабый fit кандидата под Senior CPO → `maybe`/role-fit, **не** через `FIT.commercial_primary`. После DevAI срабатывает PHP `CommercialPrimaryGuard` (снимает ложный sales-SKIP у product-primary). Guard **не** роняет skip→maybe, если остаются другие hard-factors (`DESIRABILITY.leadgen_…` и т.п.).

## Можно в шортлист

Delivery / TPM / PM IT / Scrum (delivery) / release при IT/product/web; интеграции CRM/ERP; B2B, e-com/web/mobile delivery.
