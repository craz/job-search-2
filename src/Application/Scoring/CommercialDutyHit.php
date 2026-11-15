<?php
declare(strict_types=1);

namespace JobSearch\Application\Scoring;

/** Результат EXCLUDE-01 или EXCLUDE-02. */
final class CommercialDutyHit
{
    public function __construct(
        public readonly bool $hardSkip,
        public readonly bool $ambiguous,
        public readonly string $code,
        public readonly string $snippet,
        public readonly string $reason,
    ) {}
}
