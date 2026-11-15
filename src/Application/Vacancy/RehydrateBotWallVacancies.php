<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;
use JobSearch\Domain\Vacancy\HhBotWall;
use JobSearch\Domain\Vacancy\HhVacancyClosed;
use JobSearch\Domain\Vacancy\VacancyCard;
use JobSearch\Infrastructure\Cache\Cache;

/**
 * Прямая перекачка detail для id из bot_wall_rehydrate (без SERP).
 * Капча → оставляем в очереди, без TG. Успех → ingest + force rescore.
 */
final class RehydrateBotWallVacancies
{
    /** Стоп, если подряд столько bot-wall/капч — HH снова закрыл сессию. */
    private const STOP_AFTER_CONSECUTIVE_CAPTCHA = 5;

    /** Пауза между detail, чтобы реже ловить капчу. */
    private const PAUSE_US = 2_000_000;

    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
        private readonly CoreApi $core,
        private readonly BotWallRehydrateStore $rehydrate,
        private readonly VacancyResumeOwnerStore $owners,
        private readonly ScoreTen $scoreTen,
        private readonly Cache $cache,
    ) {}

    /**
     * @param callable|null $onProgress fn(int $index, int $total, string $externalId, string $message): void
     *
     * @return array{
     *   ok: bool,
     *   message: string,
     *   total: int,
     *   ok_count: int,
     *   captcha: int,
     *   failed: int,
     *   left: int
     * }
     */
    public function run(string $storageStatePath, ?callable $onProgress = null, int $limit = 0): array
    {
        if (!is_readable($storageStatePath)) {
            throw new \RuntimeException('login_required');
        }

        $ids = array_map(
            static fn (string|int $id): string => (string) $id,
            array_keys($this->rehydrate->externalIdSet()),
        );
        sort($ids, SORT_STRING);
        if ($limit > 0) {
            $ids = array_slice($ids, 0, $limit);
        }
        $total = count($ids);
        if ($total === 0) {
            return [
                'ok' => true,
                'message' => 'Очередь перекачки пуста.',
                'total' => 0,
                'ok_count' => 0,
                'captcha' => 0,
                'failed' => 0,
                'left' => 0,
            ];
        }

        $okCount = 0;
        $captcha = 0;
        $unavailable = 0;
        $failed = 0;
        $consecutiveCaptcha = 0;
        /** @var list<string> $failSamples */
        $failSamples = [];
        $defaultResume = $this->firstResumeId();

        $context = $this->browser->openChromium($storageStatePath);
        try {
            $page = $context->newPage();
            foreach ($ids as $i => $ext) {
                $n = $i + 1;
                if ($onProgress !== null) {
                    $onProgress($n, $total, $ext, sprintf('Перекачка %d/%d…', $n, $total));
                }
                if ($i > 0) {
                    usleep(self::PAUSE_US);
                }

                $card = new VacancyCard(
                    $ext,
                    '—',
                    'https://hh.ru/vacancy/' . rawurlencode($ext),
                    '—',
                    '0',
                );

                try {
                    $detail = $this->pages->readVacancyDetail($page, $card);
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    if (str_contains($msg, 'captcha_or_action_required')) {
                        $captcha++;
                        $consecutiveCaptcha++;
                        $this->rehydrate->rememberMany([$ext]);
                        if ($consecutiveCaptcha >= self::STOP_AFTER_CONSECUTIVE_CAPTCHA) {
                            if ($onProgress !== null) {
                                $onProgress($n, $total, $ext, 'Стоп: HH снова капча подряд.');
                            }
                            break;
                        }
                        continue;
                    }
                    if (str_contains($msg, 'vacancy_unavailable')) {
                        // Закрыта/скрыта — перекачивать бессмысленно; убрать из «Смотрим».
                        $unavailable++;
                        $consecutiveCaptcha = 0;
                        $this->rehydrate->forget($ext);
                        $this->markClosedOnHh($ext, 'HH: вакансия недоступна / в архиве');
                        continue;
                    }
                    $failed++;
                    $consecutiveCaptcha = 0;
                    if (count($failSamples) < 5) {
                        $failSamples[] = $ext . ': ' . $msg;
                    }
                    continue;
                }

                if (HhBotWall::looksLike($detail->title, $detail->description)) {
                    $captcha++;
                    $consecutiveCaptcha++;
                    $this->rehydrate->rememberMany([$ext]);
                    if ($consecutiveCaptcha >= self::STOP_AFTER_CONSECUTIVE_CAPTCHA) {
                        break;
                    }
                    continue;
                }
                if (HhVacancyClosed::looksLike($detail->title, $detail->description)) {
                    $unavailable++;
                    $consecutiveCaptcha = 0;
                    $this->rehydrate->forget($ext);
                    $this->markClosedOnHh($ext, 'HH: в архиве / закрыта');
                    continue;
                }
                $consecutiveCaptcha = 0;

                try {
                    $result = $this->core->ingestVacancy($detail->toIngestPayload());
                } catch (CoreException $e) {
                    $failed++;
                    if (count($failSamples) < 5) {
                        $failSamples[] = $ext . ': ingest ' . $e->getMessage();
                    }
                    continue;
                }

                $coreId = '';
                if (isset($result['vacancy']) && is_array($result['vacancy'])) {
                    $coreId = trim((string) ($result['vacancy']['id'] ?? ''));
                }
                $this->rehydrate->forget($ext);
                $okCount++;

                if ($coreId === '') {
                    continue;
                }
                $resumeId = trim((string) (($this->owners->get($ext)['resume_id'] ?? '') ?: $defaultResume));
                if ($resumeId === '') {
                    continue;
                }
                if ($onProgress !== null) {
                    $onProgress($n, $total, $ext, sprintf('Оценка DevAI %d/%d…', $n, $total));
                }
                try {
                    $this->scoreTen->scoreFreshVacancy($resumeId, $coreId, true);
                } catch (\Throwable) {
                    // текст уже в Core — оценку можно догнать кнопкой «Оценить»
                }
            }
        } finally {
            $context->close();
        }

        $left = count($this->rehydrate->externalIdSet());
        $message = sprintf(
            'Перекачка: ок %d · недоступны %d · капча/бот-wall %d · сбой %d · осталось в очереди %d (из %d в этом прогоне).',
            $okCount,
            $unavailable,
            $captcha,
            $failed,
            $left,
            $total,
        );
        if ($failSamples !== []) {
            $message .= "\nСбои: " . implode('; ', $failSamples);
        }

        return [
            'ok' => $okCount > 0 || $unavailable > 0 || ($captcha === 0 && $failed === 0),
            'message' => $message,
            'total' => $total,
            'ok_count' => $okCount,
            'unavailable' => $unavailable,
            'captcha' => $captcha,
            'failed' => $failed,
            'left' => $left,
        ];
    }

    private function firstResumeId(): string
    {
        $entry = $this->cache->get('resumes');
        $items = is_array($entry?->payload['items'] ?? null) ? $entry->payload['items'] : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['external_id'] ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    /** Core: archived + skip, чтобы не висела в «Смотрим» с пустым MAYBE. */
    private function markClosedOnHh(string $externalId, string $reason): void
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return;
        }
        try {
            $map = $this->core->lookupVacancyIdsByExternalIds([$externalId]);
            $coreId = trim((string) ($map[$externalId] ?? ''));
            if ($coreId === '') {
                return;
            }
            $this->core->updateVacancySourceStatus($coreId, 'archived', $reason);
            $manual = new SetManualVerdict($this->core);
            $manual->set($coreId, 'skip', $reason);
        } catch (\Throwable) {
            // очередь уже снята; статус догоним вручную / следующим прогоном
        }
    }
}
