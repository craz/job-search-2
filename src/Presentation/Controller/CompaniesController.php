<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Company\ListCompanies;

/** Presentation: справочник компаний из вакансий. */
final class CompaniesController
{
    public function __construct(private readonly ListCompanies $useCase) {}

    /** @return array<string, mixed> */
    public function page(string $filter = 'all', string $query = ''): array
    {
        return $this->useCase->page($filter, $query);
    }
}
