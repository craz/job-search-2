<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Infrastructure\Cache\Cache;

/**
 * HH external_id, у которых в Core лежит страница «не робот» вместо вакансии.
 * FetchSuitable не считает их known — перекачивает detail и обновляет ingest.
 */
final class BotWallRehydrateStore
{
    public const CACHE_KEY = 'bot_wall_rehydrate';

    public function __construct(private readonly Cache $cache) {}

    /** @param list<string> $externalIds */
    public function rememberMany(array $externalIds): void
    {
        $byId = $this->all();
        $changed = false;
        foreach ($externalIds as $id) {
            $id = trim((string) $id);
            if ($id === '' || isset($byId[$id])) {
                continue;
            }
            $byId[$id] = ['external_id' => $id, 'at' => gmdate('c')];
            $changed = true;
        }
        if ($changed) {
            $this->cache->set(self::CACHE_KEY, ['by_id' => $byId]);
        }
    }

    public function forget(string $externalId): void
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return;
        }
        $byId = $this->all();
        if (!isset($byId[$externalId])) {
            return;
        }
        unset($byId[$externalId]);
        $this->cache->set(self::CACHE_KEY, ['by_id' => $byId]);
    }

    public function needs(string $externalId): bool
    {
        $externalId = trim($externalId);

        return $externalId !== '' && isset($this->all()[$externalId]);
    }

    /** @return array<string, true> */
    public function externalIdSet(): array
    {
        $out = [];
        foreach (array_keys($this->all()) as $id) {
            $out[(string) $id] = true;
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function all(): array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        $payload = is_array($entry?->payload) ? $entry->payload : [];
        $byId = is_array($payload['by_id'] ?? null) ? $payload['by_id'] : [];
        $out = [];
        foreach ($byId as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) $id);
            if ($key === '') {
                continue;
            }
            $out[$key] = $row;
        }

        return $out;
    }
}
