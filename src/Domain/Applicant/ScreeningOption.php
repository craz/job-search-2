<?php
declare(strict_types=1);

namespace JobSearch\Domain\Applicant;

/** Вариант radio/checkbox в анкете HH. */
final class ScreeningOption
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly string $type = 'radio',
    ) {}
}
