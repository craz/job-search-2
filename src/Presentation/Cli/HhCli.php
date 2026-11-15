<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Cli;

use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Infrastructure\Browser\PlaywrightBrowserGateway;
use JobSearch\Infrastructure\Cache\FileCache;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Presentation\Controller\CityController;
use JobSearch\Application\Browser\ReadCity;
use JobSearch\Presentation\Controller\LoginController;
use JobSearch\Application\Browser\LoginSession;
use JobSearch\Presentation\Controller\SetCityController;
use JobSearch\Application\Browser\SwitchCity;
use JobSearch\Presentation\Controller\MeController;
use JobSearch\Application\Account\GetCurrentUser;
use JobSearch\Presentation\Controller\ResumesController;
use JobSearch\Application\Resume\ListMine;
use JobSearch\Presentation\Controller\ResumeTextsController;
use JobSearch\Application\Resume\DownloadTexts;
use JobSearch\Presentation\Controller\VacancyFetchController;
use JobSearch\Application\Vacancy\AssignVacancyResume;
use JobSearch\Application\Vacancy\BotWallRehydrateStore;
use JobSearch\Application\Vacancy\FetchSuitable;
use JobSearch\Application\Vacancy\ListBotWallRehydrate;
use JobSearch\Application\Vacancy\RehydrateBotWallVacancies;
use JobSearch\Application\Vacancy\SuitableRunStatsStore;
use JobSearch\Application\Vacancy\VacancyResumeOwnerStore;
use JobSearch\Presentation\Controller\BotWallRehydrateController;
use JobSearch\Infrastructure\DevAi\CurlDevAiClient;
use JobSearch\Application\Vacancy\ScoreTen;
use JobSearch\Application\Vacancy\SyncResumeContext;
use JobSearch\Presentation\Controller\ApplyController;
use JobSearch\Presentation\Controller\ApplyBatchController;
use JobSearch\Application\JobApplication\ApplyToVacancy;
use JobSearch\Application\JobApplication\ApplyConsiderBatch;
use JobSearch\Application\JobApplication\AppliedByVacancyStore;
use JobSearch\Application\JobApplication\RetryFailedApplies;
use JobSearch\Presentation\Controller\RetryErrorsController;
use JobSearch\Application\Vacancy\AppliedHistoryForResume;
use JobSearch\Application\Vacancy\AppliedVacancyIndex;
use JobSearch\Application\Vacancy\ListForResume;
use JobSearch\Application\Vacancy\VacancyPage;
use JobSearch\Presentation\Controller\ChatController;
use JobSearch\Application\Chat\ChatHistoryArchive;
use JobSearch\Application\Chat\ChatRejectionArchive;
use JobSearch\Application\Chat\LeaveOldChats;
use JobSearch\Application\Chat\LeaveRejectionChats;
use JobSearch\Application\Chat\ListChats;
use JobSearch\Application\Chat\SyncChatList;
use JobSearch\Application\Company\CompanyExclusionStore;
use JobSearch\Application\Company\CompanyRejectionIndex;
use JobSearch\Application\Error\AppErrorStore;
use JobSearch\Application\Error\ReconcileApplyErrorsFromChats;
use JobSearch\Application\Resume\ResumeViewsStore;
use JobSearch\Application\Resume\SyncResumeViews;
use JobSearch\Application\Schedule\DescribeSchedule;
use JobSearch\Application\Schedule\RunScheduledTasks;
use JobSearch\Application\Schedule\ScheduleConfig;
use JobSearch\Application\Schedule\ScheduleDaemon;
use JobSearch\Application\Schedule\ScheduleStore;
use JobSearch\Infrastructure\Chat\CoreChatStore;
use JobSearch\Infrastructure\Applicant\ApplicantPages;
use JobSearch\Infrastructure\Applicant\CoverLetterFiller;
use JobSearch\Infrastructure\Applicant\ScreeningFormFiller;
use JobSearch\Infrastructure\Applicant\ServiceCaptchaSolver;
use JobSearch\Infrastructure\Applicant\TelegramCaptchaSolver;
use JobSearch\Infrastructure\Telegram\TelegramDmClient;
use JobSearch\Infrastructure\Telegram\TelegramDmJournal;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Infrastructure\Core\CurlCoreClient;
use JobSearch\Domain\Scoring\ScoringApi;
use JobSearch\Infrastructure\Scoring\LocalDevAiScoring;
use JobSearch\Infrastructure\Config\Env;
use JobSearch\Domain\Account\HhApi;
use JobSearch\Infrastructure\Hh\HhClient;
use JobSearch\Infrastructure\Hh\HhHttpException;
use JobSearch\Infrastructure\Hh\HhTransportException;
use JobSearch\Domain\Resume\ResumeListKind;
use JobSearch\Presentation\View\JsonStdoutView;

/**
 * Диспетчер команд CLI (`php bin/hh.php <command>`).
 *
 * Команды: me, login, city, set-city, resumes, resume-text,
 * refresh, job-set-city, vacancies, apply, apply-consider, chats,
 * leave-rejections, resume-views (см. help() / docblock bin/hh.php).
 */
final class HhCli
{
    public function __construct(private readonly string $root) {}

    /**
     * Разбор argv и выполнение одной команды; код выхода процесса.
     *
     * @param list<string> $argv полный argv (argv[0]=скрипт, argv[1]=command)
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? '';
        $args = array_slice($argv, 2);

        return match ($command) {
            'me' => $this->me(),
            'login' => $this->login(),
            'city' => $this->city(),
            'set-city' => $this->setCity($args[0] ?? ''),
            'resumes' => $this->resumes(),
            'resume-text' => $this->resumeText($args[0] ?? ''),
            'refresh' => $this->refresh(),
            'job-set-city' => $this->jobSetCity($args[0] ?? ''),
            'vacancies' => $this->vacancies($args[0] ?? ''),
            'assign-reconcile' => $this->assignReconcile($args[0] ?? ''),
            'apply' => $this->apply($args[0] ?? '', $args[1] ?? '', $args[2] ?? ''),
            'apply-consider' => $this->applyConsider(
                $args[0] ?? '',
                $args[1] ?? 'recent',
                $args[2] ?? '0',
            ),
            'apply-errors' => $this->applyErrors(),
            'rehydrate-bot-wall' => $this->rehydrateBotWall(),
            'chats' => $this->chats(),
            'leave-rejections' => $this->leaveRejections(),
            'leave-old' => $this->leaveOld(),
            'resume-views' => $this->resumeViews(),
            'schedule' => $this->scheduleDaemon(),
            'schedule-once' => $this->scheduleOnce(),
            'schedule-status' => $this->scheduleStatus(),
            'schedule-stop' => $this->scheduleStop(),
            'tg-log' => $this->tgLog($args[0] ?? '40'),
            'help', '-h', '--help', '' => $this->help(),
            default => $this->unknown($command),
        };
    }

    private function help(): int
    {
        $text = <<<'TXT'
Usage: php bin/hh.php <command> [args]

Commands:
  me                         GET /me (JSON)
  login                      browser login → storageState
  city                       текущий город сессии
  set-city <Город>           сменить город (JSON)
  resumes                    список резюме
  resume-text [resumeId]     тексты резюме в FileCache
  refresh                    UI-job: account+city+resumes+texts
  job-set-city <Город>       UI-job: смена города
  vacancies [resumeId]       UI-job: новые suitable → Core → DevAI сразу (не стоп по 10)
  assign-reconcile [rebuild] first→rules; rebuild — полная пересборка из Core
  apply <resumeId> <vacancyExternalId>
                             UI-job: отклик на HH через Playwright
  apply-consider <resumeId> [recent|all] [limit]
                             UI-job: отклики на «Смотрим» (limit=0 — все)
  apply-errors               UI-job: повторить открытые ошибки отклика
  rehydrate-bot-wall         UI-job: перекачать detail из очереди bot_wall_rehydrate
  tg-log [N]                 последние N событий TG DM (Core Postgres + jsonl fallback)
  chats                      UI-job: список чатов HH → Core Postgres
  leave-rejections           UI-job: покинуть на HH все чаты-отказы
  leave-old                  UI-job: покинуть на HH чаты старше 1 месяца
  resume-views               UI-job: просмотры резюме HH → FileCache
  schedule                   демон: задачи из config/schedule.json (чаты каждые 10 мин)
  schedule-once              один тик планировщика (для cron)
  schedule-status            JSON: running / last_run задач
  schedule-stop              остановить schedule-демон

TXT;
        fwrite(STDOUT, $text);

        return 0;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "Unknown command: {$command}\n");
        $this->help();

        return 1;
    }

    private function config(): Config
    {
        return Env::load($this->root . '/.env');
    }

    /** ApplicantPages + авто-капча (2captcha) / TG fallback + анкеты + письма из .env. */
    private function applicantPages(?Config $config = null): ApplicantPages
    {
        $config ??= $this->config();
        $cache = new FileCache($this->cacheDir());
        $core = CurlCoreClient::fromConfig($config);
        // Один DM-клиент: общий flock + журнал Core/JSONL (letter/captcha/screening не гоняются).
        $dm = TelegramDmClient::fromConfig($config, $this->root . '/data', $core);
        $shotDir = $this->root . '/data/captcha';
        $tgCaptcha = TelegramCaptchaSolver::fromConfig($config, $shotDir, $dm);
        // Авто 2captcha/rucaptcha → при fail TG fallback.
        $solver = ServiceCaptchaSolver::fromConfig($config, $shotDir, $dm, $tgCaptcha)
            ?? $tgCaptcha;
        $screening = ScreeningFormFiller::fromConfig($config, $this->root, $cache, $dm);
        $coverLetter = CoverLetterFiller::fromConfig(
            $config,
            $this->root,
            $core,
            $cache,
            null,
            $dm,
        );

        return new ApplicantPages($solver, $screening, $coverLetter);
    }

    private function storagePath(Config $config): string
    {
        return $config->get('HH_STORAGE_STATE')
            ?: ($this->root . '/data/hh_storage_state.json');
    }

    private function cacheDir(): string
    {
        return $this->root . '/data/cache';
    }

    private function jobStatus(): JobStatus
    {
        return new JobStatus($this->cacheDir() . '/job.status.json');
    }

    private function scheduleWiring(): array
    {
        $cache = new FileCache($this->cacheDir());
        $store = new ScheduleStore($cache);
        $config = new ScheduleConfig($this->root . '/config/schedule.json');
        $runner = new RunScheduledTasks(
            $this->root,
            $config,
            $store,
            $this->cacheDir() . '/job.status.json',
        );

        return [$config, $store, $runner];
    }

    /** Долгоживущий демон планировщика (блокирует процесс). */
    private function scheduleDaemon(): int
    {
        [$config, $store, $runner] = $this->scheduleWiring();
        unset($config);
        $daemon = new ScheduleDaemon(
            $runner,
            $store,
            $this->cacheDir() . '/schedule.pid',
            $this->cacheDir() . '/schedule.log',
        );

        return $daemon->run();
    }

    private function scheduleOnce(): int
    {
        [, $store, $runner] = $this->scheduleWiring();
        unset($store);
        $results = $runner->tick(forceAll: false);
        (new JsonStdoutView())->render(['ok' => true, 'results' => $results]);

        return 0;
    }

    private function scheduleStatus(): int
    {
        [$config, $store] = $this->scheduleWiring();
        $snap = (new DescribeSchedule($config, $store))->snapshot();
        (new JsonStdoutView())->render($snap);

        return 0;
    }

    private function scheduleStop(): int
    {
        [, $store] = $this->scheduleWiring();
        $state = $store->read();
        $pid = (int) $state['pid'];
        if ($pid <= 0 || !$store->isDaemonAlive()) {
            $store->clearDaemon();
            @unlink($this->cacheDir() . '/schedule.pid');
            (new JsonStdoutView())->render(['ok' => true, 'message' => 'Планировщик не был запущен.']);

            return 0;
        }
        $killed = function_exists('posix_kill') ? @posix_kill($pid, 15) : false;
        if (!$killed) {
            exec('kill -TERM ' . escapeshellarg((string) $pid) . ' 2>/dev/null');
        }
        usleep(200_000);
        if ($store->isDaemonAlive()) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            } else {
                exec('kill -KILL ' . escapeshellarg((string) $pid) . ' 2>/dev/null');
            }
        }
        $store->clearDaemon();
        @unlink($this->cacheDir() . '/schedule.pid');
        (new JsonStdoutView())->render(['ok' => true, 'message' => 'Планировщик остановлен (pid ' . $pid . ').']);

        return 0;
    }

    private function me(): int
    {
        try {
            /** @var HhApi $api */
            $api = HhClient::fromConfig($this->config());
            $me = (new MeController(new GetCurrentUser($api)))->currentUser();
        } catch (HhTransportException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 2;
        } catch (HhHttpException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            if ($e->bodySnippet !== '') {
                fwrite(STDERR, $e->bodySnippet . "\n");
            }

            return 3;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        (new JsonStdoutView())->render($me->toArray());

        return $me->isOk() ? 0 : 4;
    }

    private function login(): int
    {
        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            $timeout = (int) ($config->get('HH_LOGIN_TIMEOUT_SEC', '300') ?? '300');
            /** @var BrowserGateway $browser */
            $browser = new PlaywrightBrowserGateway(
                proxy: $config->get('HH_PROXY', '') ?? '',
                headless: false,
            );
            $result = (new LoginController(new LoginSession($browser, $this->applicantPages())))->loginAndSaveSession(
                storageStatePath: $storage,
                timeoutSeconds: $timeout,
                login: $config->get('HH_LOGIN'),
                password: $config->get('HH_PASSWORD'),
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        (new JsonStdoutView())->render($result->toArray());

        return $result->isOk() ? 0 : 4;
    }

    private function city(): int
    {
        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $city = (new CityController(new ReadCity($browser, $this->applicantPages())))->current($storage);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        (new JsonStdoutView())->render($city->toArray());

        return $city->isOk() ? 0 : 4;
    }

    private function setCity(string $city): int
    {
        $city = trim($city);
        if ($city === '') {
            fwrite(STDERR, "Usage: php bin/hh.php set-city <Город>\n");

            return 1;
        }

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $result = (new SetCityController(new SwitchCity($browser, $this->applicantPages())))->switchTo($storage, $city);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        (new JsonStdoutView())->render($result->toArray());

        return $result->isOk() ? 0 : 4;
    }

    private function resumes(): int
    {
        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $list = (new ResumesController(new ListMine($browser, $this->applicantPages())))->listMine($storage);
            if ($list->isOk()) {
                (new FileCache($this->cacheDir()))->set('resumes', $list->toArray());
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        (new JsonStdoutView())->render($list->toArray());
        if ($list->kind === ResumeListKind::LoginRequired) {
            fwrite(STDERR, "Нет сессии. Запустите: php bin/hh.php login\n");

            return 2;
        }

        return $list->isOk() ? 0 : 4;
    }

    private function resumeText(string $onlyId): int
    {
        $onlyId = trim($onlyId);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            $cache = new FileCache($this->cacheDir());

            $resumesEntry = $cache->get('resumes');
            $items = [];
            if ($resumesEntry !== null && is_array($resumesEntry->payload)) {
                $raw = $resumesEntry->payload['items'] ?? [];
                if (is_array($raw)) {
                    $items = $raw;
                }
            }
            if ($items === []) {
                fwrite(STDERR, "Нет списка резюме в кеше. Сначала: php bin/hh.php refresh\n");

                return 2;
            }

            if ($onlyId !== '') {
                $items = array_values(array_filter(
                    $items,
                    static fn ($row): bool => is_array($row)
                        && (($row['external_id'] ?? '') === $onlyId),
                ));
                if ($items === []) {
                    fwrite(STDERR, "Резюме {$onlyId} не найдено в кеше.\n");

                    return 3;
                }
            }

            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $texts = (new ResumeTextsController(new DownloadTexts($browser, $this->applicantPages())))->downloadMany($storage, $items);
            $payload = [
                'count' => count($texts),
                'items' => array_map(static fn ($t) => $t->toArray(), $texts),
            ];
            $cache->set('resume_texts', $payload);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        (new JsonStdoutView())->render([
            'count' => $payload['count'],
            'items' => array_map(
                static fn (array $row): array => [
                    'external_id' => $row['external_id'],
                    'title' => $row['title'],
                    'text_len' => mb_strlen((string) $row['text']),
                    'text_head' => mb_substr((string) $row['text'], 0, 160),
                ],
                $payload['items'],
            ),
        ]);

        return 0;
    }

    private function refresh(): int
    {
        $status = $this->jobStatus();
        $status->write([
            'state' => 'running',
            'step' => 'start',
            'ok' => null,
            'message' => 'Старт обновления…',
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            $cache = new FileCache($this->cacheDir());

            $status->write([
                'state' => 'running',
                'step' => 'account',
                'ok' => null,
                'message' => 'Читаем /me…',
                'pid' => getmypid(),
            ]);

            /** @var HhApi $api */
            $api = HhClient::fromConfig($config);
            $account = (new MeController(new GetCurrentUser($api)))->currentUser()->toArray();
            $cache->set('account', $account);

            if (!is_readable($storage)) {
                $status->write([
                    'state' => 'done',
                    'step' => 'done',
                    'ok' => false,
                    'message' => 'Нет browser-сессии. php bin/hh.php login',
                    'pid' => getmypid(),
                ]);

                return 2;
            }

            $status->write([
                'state' => 'running',
                'step' => 'city',
                'ok' => null,
                'message' => 'Читаем город (Playwright)…',
                'pid' => getmypid(),
            ]);

            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $city = (new CityController(new ReadCity($browser, $this->applicantPages())))->current($storage)->toArray();
            $cache->set('city', $city);

            $status->write([
                'state' => 'running',
                'step' => 'resumes',
                'ok' => null,
                'message' => 'Читаем резюме (Playwright)…',
                'pid' => getmypid(),
            ]);

            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $resumes = (new ResumesController(new ListMine($browser, $this->applicantPages())))->listMine($storage)->toArray();
            $cache->set('resumes', $resumes);

            $resumeItems = is_array($resumes['items'] ?? null) ? $resumes['items'] : [];
            if ($resumeItems !== []) {
                $status->write([
                    'state' => 'running',
                    'step' => 'resume_texts',
                    'ok' => null,
                    'message' => 'Скачиваем тексты резюме (TXT)…',
                    'pid' => getmypid(),
                ]);

                $browser = PlaywrightBrowserGateway::fromConfig($config);
                $texts = (new ResumeTextsController(new DownloadTexts($browser, $this->applicantPages())))->downloadMany($storage, $resumeItems);
                $cache->set('resume_texts', [
                    'count' => count($texts),
                    'items' => array_map(static fn ($t) => $t->toArray(), $texts),
                ]);
            }

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'ok' => true,
                'message' => 'Данные обновлены.',
                'pid' => getmypid(),
            ]);

            return 0;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    private function chats(): int
    {
        $status = $this->jobStatus();
        $status->write([
            'state' => 'running',
            'step' => 'chats',
            'ok' => null,
            'message' => 'Скачиваю чаты HH…',
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            /** @var CoreApi $core */
            $core = CurlCoreClient::fromConfig($config);
            $store = new CoreChatStore($core);
            $cache = new FileCache($this->cacheDir());
            $appliedResumes = new AppliedByVacancyStore($cache);
            $rejectionArchive = new ChatRejectionArchive($cache);
            $reconcileErrors = new ReconcileApplyErrorsFromChats(
                new AppErrorStore($cache),
                $appliedResumes,
                $core,
            );
            $result = (new ChatController(
                new ListChats($store, $appliedResumes),
                new SyncChatList(
                    $browser,
                    $this->applicantPages(),
                    $store,
                    $appliedResumes,
                    $rejectionArchive,
                    $reconcileErrors,
                ),
            ))->sync($storage);
            $this->fillAppliedResumeTitles($appliedResumes, $cache);

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'ok' => !empty($result['ok']),
                'message' => (string) ($result['message'] ?? ''),
                'count' => (int) ($result['count'] ?? 0),
                'pid' => getmypid(),
            ]);
            (new JsonStdoutView())->render($result);

            return !empty($result['ok']) ? 0 : 4;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    private function leaveRejections(): int
    {
        $status = $this->jobStatus();
        $status->write([
            'state' => 'running',
            'step' => 'leave_rejections',
            'ok' => null,
            'message' => 'Покидаю чаты с отказами на HH…',
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            /** @var CoreApi $core */
            $core = CurlCoreClient::fromConfig($config);
            $store = new CoreChatStore($core);
            $pages = $this->applicantPages();
            $cache = new FileCache($this->cacheDir());
            $rejectionArchive = new ChatRejectionArchive($cache);
            $sync = new SyncChatList(
                $browser,
                $pages,
                $store,
                new AppliedByVacancyStore($cache),
                $rejectionArchive,
            );
            $historyArchive = new ChatHistoryArchive($cache);
            $result = (new LeaveRejectionChats(
                $browser,
                $pages,
                $store,
                $sync,
                $rejectionArchive,
                $historyArchive,
            ))->leaveAll(
                $storage,
                static function (int $index, int $total, string $chatId) use ($status): void {
                    $status->write([
                        'state' => 'running',
                        'step' => 'leave_rejections',
                        'ok' => null,
                        'message' => sprintf('Покидаю отказ %d/%d… (%s)', $index, $total, $chatId),
                        'pid' => getmypid(),
                        'progress' => ['index' => $index, 'total' => $total],
                    ]);
                },
            );

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'ok' => !empty($result['ok']),
                'message' => (string) ($result['message'] ?? ''),
                'left' => (int) ($result['left'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'targets' => (int) ($result['targets'] ?? 0),
                'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
                'pid' => getmypid(),
            ]);
            (new JsonStdoutView())->render($result);

            return !empty($result['ok']) ? 0 : 4;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    private function leaveOld(): int
    {
        $status = $this->jobStatus();
        $status->write([
            'state' => 'running',
            'step' => 'leave_old',
            'ok' => null,
            'message' => 'Покидаю чаты старше месяца на HH…',
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            /** @var CoreApi $core */
            $core = CurlCoreClient::fromConfig($config);
            $store = new CoreChatStore($core);
            $pages = $this->applicantPages();
            $cache = new FileCache($this->cacheDir());
            $historyArchive = new ChatHistoryArchive($cache);
            $rejectionArchive = new ChatRejectionArchive($cache);
            $sync = new SyncChatList(
                $browser,
                $pages,
                $store,
                new AppliedByVacancyStore($cache),
                $rejectionArchive,
            );
            $result = (new LeaveOldChats(
                $browser,
                $pages,
                $store,
                $historyArchive,
                $rejectionArchive,
                $sync,
                1,
            ))->leaveAll(
                $storage,
                static function (int $index, int $total, string $chatId) use ($status): void {
                    $status->write([
                        'state' => 'running',
                        'step' => 'leave_old',
                        'ok' => null,
                        'message' => sprintf('Покидаю старый чат %d/%d… (%s)', $index, $total, $chatId),
                        'pid' => getmypid(),
                        'progress' => ['index' => $index, 'total' => $total],
                    ]);
                },
            );

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'ok' => !empty($result['ok']),
                'message' => (string) ($result['message'] ?? ''),
                'left' => (int) ($result['left'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'targets' => (int) ($result['targets'] ?? 0),
                'archived' => (int) ($result['archived'] ?? 0),
                'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
                'pid' => getmypid(),
            ]);
            (new JsonStdoutView())->render($result);

            return !empty($result['ok']) ? 0 : 4;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    private function resumeViews(): int
    {
        $status = $this->jobStatus();
        $status->write([
            'state' => 'running',
            'step' => 'resume_views',
            'ok' => null,
            'message' => 'Скачиваю просмотры резюме HH…',
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $cache = new FileCache($this->cacheDir());
            $store = new ResumeViewsStore($cache);

            $resumesEntry = $cache->get('resumes');
            $resumeItems = [];
            if ($resumesEntry !== null && is_array($resumesEntry->payload)) {
                $raw = is_array($resumesEntry->payload['items'] ?? null)
                    ? $resumesEntry->payload['items']
                    : [];
                foreach ($raw as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = trim((string) ($row['external_id'] ?? $row['id'] ?? ''));
                    if ($id === '') {
                        continue;
                    }
                    $resumeItems[] = [
                        'id' => $id,
                        'title' => trim((string) ($row['title'] ?? '')),
                    ];
                }
            }

            $result = (new SyncResumeViews($browser, $this->applicantPages(), $store))
                ->sync($storage, $resumeItems);

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'ok' => !empty($result['ok']),
                'message' => (string) ($result['message'] ?? ''),
                'resumes' => (int) ($result['resumes'] ?? 0),
                'employers' => (int) ($result['employers'] ?? 0),
                'pid' => getmypid(),
            ]);
            (new JsonStdoutView())->render($result);

            return !empty($result['ok']) ? 0 : 4;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    private function apply(string $resumeId, string $vacancyExternalId, string $vacancyTitle = ''): int
    {
        $resumeId = trim($resumeId);
        $vacancyExternalId = trim($vacancyExternalId);
        $vacancyTitle = trim($vacancyTitle);
        $status = $this->jobStatus();

        if ($resumeId === '' || $vacancyExternalId === '') {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => 'Usage: php bin/hh.php apply <resumeId> <vacancyExternalId> [vacancyTitle]',
                'pid' => getmypid(),
            ]);

            return 1;
        }

        $status->write([
            'state' => 'running',
            'step' => 'apply',
            'ok' => null,
            'message' => 'Откликаюсь на HH…',
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            $cache = new FileCache($this->cacheDir());
            $appliedResumes = new AppliedByVacancyStore($cache);
            $resumeTitle = $this->resumeTitleFromCache($cache, $resumeId);
            if ($vacancyTitle === '' || $vacancyTitle === $vacancyExternalId) {
                $vacancyTitle = $this->vacancyTitleFromList($config, $resumeId, $vacancyExternalId);
            }
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $result = (new ApplyController(
                new ApplyToVacancy($browser, $this->applicantPages(), $appliedResumes, $resumeTitle),
            ))->apply($storage, $resumeId, $vacancyExternalId);

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'ok' => $result->isOk(),
                'message' => $result->message,
                'submitted' => $result->submitted,
                'already_applied' => $result->alreadyApplied,
                'pid' => getmypid(),
            ]);
            $errors = new AppErrorStore($cache);
            if ($result->isOk() || $result->alreadyApplied) {
                $note = $result->alreadyApplied
                    ? 'Исправлено — уже откликались.'
                    : 'Исправлено — откликнуто.';
                $errors->markApplied($resumeId, $vacancyExternalId, $note);
            } elseif ($result->isSkipped() || AppErrorStore::isSkipMessage($result->message)) {
                $errors->markSkipped(
                    $resumeId,
                    $vacancyExternalId,
                    $result->message !== '' ? $result->message : 'Пропуск — skip в TG.',
                );
            } else {
                $errors->append([
                    'source' => 'apply',
                    'resume_id' => $resumeId,
                    'resume_title' => $resumeTitle,
                    'vacancy_id' => $vacancyExternalId,
                    'vacancy_title' => $vacancyTitle,
                    'message' => $result->message !== '' ? $result->message : 'Отклик не удался',
                ]);
            }
            (new JsonStdoutView())->render($result->toArray());

            return $result->isOk() ? 0 : 4;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            try {
                $cache = new FileCache($this->cacheDir());
                $title = $vacancyTitle;
                if ($title === '') {
                    try {
                        $title = $this->vacancyTitleFromList($this->config(), $resumeId, $vacancyExternalId);
                    } catch (\Throwable) {
                        $title = '';
                    }
                }
                (new AppErrorStore($cache))->append([
                    'source' => 'apply',
                    'resume_id' => $resumeId,
                    'vacancy_id' => $vacancyExternalId,
                    'vacancy_title' => $title,
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    private function applyConsider(string $resumeId, string $scope, string $limitRaw = '0'): int
    {
        $resumeId = trim($resumeId);
        $scope = strtolower(trim($scope)) === 'all' ? 'all' : 'recent';
        $limit = ctype_digit(trim($limitRaw)) ? (int) trim($limitRaw) : 0;
        $limit = max(0, min(50, $limit));
        $status = $this->jobStatus();

        if ($resumeId === '') {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => 'Usage: php bin/hh.php apply-consider <resumeId> [recent|all] [limit]',
                'pid' => getmypid(),
            ]);

            return 1;
        }

        $status->write([
            'state' => 'running',
            'step' => 'apply_consider',
            'ok' => null,
            'message' => $limit > 0
                ? sprintf('Готовлю отклики на «Смотрим» (до %d)…', $limit)
                : 'Готовлю отклики на «Смотрим»…',
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            $cache = new FileCache($this->cacheDir());
            $appliedResumes = new AppliedByVacancyStore($cache);
            $resumeTitle = $this->resumeTitleFromCache($cache, $resumeId);
            /** @var CoreApi $core */
            $core = CurlCoreClient::fromConfig($config);
            $ownerStore = new VacancyResumeOwnerStore($cache);
            $assignOwner = new AssignVacancyResume(
                $ownerStore,
                CurlDevAiClient::fromConfig($config),
                trim((string) ($config->get('DEVAI_MODEL', 'devai/auto') ?? 'devai/auto')) ?: 'devai/auto',
                $this->root . '/config/scoring/prompts/assign_vacancy_resume.txt',
                $cache,
                $core,
            );
            // Тот же фильтр «Смотрим», что в UI: без отказов / исключений / ошибок apply.
            $vacancyPage = new VacancyPage(
                new ListForResume($core),
                new AppliedVacancyIndex($core, $appliedResumes),
                new AppliedHistoryForResume($core, $appliedResumes),
                new ResumeViewsStore($cache),
                $ownerStore,
                $assignOwner,
                new CompanyRejectionIndex(new CoreChatStore($core), new ChatRejectionArchive($cache)),
                new CompanyExclusionStore($cache),
                new AppErrorStore($cache),
            );
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $lastVacancyId = '';
            $result = (new ApplyBatchController(
                new ApplyConsiderBatch(
                    $browser,
                    $this->applicantPages(),
                    $vacancyPage,
                    $appliedResumes,
                    $resumeTitle,
                    new AppErrorStore($cache),
                ),
            ))->applyConsider(
                $storage,
                $resumeId,
                $scope,
                static function (
                    int $index,
                    int $total,
                    string $vacancyId,
                    string $message,
                ) use ($status, &$lastVacancyId): void {
                    $lastVacancyId = trim($vacancyId);
                    $status->write([
                        'state' => 'running',
                        'step' => 'apply_consider',
                        'ok' => null,
                        'message' => $message . ' (' . $vacancyId . ')',
                        'pid' => getmypid(),
                        'progress' => [
                            'index' => $index,
                            'total' => $total,
                            'vacancy_id' => $vacancyId,
                        ],
                    ]);
                },
                $limit,
            );

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'ok' => !empty($result['ok']),
                'message' => (string) ($result['message'] ?? ''),
                'submitted' => (int) ($result['submitted'] ?? 0),
                'already_applied' => (int) ($result['already'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
                'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
                'skipped_details' => is_array($result['skipped_details'] ?? null)
                    ? $result['skipped_details']
                    : [],
                'pid' => getmypid(),
            ]);
            $this->recordApplyConsiderErrors($cache, $resumeId, $resumeTitle, $result);
            $this->resolveApplyConsiderErrors($cache, $resumeId, $result);
            $this->resolveApplyConsiderSkips($cache, $resumeId, $result);
            (new JsonStdoutView())->render($result);

            return !empty($result['ok']) ? 0 : 4;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            // Без vacancy_id «Повторить» бесполезен — только с последней известной вакансией.
            try {
                if (isset($lastVacancyId) && trim((string) $lastVacancyId) !== '') {
                    (new AppErrorStore(new FileCache($this->cacheDir())))->append([
                        'source' => 'apply_consider',
                        'resume_id' => $resumeId,
                        'vacancy_id' => trim((string) $lastVacancyId),
                        'message' => $e->getMessage(),
                    ]);
                }
            } catch (\Throwable) {
            }
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    private function applyErrors(): int
    {
        $status = $this->jobStatus();
        $status->write([
            'state' => 'running',
            'step' => 'apply_errors',
            'ok' => null,
            'message' => 'Повторяю отклики из журнала ошибок…',
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            $cache = new FileCache($this->cacheDir());
            $appliedResumes = new AppliedByVacancyStore($cache);
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $result = (new RetryErrorsController(
                new RetryFailedApplies(
                    $browser,
                    $this->applicantPages(),
                    new AppErrorStore($cache),
                    $appliedResumes,
                ),
            ))->retryAll(
                $storage,
                static function (int $index, int $total, string $vacancyId, string $message) use ($status): void {
                    $status->write([
                        'state' => 'running',
                        'step' => 'apply_errors',
                        'ok' => null,
                        'message' => $message . ' (' . $vacancyId . ')',
                        'pid' => getmypid(),
                        'progress' => ['index' => $index, 'total' => $total],
                    ]);
                },
            );

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'ok' => !empty($result['ok']),
                'message' => (string) ($result['message'] ?? ''),
                'submitted' => (int) ($result['submitted'] ?? 0),
                'already_applied' => (int) ($result['already'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'targets' => (int) ($result['targets'] ?? 0),
                'pid' => getmypid(),
            ]);
            (new JsonStdoutView())->render($result);

            return !empty($result['ok']) ? 0 : 4;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    private function rehydrateBotWall(): int
    {
        $status = $this->jobStatus();
        $status->write([
            'state' => 'running',
            'step' => 'rehydrate_bot_wall',
            'job' => 'rehydrate_bot_wall',
            'ok' => null,
            'message' => 'Перекачка bot-wall (detail по id)…',
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            $cache = new FileCache($this->cacheDir());
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            /** @var CoreApi $core */
            $core = CurlCoreClient::fromConfig($config);
            /** @var ScoringApi $scoring */
            $scoring = LocalDevAiScoring::fromConfig($config, $core, $this->root);
            $scoreTen = new ScoreTen($core, $scoring, new SyncResumeContext($core, $cache));
            $store = new BotWallRehydrateStore($cache);
            $result = (new BotWallRehydrateController(
                new ListBotWallRehydrate($store, $core, new VacancyResumeOwnerStore($cache)),
                new RehydrateBotWallVacancies(
                    $browser,
                    $this->applicantPages(),
                    $core,
                    $store,
                    new VacancyResumeOwnerStore($cache),
                    $scoreTen,
                    $cache,
                ),
            ))->run(
                $storage,
                static function (int $index, int $total, string $externalId, string $message) use ($status): void {
                    $status->write([
                        'state' => 'running',
                        'step' => 'rehydrate_bot_wall',
                        'job' => 'rehydrate_bot_wall',
                        'ok' => null,
                        'message' => $message . ' (' . $externalId . ')',
                        'pid' => getmypid(),
                        'progress' => ['index' => $index, 'total' => $total],
                    ]);
                },
            );

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'job' => 'rehydrate_bot_wall',
                'ok' => !empty($result['ok']),
                'message' => (string) ($result['message'] ?? ''),
                'ok_count' => (int) ($result['ok_count'] ?? 0),
                'unavailable' => (int) ($result['unavailable'] ?? 0),
                'captcha' => (int) ($result['captcha'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'left' => (int) ($result['left'] ?? 0),
                'pid' => getmypid(),
            ]);
            (new JsonStdoutView())->render($result);

            return !empty($result['ok']) ? 0 : 4;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'job' => 'rehydrate_bot_wall',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    private function jobSetCity(string $cityName): int
    {
        $cityName = trim($cityName);
        $status = $this->jobStatus();

        if ($cityName === '') {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => 'Не указан город.',
                'pid' => getmypid(),
            ]);

            return 1;
        }

        $status->write([
            'state' => 'running',
            'step' => 'set_city',
            'ok' => null,
            'message' => 'Переключаем город: ' . $cityName,
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            $cache = new FileCache($this->cacheDir());
            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            $result = (new SetCityController(new SwitchCity($browser, $this->applicantPages())))->switchTo($storage, $cityName);
            $cache->set('city', $result->city->toArray());

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'ok' => $result->isOk(),
                'message' => $result->message,
                'pid' => getmypid(),
            ]);

            return $result->isOk() ? 0 : 4;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * Пересмотр owner: source=first → title-rules / DevAI (без нового fetch).
     */
    private function assignReconcile(string $mode = ''): int
    {
        try {
            $config = $this->config();
            $cache = new FileCache($this->cacheDir());
            /** @var CoreApi $core */
            $core = CurlCoreClient::fromConfig($config);
            $assignOwner = new AssignVacancyResume(
                new VacancyResumeOwnerStore($cache),
                CurlDevAiClient::fromConfig($config),
                trim((string) ($config->get('DEVAI_MODEL', 'devai/auto') ?? 'devai/auto')) ?: 'devai/auto',
                $this->root . '/config/scoring/prompts/assign_vacancy_resume.txt',
                $cache,
                $core,
            );
            $titlesById = [];
            $entry = $cache->get('resumes');
            $items = is_array($entry?->payload['items'] ?? null) ? $entry->payload['items'] : [];
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['external_id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $titlesById[$id] = trim((string) ($row['title'] ?? ''));
            }
            if (count($titlesById) < 2) {
                (new JsonStdoutView())->render([
                    'ok' => false,
                    'message' => 'В кеше меньше 2 резюме — сначала php bin/hh.php resumes',
                    'changed' => 0,
                ]);

                return 1;
            }
            $mode = trim($mode);
            if ($mode === 'rebuild') {
                $changed = $assignOwner->rebuildFromCore($titlesById);
                (new JsonStdoutView())->render([
                    'ok' => true,
                    'message' => 'Owner rebuild from Core: ' . $changed,
                    'changed' => $changed,
                ]);
            } else {
                $changed = $assignOwner->reconcileStaleFirst($titlesById);
                (new JsonStdoutView())->render([
                    'ok' => true,
                    'message' => 'Owner reconcile (first→rules): изменено ' . $changed,
                    'changed' => $changed,
                ]);
            }

            return 0;
        } catch (\Throwable $e) {
            (new JsonStdoutView())->render([
                'ok' => false,
                'message' => $e->getMessage(),
                'changed' => 0,
            ]);
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    private function vacancies(string $onlyResumeId): int
    {
        $onlyResumeId = trim($onlyResumeId);
        $status = $this->jobStatus();

        $status->write([
            'state' => 'running',
            'step' => 'start',
            'ok' => null,
            'message' => 'Старт скачивания вакансий…',
            'pid' => getmypid(),
        ]);

        try {
            $config = $this->config();
            $storage = $this->storagePath($config);
            $cache = new FileCache($this->cacheDir());

            if (!is_readable($storage)) {
                $status->write([
                    'state' => 'done',
                    'step' => 'done',
                    'ok' => false,
                    'message' => 'Нет browser-сессии. php bin/hh.php login',
                    'pid' => getmypid(),
                ]);

                return 2;
            }

            $resumesEntry = $cache->get('resumes');
            $resumesPayload = $resumesEntry !== null && is_array($resumesEntry->payload)
                ? $resumesEntry->payload
                : null;
            $resumeItems = is_array($resumesPayload['items'] ?? null) ? $resumesPayload['items'] : [];
            $resumeList = [];
            foreach ($resumeItems as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = isset($row['external_id']) ? trim((string) $row['external_id']) : '';
                if ($id === '') {
                    continue;
                }
                if ($onlyResumeId !== '' && $id !== $onlyResumeId) {
                    continue;
                }
                $resumeList[] = [
                    'external_id' => $id,
                    'title' => isset($row['title']) ? (string) $row['title'] : '',
                ];
            }

            if ($onlyResumeId !== '' && $resumeList === []) {
                $resumeList[] = [
                    'external_id' => $onlyResumeId,
                    'title' => '',
                ];
            }

            if ($resumeList === []) {
                $status->write([
                    'state' => 'done',
                    'step' => 'done',
                    'ok' => false,
                    'message' => 'Нет резюме в кеше. Сначала «Обновить данные».',
                    'pid' => getmypid(),
                ]);

                return 2;
            }

            $status->write([
                'state' => 'running',
                'step' => 'serp',
                'ok' => null,
                'message' => $onlyResumeId !== ''
                    ? 'Качаю новые suitable для резюме…'
                    : sprintf('Качаю новые suitable для %d резюме…', count($resumeList)),
                'pid' => getmypid(),
            ]);

            /** @var BrowserGateway $browser */
            $browser = PlaywrightBrowserGateway::fromConfig($config);
            /** @var CoreApi $core */
            $core = CurlCoreClient::fromConfig($config);
            $ownerStore = new VacancyResumeOwnerStore($cache);
            $assignOwner = new AssignVacancyResume(
                $ownerStore,
                CurlDevAiClient::fromConfig($config),
                trim((string) ($config->get('DEVAI_MODEL', 'devai/auto') ?? 'devai/auto')) ?: 'devai/auto',
                $this->root . '/config/scoring/prompts/assign_vacancy_resume.txt',
                $cache,
                $core,
            );

            $allTitles = [];
            foreach ($resumeItems as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = isset($row['external_id']) ? trim((string) $row['external_id']) : '';
                if ($id === '') {
                    continue;
                }
                $allTitles[$id] = isset($row['title']) ? (string) $row['title'] : '';
            }

            /** @var ScoringApi $scoring */
            $scoring = LocalDevAiScoring::fromConfig($config, $core, $this->root);
            $scoreTen = new ScoreTen($core, $scoring, new SyncResumeContext($core, $cache));
            $listForResume = new ListForResume($core);

            $fetch = new FetchSuitable(
                $browser,
                $core,
                $this->applicantPages(),
                $assignOwner,
                FetchSuitable::DEFAULT_MAX_AGE_DAYS,
                new SuitableRunStatsStore($cache),
                new BotWallRehydrateStore($cache),
            );
            $fetch->withResumeTitles($allTitles);

            $enqueuedTotal = 0;
            /** @var list<string> $enqueuedResumeIds */
            $enqueuedResumeIds = [];
            /** @var array<string, true> $enqueuedSeen */
            $enqueuedSeen = [];

            $result = (new VacancyFetchController($fetch))->fetchForAllResumes(
                $storage,
                $resumeList,
                FetchSuitable::DEFAULT_TARGET_CONSIDER,
                static function (int $index, int $total, string $label, array $meta) use ($status): void {
                    $phase = (string) ($meta['phase'] ?? 'start');
                    $consider = (int) ($meta['consider'] ?? 0);
                    $target = (int) ($meta['target_consider'] ?? FetchSuitable::DEFAULT_TARGET_CONSIDER);
                    $ingestedNow = (int) ($meta['ingested'] ?? 0);
                    $message = match ($phase) {
                        'score' => sprintf(
                            'Резюме %d/%d: %s — оценка DevAI (скачано %d, apply/maybe %d)…',
                            $index,
                            $total,
                            $label,
                            $ingestedNow,
                            $consider,
                        ),
                        'progress' => sprintf(
                            'Резюме %d/%d: %s — скачано %d, apply/maybe %d (норма %d)',
                            $index,
                            $total,
                            $label,
                            $ingestedNow,
                            $consider,
                            $target,
                        ),
                        default => sprintf('Резюме %d/%d: %s', $index, $total, $label),
                    };
                    $status->write([
                        'state' => 'running',
                        'step' => $phase === 'score' ? 'devai' : 'serp',
                        'ok' => null,
                        'message' => $message,
                        'pid' => getmypid(),
                        'consider' => $consider,
                        'target_consider' => $target,
                        'ingested' => $ingestedNow,
                    ]);
                },
                static fn (string $resumeId): int => $listForResume->countConsiderRecent($resumeId),
                static function (string $resumeId, string $coreId, bool $forceRescore = false) use (
                    $scoreTen,
                    &$enqueuedTotal,
                    &$enqueuedResumeIds,
                    &$enqueuedSeen,
                ): bool {
                    $scored = $scoreTen->scoreFreshVacancy($resumeId, $coreId, $forceRescore);
                    if ((int) ($scored['enqueued'] ?? 0) > 0) {
                        $enqueuedTotal += (int) $scored['enqueued'];
                        if (!isset($enqueuedSeen[$resumeId])) {
                            $enqueuedSeen[$resumeId] = true;
                            $enqueuedResumeIds[] = $resumeId;
                        }
                    }

                    return (bool) ($scored['is_consider'] ?? false);
                },
            );

            $ingested = (int) $result['ingested'];
            $failed = (int) $result['failed'];
            $scoredInline = (int) ($result['scored'] ?? 0);

            $status->write([
                'state' => 'running',
                'step' => 'owners',
                'ok' => null,
                'message' => 'Разбираю пересечения вакансий между резюме…',
                'pid' => getmypid(),
                'ingested' => $ingested,
                'failed' => $failed,
                'enqueued' => $enqueuedTotal,
            ]);
            // Все резюме из кеша (не только скачанные в этом run) — для DevAI candidates.
            $titlesById = [];
            foreach ($resumeItems as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = isset($row['external_id']) ? trim((string) $row['external_id']) : '';
                if ($id === '') {
                    continue;
                }
                $titlesById[$id] = isset($row['title']) ? (string) $row['title'] : '';
            }
            foreach ($resumeList as $row) {
                $titlesById[$row['external_id']] = (string) ($row['title'] ?? $titlesById[$row['external_id']] ?? '');
            }
            $assignOwner->reconcileMultiPeers($titlesById);

            $considerParts = [];
            $resumeBatches = is_array($result['resumes'] ?? null) ? $result['resumes'] : [];
            foreach ($resumeBatches as $batch) {
                if (!is_array($batch)) {
                    continue;
                }
                $label = trim((string) ($batch['resume_title'] ?? ''));
                if ($label === '') {
                    $label = (string) ($batch['resume_id'] ?? '');
                }
                $considerParts[] = sprintf(
                    '%s %d/%d',
                    $label,
                    (int) ($batch['consider'] ?? 0),
                    (int) ($batch['target_consider'] ?? FetchSuitable::DEFAULT_TARGET_CONSIDER),
                );
            }
            $considerSummary = $considerParts !== [] ? implode('; ', $considerParts) : '';

            if ($ingested > 0 || $scoredInline > 0) {
                $message = sprintf(
                    'Скачал %d, оценил %d (ошибок %d). Apply/maybe: %s.',
                    $ingested,
                    $scoredInline > 0 ? $scoredInline : $enqueuedTotal,
                    $failed,
                    $considerSummary !== '' ? $considerSummary : '—',
                );
            } else {
                $message = sprintf(
                    'Новых в SERP нет (ошибок %d). Apply/maybe: %s.',
                    $failed,
                    $considerSummary !== '' ? $considerSummary : '—',
                );
            }

            if ($enqueuedResumeIds !== []) {
                $cache->set('scoring_watch', [
                    'resume_ids' => $enqueuedResumeIds,
                    'enqueued' => $enqueuedTotal,
                    'at' => time(),
                ]);
            }

            $status->write([
                'state' => 'done',
                'step' => 'done',
                'ok' => $failed === 0 || $ingested > 0,
                'message' => $message,
                'pid' => getmypid(),
                'ingested' => $ingested,
                'failed' => $failed,
                'enqueued' => $enqueuedTotal,
                'scored' => $scoredInline,
                'enqueued_resumes' => $enqueuedResumeIds,
            ]);

            return $ingested > 0 || $failed === 0 ? 0 : 1;
        } catch (\Throwable $e) {
            $status->write([
                'state' => 'done',
                'step' => 'error',
                'ok' => false,
                'message' => $e->getMessage(),
                'pid' => getmypid(),
            ]);
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    /** Последние события TG DM из Core (fallback: data/telegram_dm.jsonl). */
    private function tgLog(string $limitRaw): int
    {
        $limit = ctype_digit(trim($limitRaw)) ? (int) $limitRaw : 40;
        $journal = new TelegramDmJournal(
            $this->root . '/data/telegram_dm.jsonl',
            CurlCoreClient::fromConfig($this->config()),
        );
        $rows = $journal->recent($limit);
        if ($rows === []) {
            fwrite(STDOUT, "TG DM journal пуст (Core + {$this->root}/data/telegram_dm.jsonl).\n");

            return 0;
        }
        foreach (array_reverse($rows) as $row) {
            $at = (string) ($row['at'] ?? '');
            $dir = (string) ($row['direction'] ?? '');
            $kind = (string) ($row['kind'] ?? '');
            $vid = (string) ($row['vacancy_id'] ?? '');
            $pid = (string) ($row['pid'] ?? '');
            $text = preg_replace('/\s+/u', ' ', trim((string) ($row['text'] ?? ''))) ?? '';
            if (mb_strlen($text) > 160) {
                $text = mb_substr($text, 0, 157) . '…';
            }
            $meta = trim((string) ($row['meta_json'] ?? ''));
            $line = "{$at}  {$dir}/{$kind}";
            if ($vid !== '') {
                $line .= "  vac={$vid}";
            }
            if ($pid !== '' && $pid !== '0') {
                $line .= "  pid={$pid}";
            }
            $line .= '  ' . $text;
            if ($meta !== '') {
                $line .= '  ' . $meta;
            }
            fwrite(STDOUT, $line . "\n");
        }

        return 0;
    }

    /**
     * Успешные отклики пакета → пометки «Исправлено» в журнале ошибок.
     *
     * @param array<string, mixed> $result
     */
    private function resolveApplyConsiderErrors(FileCache $cache, string $resumeId, array $result): void
    {
        $ids = is_array($result['succeeded_ids'] ?? null) ? $result['succeeded_ids'] : [];
        if ($ids === []) {
            return;
        }
        $store = new AppErrorStore($cache);
        foreach ($ids as $vacancyId) {
            if (!is_string($vacancyId) || trim($vacancyId) === '') {
                continue;
            }
            $store->markApplied($resumeId, trim($vacancyId));
        }
    }

    /**
     * Skip анкеты/письма в пакете → статус skipped (не открытая ошибка).
     *
     * @param array<string, mixed> $result
     */
    private function resolveApplyConsiderSkips(FileCache $cache, string $resumeId, array $result): void
    {
        $ids = is_array($result['skipped_ids'] ?? null) ? $result['skipped_ids'] : [];
        $notes = is_array($result['skipped_apply_notes'] ?? null) ? $result['skipped_apply_notes'] : [];
        if ($ids === []) {
            return;
        }
        $store = new AppErrorStore($cache);
        foreach ($ids as $vacancyId) {
            if (!is_string($vacancyId) || trim($vacancyId) === '') {
                continue;
            }
            $vacancyId = trim($vacancyId);
            $note = is_string($notes[$vacancyId] ?? null) ? trim((string) $notes[$vacancyId]) : '';
            $store->markSkipped($resumeId, $vacancyId, $note);
        }
    }

    /**
     * Пишет ошибки пакетного отклика в локальный журнал «Ошибки».
     *
     * @param array<string, mixed> $result
     */
    private function recordApplyConsiderErrors(
        FileCache $cache,
        string $resumeId,
        string $resumeTitle,
        array $result,
    ): void {
        $rows = [];
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        foreach ($errors as $err) {
            if (is_string($err)) {
                $message = trim($err);
                if ($message === '') {
                    continue;
                }
                $rows[] = [
                    'source' => 'apply_consider',
                    'resume_id' => $resumeId,
                    'resume_title' => $resumeTitle,
                    'message' => $message,
                ];
                continue;
            }
            if (!is_array($err)) {
                continue;
            }
            $message = trim((string) ($err['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $rows[] = [
                'source' => 'apply_consider',
                'resume_id' => $resumeId,
                'resume_title' => $resumeTitle,
                'vacancy_id' => trim((string) ($err['vacancy_id'] ?? '')),
                'vacancy_title' => trim((string) ($err['vacancy_title'] ?? '')),
                'message' => $message,
            ];
        }
        if ($rows === []) {
            return;
        }
        (new AppErrorStore($cache))->appendMany($rows);
    }

    private function resumeTitleFromCache(FileCache $cache, string $resumeId): string
    {
        $resumeId = trim($resumeId);
        if ($resumeId === '') {
            return '';
        }
        $entry = $cache->get('resumes');
        if ($entry === null || !is_array($entry->payload)) {
            return '';
        }
        $items = is_array($entry->payload['items'] ?? null) ? $entry->payload['items'] : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['external_id'] ?? '')) === $resumeId) {
                return trim((string) ($row['title'] ?? ''));
            }
        }

        return '';
    }

    /** Название вакансии из suitable/истории резюме (для журнала ошибок). */
    private function vacancyTitleFromList(Config $config, string $resumeId, string $vacancyExternalId): string
    {
        $resumeId = trim($resumeId);
        $vacancyExternalId = trim($vacancyExternalId);
        if ($resumeId === '' || $vacancyExternalId === '') {
            return '';
        }
        try {
            /** @var CoreApi $core */
            $core = CurlCoreClient::fromConfig($config);
            $payload = (new ListForResume($core))->listForResume($resumeId, 'all');
        } catch (\Throwable) {
            return '';
        }
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['external_id'] ?? '')) !== $vacancyExternalId) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '' || $title === $vacancyExternalId) {
                return '';
            }
            $company = trim((string) ($row['company_name'] ?? ''));

            return $company !== '' ? $title . ' · ' . $company : $title;
        }

        return '';
    }

    private function fillAppliedResumeTitles(AppliedByVacancyStore $store, FileCache $cache): void
    {
        $entry = $cache->get('resumes');
        if ($entry === null || !is_array($entry->payload)) {
            return;
        }
        $titles = [];
        $items = is_array($entry->payload['items'] ?? null) ? $entry->payload['items'] : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['external_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $titles[$id] = trim((string) ($row['title'] ?? ''));
        }
        $store->resolveTitles($titles);
    }
}
