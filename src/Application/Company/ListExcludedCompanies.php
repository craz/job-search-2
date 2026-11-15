<?php
declare(strict_types=1);

namespace JobSearch\Application\Company;

/**
 * Страница компаний-исключений + подтягивание Сберов из справочника.
 */
final class ListExcludedCompanies
{
    public function __construct(
        private readonly CompanyExclusionStore $store,
        private readonly ?ListCompanies $companies = null,
    ) {}

    /**
     * @return array{total: int, items: list<array<string, mixed>>, seeded: int}
     */
    public function page(bool $seedSber = false): array
    {
        $seeded = 0;
        if ($seedSber) {
            $seeded = $this->seedSberFromCompanies();
        }

        $items = $this->store->all();

        return [
            'total' => count($items),
            'items' => $items,
            'seeded' => $seeded,
        ];
    }

    /** Найти Сберы в suitable/чатах и добавить в исключения (idempotent). */
    public function seedSberFromCompanies(): int
    {
        if ($this->companies === null) {
            return 0;
        }
        $payload = $this->companies->page('all', '');
        $rows = [];
        foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['company_name'] ?? ''));
            if (!CompanyExclusionStore::looksLikeSber($name)) {
                continue;
            }
            $rows[] = [
                'company_name' => $name,
                'company_external_id' => trim((string) ($item['company_external_id'] ?? '')),
                'reason' => 'Сбер / экосистема — не подаёмся',
                'source' => 'seed_sber',
            ];
        }

        return $this->store->upsertMany($rows);
    }

    public function clear(): void
    {
        $this->store->clear();
    }
}
