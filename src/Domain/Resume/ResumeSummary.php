<?php
declare(strict_types=1);

namespace JobSearch\Domain\Resume;

/**
 * Краткое резюме со страницы соискателя: id, title, подходящие, даты HH.
 */
final class ResumeSummary
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $title,
        public readonly ?int $vacanciesCount = null,
        /** ISO-8601 (UTC), когда резюме создали/разместили на HH. */
        public readonly ?string $createdAt = null,
        /** ISO-8601 (UTC), последнее обновление/поднятие на HH. */
        public readonly ?string $updatedAt = null,
    ) {}

    /**
     * @return array{
     *   external_id: string,
     *   title: string,
     *   vacancies_count: int|null,
     *   created_at: string|null,
     *   updated_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'title' => $this->title,
            'vacancies_count' => $this->vacanciesCount,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
