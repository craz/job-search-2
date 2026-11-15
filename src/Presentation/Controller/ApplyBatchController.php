<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\JobApplication\ApplyConsiderBatch;

/** Presentation: пакетный отклик на «Смотрим». */
final class ApplyBatchController
{
    public function __construct(private readonly ApplyConsiderBatch $useCase) {}

    /**
     * @param (callable(int, int, string, string): void)|null $onProgress
     *
     * @return array{
     *   ok: bool,
     *   message: string,
     *   consider: int,
     *   targets: int,
     *   submitted: int,
     *   already: int,
     *   failed: int,
     *   skipped: int,
     *   errors: list<array{vacancy_id: string, vacancy_title: string, message: string}>,
     *   skipped_details: list<string>
     * }
     */
    public function applyConsider(
        string $storageStatePath,
        string $resumeId,
        string $scope = 'recent',
        ?callable $onProgress = null,
        int $limit = 0,
    ): array {
        return $this->useCase->applyAll($storageStatePath, $resumeId, $scope, $onProgress, $limit);
    }
}
