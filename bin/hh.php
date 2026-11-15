#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Единый CLI приложения (тонкий entrypoint → JobSearch\Presentation\Cli\HhCli).
 *
 * Usage:
 *   php bin/hh.php <command> [args…]
 *   php bin/hh.php help
 *
 * Commands:
 *   me                         GET /me (JSON)
 *   login                      browser login → storageState
 *   city                       текущий город сессии
 *   set-city <Город>           сменить город (JSON)
 *   resumes                    список резюме
 *   resume-text [resumeId]     тексты резюме в FileCache
 *   refresh                    UI-job: account+city+resumes+texts
 *   job-set-city <Город>       UI-job: смена города
 *   vacancies [resumeId]       UI-job: 10 вакансий → Core → сразу DevAI
 *   apply <resumeId> <vacancyExternalId>
 *                              UI-job: отклик на HH через Playwright
 *   apply-consider <resumeId> [recent|all] [limit]
 *                              UI-job: отклики на «Смотрим» (limit=0 — все)
 *   apply-errors               UI-job: повторить открытые ошибки отклика
 *   rehydrate-bot-wall         UI-job: перекачать detail из bot_wall_rehydrate
 *   tg-log [N]                 журнал TG DM (Core Postgres / jsonl)
 *   chats                      UI-job: список чатов HH → Core Postgres
 *   leave-rejections           UI-job: покинуть на HH все чаты-отказы
 *   leave-old                  UI-job: покинуть на HH чаты старше 1 месяца
 *   resume-views               UI-job: просмотры резюме HH → FileCache
 *   schedule                   демон: config/schedule.json (чаты каждые 10 мин)
 *   schedule-once | schedule-status | schedule-stop
 *
 * npm: `npm run hh -- <command> …` или короткие скрипты `hh:me`, `hh:browser:login`, …
 */

use JobSearch\Presentation\Cli\HhCli;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

exit((new HhCli($root))->run($argv));
