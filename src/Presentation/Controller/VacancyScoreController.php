<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Vacancy\ScoreProgress;
use JobSearch\Application\Vacancy\ScoreTen;

/** Presentation: DevAI enqueue + прогресс оценки пачки. */
final class VacancyScoreController
{
    public function __construct(
        private readonly ScoreTen $scoreTen,
        private readonly ?ScoreProgress $progress = null,
    ) {}

    /** @return array{ok: bool, enqueued: int, message: string, vacancy_ids?: list<string>, raw?: array<string, mixed>} */
    public function forResume(string $resumeId, int $limit = 10): array
    {
        return $this->scoreTen->forResume($resumeId, $limit);
    }

    /** @return array{ok: bool, enqueued: int, message: string, vacancy_ids?: list<string>, raw?: array<string, mixed>} */
    public function forVacancy(string $resumeId, string $vacancyId, bool $forceRescore = false): array
    {
        return $this->scoreTen->forVacancy($resumeId, $vacancyId, $forceRescore);
    }

    /** @return array<string, mixed> */
    public function progressForResume(string $resumeId): array
    {
        if ($this->progress === null) {
            return ['ok' => false, 'message' => 'ScoreProgress not wired.'];
        }

        return $this->progress->forResume($resumeId);
    }
}
