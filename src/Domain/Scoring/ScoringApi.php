<?php
declare(strict_types=1);

namespace JobSearch\Domain\Scoring;

/**
 * Контракт Scoring: очередь semantic-оценки через DevAI.
 */
interface ScoringApi
{
    /**
     * Поставить N вакансий в manual_bulk (DevAI route scoring).
     *
     * @param list<string> $vacancyIds Core UUID
     *
     * @return array<string, mixed>
     *
     * @throws ScoringException
     */
    public function enqueueSemanticBulk(array $vacancyIds, string $source = 'devai_auto'): array;
}
