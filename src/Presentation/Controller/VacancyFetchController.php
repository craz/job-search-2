<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Vacancy\FetchSuitable;

/** Presentation: скачать подходящие вакансии в Core. */
final class VacancyFetchController
{
    public function __construct(private readonly FetchSuitable $useCase) {}

    /**
     * @param list<array{external_id: string, title?: string}> $resumes
     * @param callable|null $onProgress fn(int $index, int $total, string $label, array $batchMeta): void
     * @param callable|null $countConsider fn(string $resumeId): int
     * @param callable|null $afterIngestScore fn(string $resumeId, string $coreVacancyId): bool
     *
     * @return array<string, mixed>
     */
    public function fetchForAllResumes(
        string $storageStatePath,
        array $resumes,
        int $targetConsider = FetchSuitable::DEFAULT_TARGET_CONSIDER,
        ?callable $onProgress = null,
        ?callable $countConsider = null,
        ?callable $afterIngestScore = null,
        int $maxIngestPerResume = FetchSuitable::DEFAULT_MAX_INGEST,
    ): array {
        return $this->useCase->fetchForAllResumes(
            $storageStatePath,
            $resumes,
            $targetConsider,
            $onProgress,
            $countConsider,
            $afterIngestScore,
            $maxIngestPerResume,
        );
    }

    /**
     * @param callable|null $countConsider fn(string $resumeId): int
     * @param callable|null $afterIngestScore fn(string $resumeId, string $coreVacancyId): bool
     *
     * @return array<string, mixed>
     */
    public function fetchForResume(
        string $storageStatePath,
        string $resumeId,
        int $targetConsider = FetchSuitable::DEFAULT_TARGET_CONSIDER,
        ?callable $countConsider = null,
        ?callable $afterIngestScore = null,
        int $maxIngestPerResume = FetchSuitable::DEFAULT_MAX_INGEST,
    ): array {
        return $this->useCase->fetchForResume(
            $storageStatePath,
            $resumeId,
            $targetConsider,
            $countConsider,
            $afterIngestScore,
            $maxIngestPerResume,
        );
    }
}
