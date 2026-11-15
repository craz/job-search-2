<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;
use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Vacancy\HhBotWall;
use JobSearch\Domain\Vacancy\VacancyCard;
use JobSearch\Domain\Vacancy\VacancyDetail;
use JobSearch\Domain\Vacancy\VacancyPublishedAt;
use Playwright\Page\PageInterface;

/**
 * Use case: SERP «подходящие» → деталь → Core ingest → owner assign (дедуп резюме).
 * Только новые (не в peerIndex) и не старше maxAgeDays по дате публикации HH.
 * С колбэками — сразу DevAI; стоп только SERP / возраст / maxIngest
 * (не останавливаемся из‑за уже набранных apply/maybe).
 */
final class FetchSuitable
{
    public const DEFAULT_MAX_AGE_DAYS = 7;

    /** Ориентир «нормы смотрим» для прогресса/UI — не стоп fetch. */
    public const DEFAULT_TARGET_CONSIDER = 10;

    /** Потолок скачиваний на резюме за один run (защита от бесконечного SERP). */
    public const DEFAULT_MAX_INGEST = 40;

    /** @var array<string, list<string>> external_id → resume_ids */
    private array $peerIndex = [];

    /** @var array<string, string> resume_id → title */
    private array $resumeTitles = [];

    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly CoreApi $core,
        private readonly ApplicantPages $pages,
        private readonly ?AssignVacancyResume $assignOwner = null,
        private readonly int $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS,
        private readonly ?SuitableRunStatsStore $runStats = null,
        private readonly ?BotWallRehydrateStore $rehydrate = null,
    ) {}

    /**
     * На каждое резюме — новые suitable (до maxIngest), с опциональной оценкой сразу.
     * $targetConsider только для прогресса/отчёта, не стоп.
     *
     * @param list<array{external_id: string, title?: string}> $resumes
     * @param callable|null $onProgress fn(int $index, int $total, string $label, array $batchMeta): void
     * @param callable|null $countConsider fn(string $resumeId): int
     * @param callable|null $afterIngestScore fn(string $resumeId, string $coreVacancyId, bool $forceRescore=false): bool isConsider
     *
     * @return array{
     *   per_resume: int,
     *   target_consider: int,
     *   resume_count: int,
     *   ingested: int,
     *   failed: int,
     *   scored: int,
     *   resumes: list<array<string, mixed>>
     * }
     */
    public function fetchForAllResumes(
        string $storageStatePath,
        array $resumes,
        int $targetConsider = self::DEFAULT_TARGET_CONSIDER,
        ?callable $onProgress = null,
        ?callable $countConsider = null,
        ?callable $afterIngestScore = null,
        int $maxIngestPerResume = self::DEFAULT_MAX_INGEST,
    ): array {
        if (!is_readable($storageStatePath)) {
            throw new \RuntimeException('login_required');
        }
        if ($resumes === []) {
            throw new \InvalidArgumentException('Resume list is empty');
        }

        $targetConsider = max(1, $targetConsider);
        $maxIngestPerResume = max(1, $maxIngestPerResume);

        $this->resumeTitles = $this->normalizeResumeTitles($resumes);
        $this->peerIndex = $this->assignOwner?->peerIndexFromCore() ?? [];

        $context = $this->browser->openChromium($storageStatePath);
        try {
            $page = $context->newPage();
            $batches = [];
            $ingestedTotal = 0;
            $failedTotal = 0;
            $scoredTotal = 0;
            $index = 0;
            $total = count($resumes);

            foreach ($resumes as $resume) {
                $index++;
                $resumeId = trim((string) ($resume['external_id'] ?? ''));
                $resumeTitle = trim((string) ($resume['title'] ?? ''));
                if ($resumeId === '') {
                    continue;
                }
                if ($onProgress !== null) {
                    $onProgress($index, $total, $resumeTitle !== '' ? $resumeTitle : $resumeId, [
                        'phase' => 'start',
                        'resume_id' => $resumeId,
                    ]);
                }
                $batch = $this->fetchOnPage(
                    $page,
                    $resumeId,
                    $targetConsider,
                    $countConsider,
                    $afterIngestScore,
                    $maxIngestPerResume,
                    $onProgress !== null
                        ? static function (array $meta) use ($onProgress, $index, $total, $resumeTitle, $resumeId): void {
                            $onProgress(
                                $index,
                                $total,
                                $resumeTitle !== '' ? $resumeTitle : $resumeId,
                                $meta,
                            );
                        }
                        : null,
                );
                $batch['resume_title'] = $resumeTitle;
                $batches[] = $batch;
                $ingestedTotal += (int) $batch['ingested'];
                $failedTotal += (int) $batch['failed'];
                $scoredTotal += (int) ($batch['scored'] ?? 0);
            }

            return [
                'per_resume' => $targetConsider,
                'target_consider' => $targetConsider,
                'resume_count' => count($batches),
                'ingested' => $ingestedTotal,
                'failed' => $failedTotal,
                'scored' => $scoredTotal,
                'resumes' => $batches,
            ];
        } finally {
            $context->close();
        }
    }

    /**
     * @param callable|null $countConsider fn(string $resumeId): int
     * @param callable|null $afterIngestScore fn(string $resumeId, string $coreVacancyId, bool $forceRescore=false): bool
     *
     * @return array{
     *   resume_id: string,
     *   requested: int,
     *   ingested: int,
     *   failed: int,
     *   items: list<array<string, mixed>>
     * }
     */
    public function fetchForResume(
        string $storageStatePath,
        string $resumeId,
        int $targetConsider = self::DEFAULT_TARGET_CONSIDER,
        ?callable $countConsider = null,
        ?callable $afterIngestScore = null,
        int $maxIngestPerResume = self::DEFAULT_MAX_INGEST,
    ): array {
        $resumeId = trim($resumeId);
        if ($resumeId === '') {
            throw new \InvalidArgumentException('Resume id is empty');
        }
        if (!is_readable($storageStatePath)) {
            throw new \RuntimeException('login_required');
        }

        if (!isset($this->resumeTitles[$resumeId])) {
            $this->resumeTitles[$resumeId] = $this->resumeTitles[$resumeId] ?? '';
        }
        $this->peerIndex = $this->assignOwner?->peerIndexFromCore() ?? [];

        $context = $this->browser->openChromium($storageStatePath);
        try {
            return $this->fetchOnPage(
                $context->newPage(),
                $resumeId,
                max(1, $targetConsider),
                $countConsider,
                $afterIngestScore,
                max(1, $maxIngestPerResume),
            );
        } finally {
            $context->close();
        }
    }

    /**
     * @param callable|null $countConsider fn(string $resumeId): int
     * @param callable|null $afterIngestScore fn(string $resumeId, string $coreVacancyId, bool $forceRescore=false): bool isConsider
     * @param callable|null $onResumeProgress fn(array $meta): void
     *
     * @return array{
     *   resume_id: string,
     *   search_run_id: string|null,
     *   requested: int,
     *   serp_cards: int,
     *   skipped_known: int,
     *   skipped_old: int,
     *   ingested: int,
     *   created: int,
     *   updated: int,
     *   unchanged: int,
     *   failed: int,
     *   scored: int,
     *   consider: int,
     *   target_consider: int,
     *   skipped_enough_consider: bool,
     *   items: list<array<string, mixed>>
     * }
     */
    private function fetchOnPage(
        PageInterface $page,
        string $resumeId,
        int $targetConsider,
        ?callable $countConsider,
        ?callable $afterIngestScore,
        int $maxIngest,
        ?callable $onResumeProgress = null,
    ): array {
        $scoreInline = $afterIngestScore !== null;
        $consider = $countConsider !== null ? max(0, (int) $countConsider($resumeId)) : 0;
        $startedAt = gmdate('c');

        $run = $this->core->startResumeSuitableRun($resumeId);
        $runId = isset($run['id']) ? (string) $run['id'] : '';
        if ($runId === '') {
            throw new CoreException('Core SearchRun id missing after start');
        }

        // Уже видели в suitable-run (любое резюме) — не качаем снова; добираем ниже по выдаче.
        // Исключение: bot-wall в Core («не робот») — перекачиваем detail.
        /** @var array<string, true> $rehydrateIds */
        $rehydrateIds = $this->rehydrate?->externalIdSet() ?? [];
        /** @var array<string, true> $exclude */
        $exclude = [];
        foreach (array_keys($this->peerIndex) as $ext) {
            $ext = trim((string) $ext);
            if ($ext === '' || isset($rehydrateIds[$ext])) {
                continue;
            }
            $exclude[$ext] = true;
        }

        $items = [];
        $ingested = 0;
        $failed = 0;
        $scored = 0;
        $skippedOld = 0;
        $skippedKnown = 0;
        $serpCards = 0;
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $seen = 0;
        $status = 'failed';
        $maxPages = 12;
        // Без inline-оценки — до targetConsider карточек; с оценкой — до maxIngest новых.
        $ingestCap = $scoreInline ? $maxIngest : $targetConsider;

        try {
            for ($serpPage = 0; $serpPage < $maxPages; $serpPage++) {
                if ($ingested >= $ingestCap) {
                    break;
                }

                $pageCards = $this->pages->readSuitableSerpPage($page, $resumeId, $serpPage);
                if ($pageCards === []) {
                    break;
                }

                $hitOlderThanWindow = false;
                $consecutiveOld = 0;
                foreach ($pageCards as $card) {
                    if ($ingested >= $ingestCap) {
                        break;
                    }
                    $serpCards++;
                    $id = trim($card->externalId);
                    if ($id === '') {
                        continue;
                    }
                    if (isset($exclude[$id])) {
                        $skippedKnown++;
                        continue;
                    }
                    $exclude[$id] = true;
                    $seen++;

                    try {
                        $detail = $this->pages->readVacancyDetail($page, $card);
                        if (
                            $detail->publishedAt !== null
                            && !VacancyPublishedAt::isFresh($detail->publishedAt, $this->maxAgeDays)
                        ) {
                            $skippedOld++;
                            // URL с order_by=publication_time: несколько старых подряд → ниже только старше.
                            // Не рвём на первой (на relevance/сбоях HH иначе теряем свежие ниже).
                            $consecutiveOld++;
                            if ($consecutiveOld >= 3) {
                                $hitOlderThanWindow = true;
                                break;
                            }
                            continue;
                        }
                        $consecutiveOld = 0;

                        $forceRescore = isset($rehydrateIds[$id]);
                        $result = $this->core->ingestVacancy($detail->toIngestPayload());
                        $outcome = isset($result['outcome']) && is_string($result['outcome'])
                            ? $result['outcome']
                            : 'unchanged';
                        match ($outcome) {
                            'created' => $created++,
                            'updated' => $updated++,
                            default => $unchanged++,
                        };
                        $coreId = null;
                        if (isset($result['vacancy']) && is_array($result['vacancy']) && isset($result['vacancy']['id'])) {
                            $coreId = (string) $result['vacancy']['id'];
                        }
                        $this->core->addSearchRunItem(
                            $runId,
                            $detail->externalId,
                            $this->mapRunOutcome($outcome),
                            $coreId,
                            $coreId !== null ? 'hydrated' : null,
                            [
                                'title' => $detail->title,
                                'company_name' => $detail->companyName,
                                'company_external_id' => $detail->companyExternalId,
                                'url' => $detail->url,
                                'published_at' => $detail->publishedAt?->format('Y-m-d'),
                            ],
                        );
                        $this->notePeerAndAssign($resumeId, $detail);
                        if ($forceRescore && !HhBotWall::looksLike($detail->title, $detail->description)) {
                            $this->rehydrate?->forget($detail->externalId);
                            unset($rehydrateIds[$id]);
                        }
                        $row = $this->itemRow($detail, $outcome, $coreId, null);
                        $ingested++;

                        if ($scoreInline && $coreId !== null && $coreId !== '') {
                            if ($onResumeProgress !== null) {
                                $onResumeProgress([
                                    'phase' => 'score',
                                    'resume_id' => $resumeId,
                                    'consider' => $consider,
                                    'target_consider' => $targetConsider,
                                    'ingested' => $ingested,
                                    'core_id' => $coreId,
                                ]);
                            }
                            $isConsider = (bool) $afterIngestScore($resumeId, $coreId, $forceRescore);
                            $scored++;
                            if ($isConsider) {
                                $consider++;
                            }
                            $row['verdict_consider'] = $isConsider;
                        }

                        $items[] = $row;

                        if ($onResumeProgress !== null) {
                            $onResumeProgress([
                                'phase' => 'progress',
                                'resume_id' => $resumeId,
                                'consider' => $consider,
                                'target_consider' => $targetConsider,
                                'ingested' => $ingested,
                                'scored' => $scored,
                            ]);
                        }
                    } catch (CoreException $e) {
                        $failed++;
                        try {
                            $this->core->addSearchRunItem(
                                $runId,
                                $card->externalId,
                                'error',
                                null,
                                null,
                                ['title' => $card->title, 'error' => $e->getMessage()],
                            );
                        } catch (CoreException) {
                            // item уже мог не записаться — не роняем весь run
                        }
                        $items[] = $this->itemRowFromCard($card, 'error', null, $e->getMessage());
                    } catch (\Throwable $e) {
                        $failed++;
                        if (str_contains($e->getMessage(), 'captcha_or_action_required')) {
                            // Уже known/poisoned или снова капча — оставить в очереди на перекачку.
                            $this->rehydrate?->rememberMany([$card->externalId]);
                        }
                        try {
                            $this->core->addSearchRunItem(
                                $runId,
                                $card->externalId,
                                'error',
                                null,
                                null,
                                ['title' => $card->title, 'error' => $e->getMessage()],
                            );
                        } catch (CoreException) {
                        }
                        $items[] = $this->itemRowFromCard($card, 'error', null, $e->getMessage());
                    }
                }

                if ($hitOlderThanWindow) {
                    break;
                }
            }

            $status = $failed === 0 ? 'success' : ($ingested > 0 ? 'partial' : 'failed');
            $this->core->finalizeSearchRun($runId, $status, $seen);
        } catch (\Throwable $e) {
            $status = $ingested > 0 ? 'partial' : 'failed';
            try {
                $this->core->finalizeSearchRun($runId, $status, $seen);
            } catch (CoreException) {
            }
            $this->persistRunStats(
                $runId,
                $resumeId,
                $startedAt,
                $status,
                $serpCards,
                $skippedKnown,
                $skippedOld,
                $ingested,
                $created,
                $updated,
                $unchanged,
                $failed,
                $scored,
            );
            throw $e;
        }

        $this->persistRunStats(
            $runId,
            $resumeId,
            $startedAt,
            $status,
            $serpCards,
            $skippedKnown,
            $skippedOld,
            $ingested,
            $created,
            $updated,
            $unchanged,
            $failed,
            $scored,
        );

        return [
            'resume_id' => $resumeId,
            'search_run_id' => $runId,
            'requested' => $seen,
            'serp_cards' => $serpCards,
            'skipped_known' => $skippedKnown,
            'skipped_old' => $skippedOld,
            'ingested' => $ingested,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'failed' => $failed,
            'scored' => $scored,
            'consider' => $consider,
            'target_consider' => $targetConsider,
            'skipped_enough_consider' => false,
            'items' => $items,
        ];
    }

    private function persistRunStats(
        string $runId,
        string $resumeId,
        string $startedAt,
        string $status,
        int $serpCards,
        int $skippedKnown,
        int $skippedOld,
        int $ingested,
        int $created,
        int $updated,
        int $unchanged,
        int $failed,
        int $scored,
    ): void {
        if ($this->runStats === null || $runId === '') {
            return;
        }
        $this->runStats->remember([
            'search_run_id' => $runId,
            'resume_id' => $resumeId,
            'resume_title' => $this->resumeTitles[$resumeId] ?? '',
            'started_at' => $startedAt,
            'finished_at' => gmdate('c'),
            'status' => $status,
            'serp_cards' => $serpCards,
            'skipped_known' => $skippedKnown,
            'skipped_old' => $skippedOld,
            'ingested' => $ingested,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'failed' => $failed,
            'scored' => $scored,
        ]);
    }

    private function notePeerAndAssign(string $resumeId, VacancyDetail $detail): void
    {
        $ext = trim($detail->externalId);
        if ($ext === '') {
            return;
        }
        $peers = $this->peerIndex[$ext] ?? [];
        if (!in_array($resumeId, $peers, true)) {
            $peers[] = $resumeId;
        }
        $this->peerIndex[$ext] = array_values(array_unique($peers));

        if ($this->assignOwner === null) {
            return;
        }

        $this->assignOwner->assign(
            $ext,
            $this->peerIndex[$ext],
            [
                'title' => $detail->title,
                'company_name' => $detail->companyName,
                'url' => $detail->url,
                'description' => $detail->description,
            ],
            $this->resumeTitles,
        );
    }

    /**
     * @param list<array{external_id?: string, title?: string}> $resumes
     *
     * @return array<string, string>
     */
    private function normalizeResumeTitles(array $resumes): array
    {
        $out = $this->resumeTitles;
        foreach ($resumes as $resume) {
            $id = trim((string) ($resume['external_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[$id] = trim((string) ($resume['title'] ?? $out[$id] ?? ''));
        }

        return $out;
    }

    /** @param array<string, string> $titles */
    public function withResumeTitles(array $titles): void
    {
        foreach ($titles as $id => $title) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $this->resumeTitles[$id] = trim((string) $title);
        }
    }

    private function mapRunOutcome(string $outcome): string
    {
        return match ($outcome) {
            'created' => 'created',
            'updated' => 'updated',
            'unchanged' => 'unchanged',
            default => 'unchanged',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRow(VacancyDetail $detail, string $outcome, ?string $coreId, ?string $error): array
    {
        return [
            'external_id' => $detail->externalId,
            'title' => $detail->title,
            'url' => $detail->url,
            'company_name' => $detail->companyName,
            'description' => $detail->description,
            'key_skills' => $detail->keySkills,
            'salary_text' => $detail->salaryText,
            'area_text' => $detail->areaText,
            'outcome' => $outcome,
            'core_id' => $coreId,
            'error' => $error,
            'has_description' => $detail->description !== null && $detail->description !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRowFromCard(VacancyCard $card, string $outcome, ?string $coreId, ?string $error): array
    {
        return [
            'external_id' => $card->externalId,
            'title' => $card->title,
            'url' => $card->url,
            'company_name' => $card->companyName,
            'outcome' => $outcome,
            'core_id' => $coreId,
            'error' => $error,
            'description' => null,
            'key_skills' => [],
            'salary_text' => $card->salaryText,
            'area_text' => $card->areaText,
            'has_description' => false,
        ];
    }
}
