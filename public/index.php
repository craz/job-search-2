<?php
declare(strict_types=1);

/**
 * HTML UI: кеш на экране; долгие job — фон (php bin/hh.php …) + poll статуса.
 * Запуск: npm run ui → только http://127.0.0.1:8888/ (JS из /build, без BrowserSync/:5173).
 * HMR (локально без системного прокси): npm run ui:hmr → + Vite :5173.
 */

use JobSearch\Application\Account\GetCurrentUser;
use JobSearch\Application\JobApplication\AppliedByVacancyStore;
use JobSearch\Application\JobApplication\CountTodayApplies;
use JobSearch\Application\JobApplication\ListPage;
use JobSearch\Application\JobApplication\SyncDailyProgress;
use JobSearch\Application\Resume\ResumeViewsStore;
use JobSearch\Application\Vacancy\AssignVacancyResume;
use JobSearch\Application\Vacancy\AppliedHistoryForResume;
use JobSearch\Application\Vacancy\AppliedVacancyIndex;
use JobSearch\Application\Vacancy\ListForResume;
use JobSearch\Application\Vacancy\BotWallRehydrateStore;
use JobSearch\Application\Vacancy\ListBotWallRehydrate;
use JobSearch\Application\Vacancy\VacancyResumeOwnerStore;
use JobSearch\Infrastructure\DevAi\CurlDevAiClient;
use JobSearch\Application\Vacancy\ScoreProgress;
use JobSearch\Application\Vacancy\ScoreTen;
use JobSearch\Application\Vacancy\SyncResumeContext;
use JobSearch\Application\Vacancy\VacancyPage;
use JobSearch\Domain\Account\HhApi;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Scoring\ScoringApi;
use JobSearch\Infrastructure\Cache\Cache;
use JobSearch\Infrastructure\Cache\FileCache;
use JobSearch\Infrastructure\Config\Env;
use JobSearch\Infrastructure\Core\CurlCoreClient;
use JobSearch\Infrastructure\Hh\HhClient;
use JobSearch\Infrastructure\Scoring\LocalDevAiScoring;
use JobSearch\Presentation\Controller\ApplicationsController;
use JobSearch\Presentation\Controller\MeController;
use JobSearch\Presentation\Controller\ProgressController;
use JobSearch\Presentation\Controller\VacancyPageController;
use JobSearch\Presentation\Controller\VacancyQueryController;
use JobSearch\Presentation\Controller\VacancyScoreController;
use JobSearch\Presentation\Controller\VacancyVerdictController;
use JobSearch\Application\Vacancy\SetManualVerdict;
use JobSearch\Application\Chat\ChatHistoryArchive;
use JobSearch\Application\Chat\ChatRejectionArchive;
use JobSearch\Application\Chat\ListChats;
use JobSearch\Application\Chat\ListRejections;
use JobSearch\Application\Company\CompanyExclusionStore;
use JobSearch\Application\Company\CompanyRejectionIndex;
use JobSearch\Application\Company\ListCompanies;
use JobSearch\Application\Company\ListExcludedCompanies;
use JobSearch\Application\Error\AppErrorStore;
use JobSearch\Application\Error\ListAppErrors;
use JobSearch\Application\Error\ReconcileApplyErrorsFromChats;
use JobSearch\Application\Schedule\DescribeSchedule;
use JobSearch\Application\Schedule\ScheduleConfig;
use JobSearch\Application\Schedule\ScheduleStore;
use JobSearch\Presentation\Controller\ChatController;
use JobSearch\Presentation\Controller\CompaniesController;
use JobSearch\Presentation\Controller\BotWallRehydrateController;
use JobSearch\Presentation\Controller\ErrorsController;
use JobSearch\Presentation\Controller\ExcludedCompaniesController;
use JobSearch\Presentation\Controller\RejectionsController;
use JobSearch\Infrastructure\Chat\CoreChatStore;
use JobSearch\Presentation\View\ApplicationsPageView;
use JobSearch\Presentation\View\BotWallRehydratePageView;
use JobSearch\Presentation\View\ChatsPageView;
use JobSearch\Presentation\View\CompaniesPageView;
use JobSearch\Presentation\View\ErrorsPageView;
use JobSearch\Presentation\View\ExcludedCompaniesPageView;
use JobSearch\Presentation\View\HtmlPageView;
use JobSearch\Presentation\View\RejectionsPageView;
use JobSearch\Presentation\View\ScheduleNote;
use JobSearch\Presentation\View\VacanciesPageView;
use JobSearch\Presentation\View\View;
use JobSearch\Presentation\View\ViteAssets;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$config = Env::load($root . '/.env');
$storage = $config->get('HH_STORAGE_STATE')
    ?: ($root . '/data/hh_storage_state.json');
$sessionExists = is_readable($storage);
$cacheDir = $root . '/data/cache';

/** @var Cache $cache */
$cache = new FileCache($cacheDir);
$statusFile = $cacheDir . '/job.status.json';

$wantJson = isset($_GET['format']) && $_GET['format'] === 'json';
$busy = isset($_GET['busy']) ? (string) $_GET['busy'] : '';
$job = isset($_GET['job']) ? (string) $_GET['job'] : '';

$vite = new ViteAssets($root);
$view = new HtmlPageView($vite);
$page = isset($_GET['page']) ? (string) $_GET['page'] : '';

if ($page === 'statistics' && !$wantJson && $busy === '') {
    // Статистика переехала в Grafana (row «Статистика»).
    $grafanaStats = trim((string) ($config->get('GRAFANA_STATS_URL') ?? ''));
    if ($grafanaStats === '') {
        $grafanaStats = 'http://127.0.0.1:3000/d/job-search-2/poisk-raboty?orgId=1&refresh=30s';
    }
    header('Location: ' . $grafanaStats, true, 302);
    exit;
}

if ($page === 'applications' && !$wantJson && $busy === '') {
    $appsView = new ApplicationsPageView();
    try {
        /** @var CoreApi $coreApps */
        $coreApps = CurlCoreClient::fromConfig($config);
        $payload = (new ApplicationsController(new ListPage($coreApps)))->listPage(
            isset($_GET['bucket']) ? (string) $_GET['bucket'] : null,
        );
        $appsView->render($payload);
    } catch (Throwable $e) {
        $appsView->render([
            'error' => $e->getMessage(),
            'total' => 0,
            'counts' => ['waiting' => 0, 'in_process' => 0, 'completed' => 0, 'unknown' => 0],
            'items' => [],
            'bucket' => isset($_GET['bucket']) ? (string) $_GET['bucket'] : 'all',
        ]);
    }
    exit;
}

if ($page === 'chats' && !$wantJson && $busy === '') {
    $chatsView = new ChatsPageView($vite);
    $flash = isset($_GET['flash']) ? (string) $_GET['flash'] : '';
    $flashOk = !isset($_GET['ok']) || $_GET['ok'] === '1';
    $chatQuery = trim((string) ($_GET['q'] ?? ''));
    $chatFilter = trim((string) ($_GET['filter'] ?? 'all'));
    try {
        /** @var CoreApi $coreChats */
        $coreChats = CurlCoreClient::fromConfig($config);
        $store = new CoreChatStore($coreChats);
        $appliedResumes = new AppliedByVacancyStore($cache);
        $payload = (new ChatController(
            new ListChats($store, $appliedResumes, new ChatHistoryArchive($cache)),
        ))->listPage($chatQuery, $chatFilter);
        $payload['flash'] = $flash;
        $payload['flash_ok'] = $flashOk;
        $payload['schedule'] = (new DescribeSchedule(
            new ScheduleConfig($root . '/config/schedule.json'),
            new ScheduleStore($cache),
        ))->snapshot();
        $chatsView->render($payload);
    } catch (Throwable $e) {
        $chatsView->render([
            'error' => $e->getMessage(),
            'total' => 0,
            'shown' => 0,
            'live_count' => 0,
            'archived_count' => 0,
            'rejection_count' => 0,
            'old_count' => 0,
            'filter' => $chatFilter !== '' ? $chatFilter : 'all',
            'query' => $chatQuery,
            'vacancy_id' => '',
            'synced_at' => '',
            'items' => [],
            'flash' => $flash,
            'flash_ok' => $flashOk,
        ]);
    }
    exit;
}

if ($page === 'rejections' && !$wantJson && $busy === '') {
    $rejView = new RejectionsPageView($vite);
    $flash = isset($_GET['flash']) ? (string) $_GET['flash'] : '';
    $flashOk = !isset($_GET['ok']) || $_GET['ok'] === '1';
    $rejQuery = trim((string) ($_GET['q'] ?? ''));
    try {
        /** @var CoreApi $coreRej */
        $coreRej = CurlCoreClient::fromConfig($config);
        $payload = (new RejectionsController(
            new ListRejections(
                new CoreChatStore($coreRej),
                new AppliedByVacancyStore($cache),
                new ChatRejectionArchive($cache),
            ),
        ))->page($rejQuery);
        $payload['flash'] = $flash;
        $payload['flash_ok'] = $flashOk;
        $rejView->render($payload);
    } catch (Throwable $e) {
        $rejView->render([
            'error' => $e->getMessage(),
            'total_chats' => 0,
            'total' => 0,
            'shown' => 0,
            'query' => $rejQuery,
            'vacancy_id' => '',
            'synced_at' => '',
            'items' => [],
            'flash' => $flash,
            'flash_ok' => $flashOk,
        ]);
    }
    exit;
}

if ($page === 'exclusions' && !$wantJson && $busy === '') {
    $exclView = new ExcludedCompaniesPageView($vite);
    $flash = isset($_GET['flash']) ? (string) $_GET['flash'] : '';
    $flashOk = !isset($_GET['ok']) || $_GET['ok'] === '1';
    $exclStore = new CompanyExclusionStore($cache);
    try {
        /** @var CoreApi $coreExcl */
        $coreExcl = CurlCoreClient::fromConfig($config);
        $exclController = new ExcludedCompaniesController(
            new ListExcludedCompanies(
                $exclStore,
                new ListCompanies(
                    $coreExcl,
                    new CoreChatStore($coreExcl),
                    new AppliedByVacancyStore($cache),
                    new ChatRejectionArchive($cache),
                ),
            ),
        );
        if (isset($_GET['clear']) && (string) $_GET['clear'] === '1') {
            $exclController->clear();
            header('Location: /?page=exclusions&flash=' . rawurlencode('Список очищен.') . '&ok=1');
            exit;
        }
        $seedSber = isset($_GET['seed']) && (string) $_GET['seed'] === 'sber';
        // Первый заход — сразу подтянуть Сберы из справочника.
        if (!$seedSber && $exclStore->count() === 0) {
            $seedSber = true;
        }
        $payload = $exclController->page($seedSber);
        if ($seedSber && $flash === '') {
            $seeded = (int) ($payload['seeded'] ?? 0);
            $flash = $seeded > 0
                ? ('Добавлено Сберов: ' . $seeded . '.')
                : 'Сберы уже в списке (или в справочнике пусто).';
            $flashOk = true;
        }
        $payload['flash'] = $flash;
        $payload['flash_ok'] = $flashOk;
        $exclView->render($payload);
    } catch (Throwable $e) {
        $exclView->render([
            'error' => $e->getMessage(),
            'total' => 0,
            'items' => [],
            'seeded' => 0,
            'flash' => $flash,
            'flash_ok' => $flashOk,
        ]);
    }
    exit;
}

if ($page === 'rehydrate' && !$wantJson && $busy === '') {
    $rehydrateView = new BotWallRehydratePageView($vite);
    $flash = isset($_GET['flash']) ? (string) $_GET['flash'] : '';
    $flashOk = !isset($_GET['ok']) || $_GET['ok'] === '1';
    try {
        /** @var CoreApi $coreRehydrate */
        $coreRehydrate = CurlCoreClient::fromConfig($config);
        $payload = (new BotWallRehydrateController(
            new ListBotWallRehydrate(
                new BotWallRehydrateStore($cache),
                $coreRehydrate,
                new VacancyResumeOwnerStore($cache),
            ),
        ))->page();
        $payload['flash'] = $flash;
        $payload['flash_ok'] = $flashOk;
        $rehydrateView->render($payload);
    } catch (Throwable $e) {
        $rehydrateView->render([
            'error' => $e->getMessage(),
            'count' => 0,
            'items' => [],
            'flash' => $flash,
            'flash_ok' => $flashOk,
        ]);
    }
    exit;
}

if ($page === 'errors' && !$wantJson && $busy === '') {
    $errorsView = new ErrorsPageView($vite);
    $flash = isset($_GET['flash']) ? (string) $_GET['flash'] : '';
    $flashOk = !isset($_GET['ok']) || $_GET['ok'] === '1';
    $errorsFilter = trim((string) ($_GET['filter'] ?? 'open'));
    /** @var CoreApi $coreErrors */
    $coreErrors = CurlCoreClient::fromConfig($config);
    $errorStore = new AppErrorStore($cache);
    $errorsController = new ErrorsController(new ListAppErrors(
        $errorStore,
        new ListForResume($coreErrors),
        new ReconcileApplyErrorsFromChats(
            $errorStore,
            new AppliedByVacancyStore($cache),
            $coreErrors,
        ),
    ));
    if (isset($_GET['clear']) && (string) $_GET['clear'] === '1') {
        $errorsController->clear();
        header('Location: /?page=errors&flash=' . rawurlencode('Журнал очищен.') . '&ok=1');
        exit;
    }
    $resolveId = trim((string) ($_GET['resolve'] ?? ''));
    if ($resolveId !== '') {
        $ok = $errorsController->resolveById($resolveId);
        $filterQ = $errorsFilter !== '' && $errorsFilter !== 'open'
            ? '&filter=' . rawurlencode($errorsFilter)
            : '';
        header(
            'Location: /?page=errors' . $filterQ
            . '&flash=' . rawurlencode($ok ? 'Ошибка закрыта.' : 'Запись не найдена.')
            . '&ok=' . ($ok ? '1' : '0'),
        );
        exit;
    }
    $skipId = trim((string) ($_GET['skip'] ?? ''));
    if ($skipId !== '') {
        $ok = $errorsController->skipById($skipId);
        header(
            'Location: /?page=errors&filter=skipped'
            . '&flash=' . rawurlencode($ok ? 'Помечено как скип.' : 'Запись не найдена.')
            . '&ok=' . ($ok ? '1' : '0'),
        );
        exit;
    }
    try {
        $payload = $errorsController->page($errorsFilter);
        $payload['flash'] = $flash;
        $payload['flash_ok'] = $flashOk;
        $errorsView->render($payload);
    } catch (Throwable $e) {
        $errorsView->render([
            'error' => $e->getMessage(),
            'total' => 0,
            'filtered' => 0,
            'retryable' => 0,
            'filter' => 'open',
            'counts' => [],
            'items' => [],
            'flash' => $flash,
            'flash_ok' => $flashOk,
        ]);
    }
    exit;
}

if ($page === 'companies' && !$wantJson && $busy === '') {
    $companiesView = new CompaniesPageView($vite);
    $companyFilter = trim((string) ($_GET['filter'] ?? 'all'));
    $companyQuery = trim((string) ($_GET['q'] ?? ''));
    try {
        /** @var CoreApi $coreCompanies */
        $coreCompanies = CurlCoreClient::fromConfig($config);
        $payload = (new CompaniesController(
            new ListCompanies(
                $coreCompanies,
                new CoreChatStore($coreCompanies),
                new AppliedByVacancyStore($cache),
                new ChatRejectionArchive($cache),
            ),
        ))->page($companyFilter, $companyQuery);
        $companiesView->render($payload);
    } catch (Throwable $e) {
        $companiesView->render([
            'error' => $e->getMessage(),
            'total' => 0,
            'rejected_count' => 0,
            'shown' => 0,
            'filter' => $companyFilter !== '' ? $companyFilter : 'all',
            'query' => $companyQuery,
            'items' => [],
        ]);
    }
    exit;
}

if ($page === 'vacancies' && !$wantJson && $busy === '') {
    $vacanciesView = new VacanciesPageView($vite);
    $resumeId = trim((string) ($_GET['resume'] ?? ''));
    $filter = trim((string) ($_GET['filter'] ?? 'all'));
    $selectedId = trim((string) ($_GET['id'] ?? ''));
    $scope = trim((string) ($_GET['scope'] ?? 'recent'));
    $watchVerdict = isset($_GET['watch']) && (string) $_GET['watch'] === '1';
    $forceRescore = isset($_GET['rescore']) && (string) $_GET['rescore'] === '1';
    $scoreVacancyId = trim((string) ($_GET['score'] ?? ''));
    if ($scoreVacancyId === '1') {
        $scoreVacancyId = $selectedId;
    }
    if ($forceRescore && $scoreVacancyId === '' && $selectedId !== '') {
        $scoreVacancyId = $selectedId;
    }
    if ($scoreVacancyId !== '' && $resumeId !== '') {
        try {
            /** @var CoreApi $coreScoreOne */
            $coreScoreOne = CurlCoreClient::fromConfig($config);
            /** @var ScoringApi $scoringOne */
            $scoringOne = LocalDevAiScoring::fromConfig($config, $coreScoreOne, $root);
            $scoreResult = (new VacancyScoreController(
                new ScoreTen(
                    $coreScoreOne,
                    $scoringOne,
                    new SyncResumeContext($coreScoreOne, $cache),
                ),
            ))->forVacancy($resumeId, $scoreVacancyId, $forceRescore);
            $back = [
                'page' => 'vacancies',
                'resume' => $resumeId,
                'id' => $scoreVacancyId,
                'flash' => (string) ($scoreResult['message'] ?? 'Готово.'),
                'ok' => !empty($scoreResult['ok']) ? '1' : '0',
            ];
            if (!empty($scoreResult['ok'])) {
                $back['watch'] = '1';
            }
            if ($filter !== '' && $filter !== 'all') {
                $back['filter'] = $filter;
            }
            if ($scope === 'all') {
                $back['scope'] = 'all';
            }
            header('Location: /?' . http_build_query($back));
            exit;
        } catch (Throwable $e) {
            $back = [
                'page' => 'vacancies',
                'resume' => $resumeId,
                'flash' => $e->getMessage(),
                'ok' => '0',
            ];
            if ($selectedId !== '') {
                $back['id'] = $selectedId;
            }
            header('Location: /?' . http_build_query($back));
            exit;
        }
    }
    $resumeTitle = '';
    $resumeOptions = [];
    $resumesEntryForTitle = $cache->get('resumes');
    if ($resumesEntryForTitle !== null && is_array($resumesEntryForTitle->payload)) {
        $resumeItems = is_array($resumesEntryForTitle->payload['items'] ?? null)
            ? $resumesEntryForTitle->payload['items']
            : [];
        foreach ($resumeItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rid = trim((string) ($row['external_id'] ?? ''));
            if ($rid === '') {
                continue;
            }
            $rtitle = trim((string) ($row['title'] ?? ''));
            $resumeOptions[] = ['id' => $rid, 'title' => $rtitle !== '' ? $rtitle : $rid];
            if ($rid === $resumeId) {
                $resumeTitle = $rtitle;
            }
        }
    }
    $flash = isset($_GET['flash']) ? (string) $_GET['flash'] : '';
    $flashOk = !isset($_GET['ok']) || $_GET['ok'] === '1';
    $vacancyQuery = trim((string) ($_GET['q'] ?? ''));
    try {
        /** @var CoreApi $coreVacPage */
        $coreVacPage = CurlCoreClient::fromConfig($config);
        $appliedStore = new AppliedByVacancyStore($cache);
        $resumeViewsStore = new ResumeViewsStore($cache);
        $ownerStore = new VacancyResumeOwnerStore($cache);
        $assignOwner = new AssignVacancyResume(
            $ownerStore,
            CurlDevAiClient::fromConfig($config),
            trim((string) ($config->get('DEVAI_MODEL', 'devai/auto') ?? 'devai/auto')) ?: 'devai/auto',
            $root . '/config/scoring/prompts/assign_vacancy_resume.txt',
            $cache,
            $coreVacPage,
        );
        $vacancyErrorStore = new AppErrorStore($cache);
        $payload = (new VacancyPageController(
            new VacancyPage(
                new ListForResume($coreVacPage),
                new AppliedVacancyIndex($coreVacPage, $appliedStore),
                new AppliedHistoryForResume($coreVacPage, $appliedStore),
                $resumeViewsStore,
                $ownerStore,
                $assignOwner,
                new CompanyRejectionIndex(
                    new CoreChatStore($coreVacPage),
                    new ChatRejectionArchive($cache),
                ),
                new CompanyExclusionStore($cache),
                $vacancyErrorStore,
                new ReconcileApplyErrorsFromChats(
                    $vacancyErrorStore,
                    $appliedStore,
                    $coreVacPage,
                ),
            ),
        ))->page($resumeId, $resumeTitle, $filter, $selectedId, $scope, $resumeOptions, $vacancyQuery);
        $payload['flash'] = $flash;
        $payload['flash_ok'] = $flashOk;
        $payload['watch'] = $watchVerdict;
        $payload['schedule'] = (new DescribeSchedule(
            new ScheduleConfig($root . '/config/schedule.json'),
            new ScheduleStore($cache),
        ))->snapshot();
        $vacanciesView->render($payload);
    } catch (Throwable $e) {
        $vacanciesView->render([
            'error' => $e->getMessage(),
            'flash' => $flash,
            'flash_ok' => $flashOk,
            'watch' => $watchVerdict,
            'schedule' => [],
            'resume_id' => $resumeId,
            'resume_title' => $resumeTitle,
            'resumes' => $resumeOptions,
            'scope' => $scope === 'all' ? 'all' : 'recent',
            'recent_days' => 3,
            'recent_count' => 0,
            'total_all' => 0,
            'filter' => $filter !== '' ? $filter : 'all',
            'counts' => [
                'all' => 0,
                'consider' => 0,
                'sent' => 0,
                'errors' => 0,
                'skip' => 0,
                'pending' => 0,
                'viewed' => 0,
                'rejected' => 0,
                'duplicates' => 0,
            ],
            'items' => [],
            'selected' => null,
        ]);
    }
    exit;
}

if ($busy !== '' && !$wantJson) {
    // Стартуем job на сервере сразу — не ждём, пока в браузере загрузится/выполнится JS
    // (прокси / type=module / старый BrowserSync часто ломают скрипт → вечный «Запуск…»).
    $started = true;
    if ($busy === 'refresh' || $busy === 'load') {
        $started = startBackgroundJob($root, $cacheDir, 'refresh');
    } elseif ($busy === 'set_city') {
        $cityName = trim((string) ($_GET['city'] ?? ''));
        $started = $cityName !== ''
            ? startBackgroundJob($root, $cacheDir, 'set_city', $cityName)
            : false;
    } elseif ($busy === 'vacancies') {
        $resumeId = trim((string) ($_GET['resume'] ?? ''));
        $started = startBackgroundJob($root, $cacheDir, 'vacancies', '', $resumeId);
    } elseif ($busy === 'apply') {
        $resumeId = trim((string) ($_GET['resume'] ?? ''));
        $vacancyExternalId = trim((string) ($_GET['vacancy'] ?? ''));
        $vacancyTitle = trim((string) ($_GET['title'] ?? ''));
        $started = ($resumeId !== '' && $vacancyExternalId !== '')
            ? startBackgroundJob($root, $cacheDir, 'apply', '', $resumeId, $vacancyExternalId, 'recent', $vacancyTitle)
            : false;
    } elseif ($busy === 'apply_consider') {
        $resumeId = trim((string) ($_GET['resume'] ?? ''));
        $scope = trim((string) ($_GET['scope'] ?? 'recent'));
        $limit = max(0, min(50, (int) ($_GET['limit'] ?? 0)));
        $started = $resumeId !== ''
            ? startBackgroundJob($root, $cacheDir, 'apply_consider', '', $resumeId, '', $scope, '', $limit)
            : false;
    } elseif ($busy === 'apply_errors') {
        $started = startBackgroundJob($root, $cacheDir, 'apply_errors');
    } elseif ($busy === 'rehydrate_bot_wall') {
        $started = startBackgroundJob($root, $cacheDir, 'rehydrate_bot_wall');
    } elseif ($busy === 'chats') {
        $started = startBackgroundJob($root, $cacheDir, 'chats');
    } elseif ($busy === 'leave_rejections') {
        $started = startBackgroundJob($root, $cacheDir, 'leave_rejections');
    } elseif ($busy === 'leave_old') {
        $started = startBackgroundJob($root, $cacheDir, 'leave_old');
    } elseif ($busy === 'resume_views') {
        $started = startBackgroundJob($root, $cacheDir, 'resume_views');
    }
    if (!$started) {
        $snap = readJobStatus($statusFile);
        $cur = trim((string) ($snap['message'] ?? 'другое задание'));
        $return = trim((string) ($_GET['return'] ?? ''));
        $target = match ($return) {
            'errors' => '/?page=errors',
            'vacancies' => '/?page=vacancies',
            'chats' => '/?page=chats',
            'rehydrate' => '/?page=rehydrate',
            default => '/',
        };
        $msg = 'Уже выполняется другое задание — это не запускал. Сейчас: ' . $cur;
        header('Location: ' . $target
            . (str_contains($target, '?') ? '&' : '?')
            . 'flash=' . rawurlencode($msg) . '&ok=0');
        exit;
    }
    $view->renderBusy($busy);
    exit;
}

if ($wantJson && $job === 'status') {
    respondJson(readJobStatus($statusFile));
}

if ($wantJson && $job === 'schedule_status') {
    $snap = (new DescribeSchedule(
        new ScheduleConfig($root . '/config/schedule.json'),
        new ScheduleStore($cache),
    ))->snapshot();
    $taskId = trim((string) ($_GET['task'] ?? 'chats'));
    $note = match ($taskId) {
        'resume_views' => ScheduleNote::resumeViews($snap),
        default => ScheduleNote::forTask($snap, $taskId !== '' ? $taskId : 'chats'),
    };
    respondJson([
        'ok' => true,
        'running' => !empty($snap['running']),
        'task' => $taskId !== '' ? $taskId : 'chats',
        'note' => $note,
        'schedule' => $snap,
    ]);
}

if ($wantJson && ($job === 'load' || $job === 'refresh')) {
    startBackgroundJob($root, $cacheDir, 'refresh');
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => 'Обновление запущено в фоне.',
    ]);
}

if ($wantJson && $job === 'set_city') {
    $cityName = trim((string) ($_GET['city'] ?? $_POST['city'] ?? ''));
    if ($cityName === '') {
        respondJson(['ok' => false, 'message' => 'Укажите город.']);
    }
    startBackgroundJob($root, $cacheDir, 'set_city', $cityName);
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => 'Смена города запущена в фоне.',
    ]);
}

if ($wantJson && $job === 'vacancies') {
    $resumeId = trim((string) ($_GET['resume'] ?? ''));
    startBackgroundJob($root, $cacheDir, 'vacancies', '', $resumeId);
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => $resumeId !== ''
            ? 'Скачивание 10 вакансий для резюме запущено в фоне.'
            : 'Скачивание вакансий (по 10 на каждое резюме) запущено в фоне.',
    ]);
}

if ($wantJson && $job === 'apply') {
    $resumeId = trim((string) ($_GET['resume'] ?? ''));
    $vacancyExternalId = trim((string) ($_GET['vacancy'] ?? ''));
    $vacancyTitle = trim((string) ($_GET['title'] ?? ''));
    if ($resumeId === '' || $vacancyExternalId === '') {
        respondJson(['ok' => false, 'message' => 'Укажите resume и vacancy.']);
    }
    if (!startBackgroundJob($root, $cacheDir, 'apply', '', $resumeId, $vacancyExternalId, 'recent', $vacancyTitle)) {
        respondJson([
            'ok' => false,
            'started' => false,
            'busy' => true,
            'message' => 'Уже выполняется другое задание — отклик не запущен.',
            'status' => readJobStatus($statusFile),
        ]);
    }
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => 'Отклик на HH запущен в фоне.',
    ]);
}

if ($wantJson && $job === 'apply_consider') {
    $resumeId = trim((string) ($_GET['resume'] ?? ''));
    $scope = trim((string) ($_GET['scope'] ?? 'recent'));
    $limit = max(0, min(50, (int) ($_GET['limit'] ?? 0)));
    if ($resumeId === '') {
        respondJson(['ok' => false, 'message' => 'Укажите resume.']);
    }
    if (!startBackgroundJob($root, $cacheDir, 'apply_consider', '', $resumeId, '', $scope, '', $limit)) {
        respondJson([
            'ok' => false,
            'started' => false,
            'busy' => true,
            'message' => 'Уже выполняется другое задание — пакетный отклик не запущен.',
            'status' => readJobStatus($statusFile),
        ]);
    }
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => $limit > 0
            ? sprintf('Пакетный отклик на «Смотрим» (до %d) запущен в фоне.', $limit)
            : 'Пакетный отклик на «Смотрим» запущен в фоне.',
    ]);
}

if ($wantJson && $job === 'apply_errors') {
    if (!startBackgroundJob($root, $cacheDir, 'apply_errors')) {
        respondJson([
            'ok' => false,
            'started' => false,
            'busy' => true,
            'message' => 'Уже выполняется другое задание — повтор ошибок не запущен.',
            'status' => readJobStatus($statusFile),
        ]);
    }
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => 'Повтор откликов из журнала ошибок запущен в фоне.',
    ]);
}

if ($wantJson && $job === 'rehydrate_bot_wall') {
    if (!startBackgroundJob($root, $cacheDir, 'rehydrate_bot_wall')) {
        respondJson([
            'ok' => false,
            'started' => false,
            'busy' => true,
            'message' => 'Уже выполняется другое задание — перекачка не запущена.',
            'status' => readJobStatus($statusFile),
        ]);
    }
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => 'Перекачка bot-wall запущена в фоне.',
    ]);
}

if ($wantJson && $job === 'chats') {
    startBackgroundJob($root, $cacheDir, 'chats');
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => 'Скачивание чатов HH запущено в фоне.',
    ]);
}

if ($wantJson && $job === 'leave_rejections') {
    startBackgroundJob($root, $cacheDir, 'leave_rejections');
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => 'Покидание чатов-отказов запущено в фоне.',
    ]);
}

if ($wantJson && $job === 'leave_old') {
    startBackgroundJob($root, $cacheDir, 'leave_old');
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => 'Покидание чатов старше месяца запущено в фоне.',
    ]);
}

if ($wantJson && $job === 'resume_views') {
    startBackgroundJob($root, $cacheDir, 'resume_views');
    respondJson([
        'ok' => true,
        'started' => true,
        'message' => 'Скачивание просмотров резюме запущено в фоне.',
    ]);
}

if ($wantJson && $job === 'vacancies_for_resume') {
    $resumeId = trim((string) ($_GET['resume'] ?? ''));
    if ($resumeId === '') {
        respondJson(['ok' => false, 'message' => 'Укажите resume.', 'items' => []]);
    }
    try {
        /** @var CoreApi $core */
        $core = CurlCoreClient::fromConfig($config);
        $payload = (new VacancyQueryController(new ListForResume($core)))->listForResume($resumeId);
        respondJson(['ok' => true] + $payload);
    } catch (Throwable $e) {
        respondJson([
            'ok' => false,
            'message' => $e->getMessage(),
            'resume_id' => $resumeId,
            'search_run_id' => null,
            'count' => 0,
            'items' => [],
        ]);
    }
}

if ($wantJson && $job === 'score_ten') {
    $resumeId = trim((string) ($_GET['resume'] ?? ''));
    try {
        /** @var CoreApi $coreScore */
        $coreScore = CurlCoreClient::fromConfig($config);
        /** @var ScoringApi $scoring */
        $scoring = LocalDevAiScoring::fromConfig($config, $coreScore, $root);
        $listForScore = new ListForResume($coreScore);
        $controller = new VacancyScoreController(
            new ScoreTen(
                $coreScore,
                $scoring,
                new SyncResumeContext($coreScore, $cache),
            ),
            new ScoreProgress($listForScore),
        );
        if ($resumeId === '') {
            respondJson(['ok' => false, 'enqueued' => 0, 'message' => 'Укажите resume.']);
        }
        $result = $controller->forResume($resumeId, 10);
        $result['progress'] = $controller->progressForResume($resumeId);
        respondJson($result);
    } catch (Throwable $e) {
        respondJson(['ok' => false, 'enqueued' => 0, 'message' => $e->getMessage()]);
    }
}

if ($wantJson && $job === 'score_one') {
    $resumeId = trim((string) ($_GET['resume'] ?? ''));
    $vacancyId = trim((string) ($_GET['vacancy'] ?? $_GET['id'] ?? ''));
    $force = isset($_GET['force']) && (string) $_GET['force'] === '1';
    try {
        /** @var CoreApi $coreScoreOneJson */
        $coreScoreOneJson = CurlCoreClient::fromConfig($config);
        $controller = new VacancyScoreController(
            new ScoreTen(
                $coreScoreOneJson,
                LocalDevAiScoring::fromConfig($config, $coreScoreOneJson, $root),
                new SyncResumeContext($coreScoreOneJson, $cache),
            ),
        );
        respondJson($controller->forVacancy($resumeId, $vacancyId, $force));
    } catch (Throwable $e) {
        respondJson(['ok' => false, 'enqueued' => 0, 'message' => $e->getMessage()]);
    }
}

if ($wantJson && $job === 'set_verdict') {
    $vacancyId = trim((string) ($_GET['vacancy'] ?? $_GET['id'] ?? ''));
    $verdict = trim((string) ($_GET['verdict'] ?? ''));
    $reason = trim((string) ($_GET['reason'] ?? ''));
    try {
        /** @var CoreApi $coreVerdictSet */
        $coreVerdictSet = CurlCoreClient::fromConfig($config);
        respondJson(
            (new VacancyVerdictController(new SetManualVerdict($coreVerdictSet)))
                ->set($vacancyId, $verdict, $reason),
        );
    } catch (Throwable $e) {
        respondJson(['ok' => false, 'message' => $e->getMessage(), 'verdict' => $verdict, 'vacancy_id' => $vacancyId]);
    }
}

if ($wantJson && $job === 'vacancy_verdict') {
    $vacancyId = trim((string) ($_GET['vacancy'] ?? $_GET['id'] ?? ''));
    if ($vacancyId === '') {
        respondJson(['ok' => false, 'pending' => true, 'message' => 'Укажите vacancy.']);
    }
    try {
        /** @var CoreApi $coreVerdict */
        $coreVerdict = CurlCoreClient::fromConfig($config);
        $list = $coreVerdict->listAssessments($vacancyId, 1);
        $row = $list['items'][0] ?? null;
        if (!is_array($row)) {
            respondJson([
                'ok' => true,
                'vacancy_id' => $vacancyId,
                'pending' => true,
                'verdict' => '',
                'reason' => '',
                'action' => '',
            ]);
        }
        $verdict = strtolower(trim((string) ($row['verdict'] ?? '')));
        respondJson([
            'ok' => true,
            'vacancy_id' => $vacancyId,
            'pending' => $verdict === '',
            'verdict' => $verdict,
            'reason' => trim((string) ($row['reason'] ?? '')),
            'action' => trim((string) ($row['action'] ?? '')),
        ]);
    } catch (Throwable $e) {
        respondJson(['ok' => false, 'pending' => true, 'message' => $e->getMessage()]);
    }
}

if ($wantJson && $job === 'score_progress') {
    $resumeId = trim((string) ($_GET['resume'] ?? ''));
    try {
        /** @var CoreApi $coreProgress */
        $coreProgress = CurlCoreClient::fromConfig($config);
        $controller = new VacancyScoreController(
            new ScoreTen(
                $coreProgress,
                LocalDevAiScoring::fromConfig($config, $coreProgress, $root),
                new SyncResumeContext($coreProgress, $cache),
            ),
            new ScoreProgress(new ListForResume($coreProgress)),
        );
        if ($resumeId === '') {
            respondJson(['ok' => false, 'message' => 'Укажите resume.']);
        }
        respondJson($controller->progressForResume($resumeId));
    } catch (Throwable $e) {
        respondJson(['ok' => false, 'message' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'set_city') {
        $cityName = trim((string) ($_POST['city'] ?? ''));
        if ($cityName === '') {
            jsonOrRedirect('Укажите город.', false, $wantJson);
        }
        startBackgroundJob($root, $cacheDir, 'set_city', $cityName);
        if ($wantJson) {
            respondJson(['ok' => true, 'started' => true, 'message' => 'Смена города запущена.']);
        }
        header('Location: /?busy=set_city&city=' . rawurlencode($cityName));
        exit;
    }
    jsonOrRedirect('Неизвестное действие.', false, $wantJson);
}

// Старые прямые ссылки → экран ожидания.
if (!$wantJson && (isset($_GET['load']) || isset($_GET['refresh']))) {
    $which = isset($_GET['refresh']) ? 'refresh' : 'load';
    header('Location: /?busy=' . rawurlencode($which));
    exit;
}

$account = null;
$accountError = null;
$accountCachedAt = null;
$accountFromCache = false;

$entry = $cache->get('account');
if ($entry !== null && is_array($entry->payload)) {
    $account = $entry->payload;
    $accountCachedAt = $entry->savedAt;
    $accountFromCache = true;
} else {
    try {
        /** @var HhApi $api */
        $api = HhClient::fromConfig($config);
        $account = (new MeController(new GetCurrentUser($api)))->currentUser()->toArray();
        $cache->set('account', $account);
        $accountCachedAt = time();
        $accountFromCache = false;
    } catch (Throwable $e) {
        $accountError = $e->getMessage();
    }
}

$city = null;
$cityCachedAt = null;
$cityFromCache = false;
$resumes = null;
$resumesCachedAt = null;
$resumesFromCache = false;

$cityEntry = $cache->get('city');
if ($cityEntry !== null && is_array($cityEntry->payload)) {
    $city = $cityEntry->payload;
    $cityCachedAt = $cityEntry->savedAt;
    $cityFromCache = true;
}
$resumesEntry = $cache->get('resumes');
if ($resumesEntry !== null && is_array($resumesEntry->payload)) {
    $resumes = $resumesEntry->payload;
    $resumesCachedAt = $resumesEntry->savedAt;
    $resumesFromCache = true;
}

$resumeTexts = null;
$resumeTextsEntry = $cache->get('resume_texts');
if ($resumeTextsEntry !== null && is_array($resumeTextsEntry->payload)) {
    $resumeTexts = $resumeTextsEntry->payload;
}

/** @var array<string, array{search_run_id: string, count: int, status: string}> $vacanciesByResume */
$vacanciesByResume = [];
$dailyProgress = [
    'day' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d'),
    'goal' => CountTodayApplies::GOAL_PER_RESUME,
    'by_resume' => [],
    'total_today' => 0,
    'batch' => ['skip' => 0, 'consider' => 0, 'unscored' => 0],
    'persisted' => false,
    'from_db' => false,
];
try {
    /** @var CoreApi $coreForVacancies */
    $coreForVacancies = CurlCoreClient::fromConfig($config);
    $vacanciesByResume = (new VacancyQueryController(new ListForResume($coreForVacancies)))->summaryByResume();
    $dailyProgress = (new ProgressController(
        new SyncDailyProgress($coreForVacancies, new CountTodayApplies($coreForVacancies)),
    ))->today($vacanciesByResume);
} catch (Throwable) {
    $vacanciesByResume = [];
}

$flash = isset($_GET['flash']) ? (string) $_GET['flash'] : null;
$flashOk = !isset($_GET['ok']) || $_GET['ok'] === '1';

/** @var list<string> $scoringWatchIds */
$scoringWatchIds = [];
$scoringWatchEntry = $cache->get('scoring_watch');
if ($scoringWatchEntry !== null && is_array($scoringWatchEntry->payload)) {
    $rawIds = $scoringWatchEntry->payload['resume_ids'] ?? [];
    $at = (int) ($scoringWatchEntry->payload['at'] ?? 0);
    if (is_array($rawIds) && $at > 0 && time() - $at < 900) {
        foreach ($rawIds as $rid) {
            if (is_string($rid) && trim($rid) !== '') {
                $scoringWatchIds[] = trim($rid);
            }
        }
    }
}

$homeSchedule = [];
try {
    $homeSchedule = (new DescribeSchedule(
        new ScheduleConfig($root . '/config/schedule.json'),
        new ScheduleStore($cache),
    ))->snapshot();
} catch (Throwable) {
    $homeSchedule = [];
}

/** @var View $view */
$view->render([
    'account' => $account,
    'account_error' => $accountError,
    'account_cached_at' => $accountCachedAt,
    'account_from_cache' => $accountFromCache,
    'session_exists' => $sessionExists,
    'has_browser_data' => $city !== null || $resumes !== null,
    'city' => $city,
    'city_error' => null,
    'city_cached_at' => $cityCachedAt,
    'city_from_cache' => $cityFromCache,
    'resumes' => $resumes,
    'resumes_error' => null,
    'resumes_cached_at' => $resumesCachedAt,
    'resumes_from_cache' => $resumesFromCache,
    'resume_texts' => $resumeTexts,
    'vacancies_by_resume' => $vacanciesByResume,
    'daily_progress' => $dailyProgress,
    'scoring_watch_ids' => $scoringWatchIds,
    'schedule' => $homeSchedule,
    'flash' => $flash,
    'flash_ok' => $flashOk,
]);

/** @param array<string, mixed> $payload */
function respondJson(array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function jsonOrRedirect(string $message, bool $ok, bool $wantJson): never
{
    if ($wantJson) {
        respondJson(['ok' => $ok, 'message' => $message]);
    }
    $query = http_build_query([
        'flash' => $message,
        'ok' => $ok ? '1' : '0',
    ]);
    header('Location: /?' . $query);
    exit;
}

/** @return array<string, mixed> */
function readJobStatus(string $statusFile): array
{
    if (!is_readable($statusFile)) {
        return [
            'state' => 'idle',
            'ok' => null,
            'message' => 'Нет активного задания.',
        ];
    }
    $raw = file_get_contents($statusFile);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return [
            'state' => 'idle',
            'ok' => null,
            'message' => 'Статус повреждён.',
        ];
    }
    return $data;
}

/** Уже крутится UI-job (живой pid или свежий running без pid). Не убиваем — только детект. */
function uiJobIsBusy(string $statusFile): bool
{
    $data = readJobStatus($statusFile);
    if (strtolower(trim((string) ($data['state'] ?? ''))) !== 'running') {
        return false;
    }
    $updated = (int) ($data['updated_at'] ?? 0);
    if ($updated > 0 && (time() - $updated) > 1800) {
        return false;
    }
    $pid = (int) ($data['pid'] ?? 0);
    if ($pid > 0) {
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : true;
    }

    // queued / ещё без pid — считаем busy, если статус свежий (<2 мин).
    return $updated <= 0 || (time() - $updated) < 120;
}

/**
 * @return bool true = стартовали; false = уже занято (другое задание), ничего не убили
 */
function startBackgroundJob(
    string $root,
    string $cacheDir,
    string $kind,
    string $city = '',
    string $resumeId = '',
    string $vacancyExternalId = '',
    string $scope = 'recent',
    string $vacancyTitle = '',
    int $limit = 0,
): bool {
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('Не удалось создать data/cache');
    }

    $statusFile = $cacheDir . '/job.status.json';
    if (uiJobIsBusy($statusFile)) {
        return false;
    }

    $logFile = $cacheDir . '/job.log';
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';

    $hh = escapeshellarg($php) . ' ' . escapeshellarg($root . '/bin/hh.php');
    if ($kind === 'set_city') {
        $cmd = $hh . ' job-set-city ' . escapeshellarg($city);
    } elseif ($kind === 'vacancies') {
        $cmd = $hh . ' vacancies';
        if ($resumeId !== '') {
            $cmd .= ' ' . escapeshellarg($resumeId);
        }
    } elseif ($kind === 'apply') {
        $cmd = $hh . ' apply ' . escapeshellarg($resumeId) . ' ' . escapeshellarg($vacancyExternalId);
        if (trim($vacancyTitle) !== '') {
            $cmd .= ' ' . escapeshellarg($vacancyTitle);
        }
    } elseif ($kind === 'apply_consider') {
        $cmd = $hh . ' apply-consider ' . escapeshellarg($resumeId)
            . ' ' . escapeshellarg($scope === 'all' ? 'all' : 'recent');
        $limit = max(0, min(50, $limit));
        if ($limit > 0) {
            $cmd .= ' ' . escapeshellarg((string) $limit);
        }
    } elseif ($kind === 'apply_errors') {
        $cmd = $hh . ' apply-errors';
    } elseif ($kind === 'rehydrate_bot_wall') {
        $cmd = $hh . ' rehydrate-bot-wall';
    } elseif ($kind === 'chats') {
        $cmd = $hh . ' chats';
    } elseif ($kind === 'leave_rejections') {
        $cmd = $hh . ' leave-rejections';
    } elseif ($kind === 'leave_old') {
        $cmd = $hh . ' leave-old';
    } elseif ($kind === 'resume_views') {
        $cmd = $hh . ' resume-views';
    } else {
        $cmd = $hh . ' refresh';
    }

    // Сразу пишем running — poll не увидит пустоту до старта CLI.
    file_put_contents(
        $statusFile,
        json_encode([
            'state' => 'running',
            'step' => 'queued',
            'job' => $kind,
            'ok' => null,
            'message' => 'Задание в очереди…',
            'updated_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n",
    );

    $full = 'nohup ' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
    exec($full);

    return true;
}
