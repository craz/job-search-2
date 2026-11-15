<?php
declare(strict_types=1);

namespace JobSearch\Domain\Resume;

/**
 * Текст одного резюме (экспорт TXT / HTML-конвертер hh.ru).
 */
final class ResumeText
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $title,
        public readonly string $text,
    ) {}

    /** @return array{external_id: string, title: string, text: string} */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'title' => $this->title,
            'text' => $this->text,
        ];
    }
}
