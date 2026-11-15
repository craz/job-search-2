<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\JobApplication\ApplyToVacancy;
use JobSearch\Domain\Applicant\ApplyResult;

/** Presentation: программный отклик на вакансию. */
final class ApplyController
{
    public function __construct(private readonly ApplyToVacancy $useCase) {}

    public function apply(
        string $storageStatePath,
        string $resumeId,
        string $vacancyExternalId,
    ): ApplyResult {
        return $this->useCase->apply($storageStatePath, $resumeId, $vacancyExternalId);
    }
}
