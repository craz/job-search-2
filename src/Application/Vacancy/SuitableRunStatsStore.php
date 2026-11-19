<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Infrastructure\Cache\Cache;

/**
 * Локальный журнал метрик suitable-run: SERP / дедуп / ingest (FileCache).
 * Core SearchRun не хранит skipped_known — пишем сюда.
 */
final class SuitableRunStatsStore
{
    public const CACHE_KEY = 'suitable_run_stats';

    public function __construct(private readonly Cache $cache) {}

    /**
     * @param array{
     *   search_run_id: string,
     *   resume_id: string,
     *   resume_title?: string,
     *   started_at?: string,
     *   finished_at?: string,
     *   status?: string,
     *   serp_cards: int,
     *   hh_total?: int|null,
     *   in_db?: int,
     *   skipped_known: int,
     *   skipped_old: int,
     *   ingested: int,
     *   created: int,
     *   updated: int,
     *   unchanged: int,
     *   failed: int,
     *   scored?: int
     * } $row
     */
    public function remember(array $row): void
    {
        $runId = trim((string) ($row['search_run_id'] ?? ''));
        if ($runId === '') {
            return;
        }
        $entry = $this->cache->get(self::CACHE_KEY);
        $payload = is_array($entry?->payload) ? $entry->payload : [];
        /** @var array<string, array<string, mixed>> $byId */
        $byId = is_array($payload['by_id'] ?? null) ? $payload['by_id'] : [];
        $hhTotal = $row['hh_total'] ?? null;
        $byId[$runId] = [
            'search_run_id' => $runId,
            'resume_id' => trim((string) ($row['resume_id'] ?? '')),
            'resume_title' => trim((string) ($row['resume_title'] ?? '')),
            'started_at' => trim((string) ($row['started_at'] ?? '')),
            'finished_at' => trim((string) ($row['finished_at'] ?? gmdate('c'))),
            'status' => trim((string) ($row['status'] ?? '')),
            'serp_cards' => max(0, (int) ($row['serp_cards'] ?? 0)),
            'hh_total' => is_numeric($hhTotal) ? max(0, (int) $hhTotal) : null,
            'in_db' => max(0, (int) ($row['in_db'] ?? 0)),
            'skipped_known' => max(0, (int) ($row['skipped_known'] ?? 0)),
            'skipped_old' => max(0, (int) ($row['skipped_old'] ?? 0)),
            'ingested' => max(0, (int) ($row['ingested'] ?? 0)),
            'created' => max(0, (int) ($row['created'] ?? 0)),
            'updated' => max(0, (int) ($row['updated'] ?? 0)),
            'unchanged' => max(0, (int) ($row['unchanged'] ?? 0)),
            'failed' => max(0, (int) ($row['failed'] ?? 0)),
            'scored' => max(0, (int) ($row['scored'] ?? 0)),
        ];
        // Не раздувать бесконечно: последние ~400 run'ов.
        if (count($byId) > 400) {
            uasort($byId, static function (array $a, array $b): int {
                return strcmp((string) ($b['finished_at'] ?? ''), (string) ($a['finished_at'] ?? ''));
            });
            $byId = array_slice($byId, 0, 400, true);
        }
        $this->cache->set(self::CACHE_KEY, ['by_id' => $byId]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allByRunId(): array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        $payload = is_array($entry?->payload) ? $entry->payload : [];
        $byId = is_array($payload['by_id'] ?? null) ? $payload['by_id'] : [];

        return array_filter($byId, 'is_array');
    }
}
