<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Company\ListExcludedCompanies;

/** Presentation: компании-исключения. */
final class ExcludedCompaniesController
{
    public function __construct(private readonly ListExcludedCompanies $useCase) {}

    /** @return array{total: int, items: list<array<string, mixed>>, seeded: int} */
    public function page(bool $seedSber = false): array
    {
        return $this->useCase->page($seedSber);
    }

    public function clear(): void
    {
        $this->useCase->clear();
    }
}
