<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\JobApplication\SyncDailyProgress;

/** Presentation: дневной прогресс → Core daily_metrics. */
final class ProgressController
{
    public function __construct(private readonly SyncDailyProgress $useCase) {}

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
     *   from_db: bool
     * }
     */
    public function today(array $vacanciesByResume = []): array
    {
        return $this->useCase->loadOrSync($vacanciesByResume);
    }
}
