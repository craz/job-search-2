<?php
declare(strict_types=1);

namespace JobSearch\Domain\Resume;


use JobSearch\Domain\Resume\ResumeSummary;
/**
 * Результат чтения списка резюме из UI hh.ru (не официальный API).
 */
final class ResumeList
{
    /** @param list<ResumeSummary> $items */
    public function __construct(
        public readonly ResumeListKind $kind,
        public readonly array $items,
        public readonly string $url,
    ) {}

    public function isOk(): bool
    {
        return $this->kind === ResumeListKind::Ok;
    }

    /** @return array{kind: string, url: string, count: int, items: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'url' => $this->url,
            'count' => count($this->items),
            'items' => array_map(
                static fn (ResumeSummary $r): array => $r->toArray(),
                $this->items,
            ),
        ];
    }
}
