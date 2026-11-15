<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Vacancy\VacancyPage;

/** Presentation: HTML-страница вакансий по резюме. */
final class VacancyPageController
{
    public function __construct(private readonly VacancyPage $useCase) {}

    /**
     * @param list<array{id: string, title: string}> $resumes
     *
     * @return array<string, mixed>
     */
    public function page(
        string $resumeId,
        string $resumeTitle = '',
        string $filter = 'all',
        string $selectedId = '',
        string $scope = 'recent',
        array $resumes = [],
        string $query = '',
    ): array {
        return $this->useCase->forResume(
            $resumeId,
            $resumeTitle,
            $filter,
            $selectedId,
            $scope,
            $resumes,
            $query,
        );
    }
}
