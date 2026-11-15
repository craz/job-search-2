<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\JobApplication\RetryFailedApplies;

/** Presentation: повтор откликов из журнала ошибок. */
final class RetryErrorsController
{
    public function __construct(private readonly RetryFailedApplies $useCase) {}

    /**
     * @param (callable(int, int, string, string): void)|null $onProgress
     *
     * @return array{
     *   ok: bool,
     *   message: string,
     *   targets: int,
     *   submitted: int,
     *   already: int,
     *   failed: int,
     *   errors: list<array{vacancy_id: string, vacancy_title: string, message: string}>
     * }
     */
    public function retryAll(string $storageStatePath, ?callable $onProgress = null): array
    {
        return $this->useCase->retryAll($storageStatePath, $onProgress);
    }
}
