<?php
declare(strict_types=1);

namespace JobSearch\Domain\Vacancy;

/**
 * Вакансия с полным описанием для Core ingest.
 */
final class VacancyDetail
{
    /**
     * @param list<string> $keySkills
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $title,
        public readonly string $url,
        public readonly string $companyName,
        public readonly string $companyExternalId,
        public readonly ?string $description = null,
        public readonly ?string $salaryText = null,
        public readonly ?string $areaText = null,
        public readonly ?string $employmentText = null,
        public readonly ?string $scheduleText = null,
        public readonly ?string $experienceText = null,
        public readonly array $keySkills = [],
        /** Дата публикации HH (Europe/Moscow), если распарсили со страницы. */
        public readonly ?\DateTimeImmutable $publishedAt = null,
    ) {}

    /** Описание + ключевые навыки (Core ingest без отдельного поля skills). */
    public function descriptionForIngest(): ?string
    {
        $parts = [];
        if ($this->description !== null && $this->description !== '') {
            $parts[] = $this->description;
        }
        if ($this->keySkills !== []) {
            // Разделитель устойчив к схлопыванию \n→пробел в Core.
            $parts[] = 'Ключевые навыки: ' . implode(' | ', $this->keySkills);
        }
        if ($parts === []) {
            return null;
        }

        return implode("\n\n", $parts);
    }

    /** @return array<string, mixed> тело VacancyIngest для Core */
    public function toIngestPayload(): array
    {
        $payload = [
            'company_name' => $this->companyName,
            'company_external_id' => $this->companyExternalId,
            'source' => 'hh',
            'external_id' => $this->externalId,
            'title' => $this->title,
            'url' => $this->url,
            'description' => $this->descriptionForIngest(),
            'salary_text' => $this->salaryText,
            'area_text' => $this->areaText,
            'employment_text' => $this->employmentText,
            'schedule_text' => $this->scheduleText,
            'experience_text' => $this->experienceText,
            // Live detail fetch: Core ставит source_status=active (иначе Scoring skip).
            'archived' => false,
        ];

        return array_filter(
            $payload,
            static fn ($v) => $v !== null && $v !== '',
        );
    }
}
