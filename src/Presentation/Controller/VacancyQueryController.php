<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Vacancy\ListForResume;

/** Presentation: чтение вакансий из Core. */
final class VacancyQueryController
{
    public function __construct(private readonly ListForResume $useCase) {}

    /** @return array<string, array{search_run_id: string, count: int, status: string}> */
    public function summaryByResume(): array
    {
        return $this->useCase->summaryByResume();
    }

    /** @return array<string, mixed> */
    public function listForResume(string $resumeId): array
    {
        $payload = $this->useCase->listForResume($resumeId, 'recent');
        $items = [];
        foreach ($payload['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $this->useCase->hydrateDetail($row);
        }
        $payload['items'] = $items;
        $payload['count'] = count($items);

        return $payload;
    }
}
