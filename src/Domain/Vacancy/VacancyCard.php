<?php
declare(strict_types=1);

namespace JobSearch\Domain\Vacancy;

/**
 * Карточка вакансии с SERP «подходящие» (без полного описания).
 */
final class VacancyCard
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $title,
        public readonly string $url,
        public readonly string $companyName,
        public readonly string $companyExternalId,
        public readonly ?string $salaryText = null,
        public readonly ?string $areaText = null,
    ) {}
}
