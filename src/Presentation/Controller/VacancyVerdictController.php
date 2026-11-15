<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Vacancy\SetManualVerdict;

/** Presentation: ручной вердикт вакансии. */
final class VacancyVerdictController
{
    public function __construct(private readonly SetManualVerdict $useCase) {}

    /** @return array{ok: bool, message: string, verdict: string, vacancy_id: string} */
    public function set(string $vacancyId, string $verdict, string $reason = ''): array
    {
        return $this->useCase->set($vacancyId, $verdict, $reason);
    }
}
