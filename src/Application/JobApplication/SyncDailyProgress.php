<?php
declare(strict_types=1);

namespace JobSearch\Application\JobApplication;

use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;

/**
 * Считает дневной прогресс и пишет снимок в Core `daily_metrics` (Postgres).
 */
final class SyncDailyProgress
{
    public function __construct(
        private readonly CoreApi $core,
        private readonly CountTodayApplies $countToday,
    ) {}

    /**
     * @param array<string, array{skip_count?: int, consider_count?: int, unscored_count?: int}> $vacanciesByResume
     *
     * @return array{
     *   day: string,
     *   goal: int,
     *   by_resume: array<string, int>,
     *   total_today: int,
     *   batch: array{skip: int, consider: int, unscored: int},
     *   persisted: bool,
     *   metric_id?: string
     * }
     */
    public function sync(array $vacanciesByResume = []): array
    {
        $summary = $this->countToday->summarize();
        $batch = ['skip' => 0, 'consider' => 0, 'unscored' => 0];
        foreach ($vacanciesByResume as $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $batch['skip'] += (int) ($meta['skip_count'] ?? 0);
            $batch['consider'] += (int) ($meta['consider_count'] ?? 0);
            $batch['unscored'] += (int) ($meta['unscored_count'] ?? 0);
        }

        $notesPayload = [
            'source' => 'job_search_2',
            'goal_per_resume' => $summary['goal'],
            'by_resume' => $summary['by_resume'],
            'batch' => $batch,
        ];
        $notes = json_encode($notesPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($notes === false) {
            $notes = null;
        } elseif (strlen($notes) > 4000) {
            $notes = substr($notes, 0, 3997) . '...';
        }

        $persisted = false;
        try {
            $fingerprint = hash(
                'sha256',
                (string) $summary['total_today'] . '|' . ($notes ?? ''),
            );
            $this->core->setDailyMetric(
                $summary['day'],
                [
                    'applications' => $summary['total_today'],
                    'notes' => $notes,
                ],
                'job_search_2:progress:' . $summary['day'] . ':' . substr($fingerprint, 0, 16),
            );
            $persisted = true;
        } catch (CoreException) {
            $persisted = false;
        }

        return [
            'day' => $summary['day'],
            'goal' => $summary['goal'],
            'by_resume' => $summary['by_resume'],
            'total_today' => $summary['total_today'],
            'batch' => $batch,
            'persisted' => $persisted,
        ];
    }

    /**
     * Актуальный прогресс (live) + запись снимка в Core. UI всегда из live, не из старых notes.
     *
     * @param array<string, array{skip_count?: int, consider_count?: int, unscored_count?: int}> $vacanciesByResume
     *
     * @return array{
     *   day: string,
     *   goal: int,
     *   by_resume: array<string, int>,
     *   total_today: int,
     *   batch: array{skip: int, consider: int, unscored: int},
     *   persisted: bool,
     *   from_db: bool
     * }
     */
    public function loadOrSync(array $vacanciesByResume = []): array
    {
        $live = $this->sync($vacanciesByResume);
        $fromDb = false;
        try {
            $fromDb = $this->core->getDailyMetric($live['day']) !== null;
        } catch (CoreException) {
            $fromDb = false;
        }

        return [
            ...$live,
            'from_db' => $fromDb,
        ];
    }
}
