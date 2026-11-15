<?php
declare(strict_types=1);

namespace JobSearch\Application\JobApplication;

use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Applicant\ApplyResult;
use JobSearch\Domain\Browser\BrowserGateway;

/**
 * Use case: программный отклик на вакансию через browser-сессию hh.ru.
 */
final class ApplyToVacancy
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
        private readonly ?AppliedByVacancyStore $appliedResumes = null,
        private readonly string $resumeTitle = '',
    ) {}

    public function apply(
        string $storageStatePath,
        string $resumeId,
        string $vacancyExternalId,
    ): ApplyResult {
        $resumeId = trim($resumeId);
        $vacancyExternalId = trim($vacancyExternalId);
        if ($resumeId === '' || $vacancyExternalId === '') {
            return new ApplyResult(false, false, false, 'Укажите resume и vacancy.');
        }
        if (!is_readable($storageStatePath)) {
            return new ApplyResult(false, false, false, 'Нет browser-сессии. Сначала login.', $vacancyExternalId, $resumeId);
        }

        $context = $this->browser->openChromium($storageStatePath);
        try {
            $page = $context->newPage();
            $result = $this->pages->applyToVacancy($page, $vacancyExternalId, $resumeId);
            if ($result->isOk() || $result->alreadyApplied) {
                $this->appliedResumes?->rememberFromApply(
                    $vacancyExternalId,
                    $resumeId,
                    $this->resumeTitle,
                );
            }

            return $result;
        } finally {
            $context->close();
        }
    }
}
