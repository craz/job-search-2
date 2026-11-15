<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Infrastructure\Cache\Cache;

/**
 * Какое резюме «владеет» HH-вакансией (дедуп между suitable-run).
 * Ключ — vacancy external_id (HH).
 *
 * suitable_peers — резюме, в чьей suitable-выдаче вакансия реально была.
 * candidates — пул для DevAI (может быть шире: все профили).
 */
final class VacancyResumeOwnerStore
{
    public const CACHE_KEY = 'vacancy_resume_owner';

    public function __construct(private readonly Cache $cache) {}

    /**
     * @return array{
     *   resume_id: string,
     *   resume_title: string,
     *   decided_at: string,
     *   source: string,
     *   reason: string,
     *   candidates: list<string>,
     *   suitable_peers: list<string>
     * }|null
     */
    public function get(string $vacancyExternalId): ?array
    {
        $vacancyExternalId = trim($vacancyExternalId);
        if ($vacancyExternalId === '') {
            return null;
        }
        $row = $this->all()[$vacancyExternalId] ?? null;

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, array{
     *   resume_id: string,
     *   resume_title: string,
     *   decided_at: string,
     *   source: string,
     *   reason: string,
     *   candidates: list<string>,
     *   suitable_peers: list<string>
     * }>
     */
    public function all(): array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        if ($entry === null || !is_array($entry->payload)) {
            return [];
        }
        $out = [];
        foreach ($entry->payload as $vacancyId => $row) {
            $vacancyId = trim((string) $vacancyId);
            if ($vacancyId === '' || !is_array($row)) {
                continue;
            }
            $resumeId = trim((string) ($row['resume_id'] ?? ''));
            if ($resumeId === '') {
                continue;
            }
            $candidates = $this->normalizeIdList($row['candidates'] ?? null);
            if (!in_array($resumeId, $candidates, true)) {
                $candidates[] = $resumeId;
            }
            $suitable = $this->normalizeIdList($row['suitable_peers'] ?? null);
            // Старые записи без suitable_peers: не подставляем весь candidates-пул.
            if ($suitable === []) {
                $suitable = [$resumeId];
            } elseif (!in_array($resumeId, $suitable, true)) {
                $suitable[] = $resumeId;
            }
            $out[$vacancyId] = [
                'resume_id' => $resumeId,
                'resume_title' => trim((string) ($row['resume_title'] ?? '')),
                'decided_at' => trim((string) ($row['decided_at'] ?? '')),
                'source' => trim((string) ($row['source'] ?? '')),
                'reason' => trim((string) ($row['reason'] ?? '')),
                'candidates' => $candidates,
                'suitable_peers' => array_values(array_unique($suitable)),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $candidates пул DevAI
     * @param list<string> $suitablePeers фактические suitable-peers
     */
    public function put(
        string $vacancyExternalId,
        string $resumeId,
        string $resumeTitle,
        string $source,
        string $reason = '',
        array $candidates = [],
        array $suitablePeers = [],
    ): void {
        $row = $this->buildRow($resumeId, $resumeTitle, $source, $reason, $candidates, $suitablePeers);
        if ($row === null) {
            return;
        }
        $vacancyExternalId = trim($vacancyExternalId);
        if ($vacancyExternalId === '') {
            return;
        }
        $all = $this->all();
        $all[$vacancyExternalId] = $row;
        $this->cache->set(self::CACHE_KEY, $all);
    }

    /**
     * Пакетная запись — один read/write, без потери ключей при массовом reconcile.
     *
     * @param array<string, array{
     *   resume_id: string,
     *   resume_title?: string,
     *   source?: string,
     *   reason?: string,
     *   candidates?: list<string>,
     *   suitable_peers?: list<string>
     * }> $rows
     */
    public function putMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $all = $this->all();
        $n = 0;
        foreach ($rows as $ext => $raw) {
            $ext = trim((string) $ext);
            if ($ext === '' || !is_array($raw)) {
                continue;
            }
            $row = $this->normalizeIncomingRow($raw);
            if ($row === null) {
                continue;
            }
            $all[$ext] = $row;
            $n++;
        }
        $this->cache->set(self::CACHE_KEY, $all);

        return $n;
    }

    /**
     * Полная замена стора (восстановление из Core).
     *
     * @param array<string, array{
     *   resume_id: string,
     *   resume_title?: string,
     *   source?: string,
     *   reason?: string,
     *   candidates?: list<string>,
     *   suitable_peers?: list<string>
     * }> $rows
     */
    public function replaceAll(array $rows): int
    {
        $all = [];
        foreach ($rows as $ext => $raw) {
            $ext = trim((string) $ext);
            if ($ext === '' || !is_array($raw)) {
                continue;
            }
            $row = $this->normalizeIncomingRow($raw);
            if ($row === null) {
                continue;
            }
            $all[$ext] = $row;
        }
        $this->cache->set(self::CACHE_KEY, $all);

        return count($all);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{
     *   resume_id: string,
     *   resume_title: string,
     *   decided_at: string,
     *   source: string,
     *   reason: string,
     *   candidates: list<string>,
     *   suitable_peers: list<string>
     * }|null
     */
    private function normalizeIncomingRow(array $raw): ?array
    {
        return $this->buildRow(
            (string) ($raw['resume_id'] ?? ''),
            (string) ($raw['resume_title'] ?? ''),
            (string) ($raw['source'] ?? 'first'),
            (string) ($raw['reason'] ?? ''),
            is_array($raw['candidates'] ?? null) ? $raw['candidates'] : [],
            is_array($raw['suitable_peers'] ?? null) ? $raw['suitable_peers'] : [],
        );
    }

    /**
     * @param list<string> $candidates
     * @param list<string> $suitablePeers
     *
     * @return array{
     *   resume_id: string,
     *   resume_title: string,
     *   decided_at: string,
     *   source: string,
     *   reason: string,
     *   candidates: list<string>,
     *   suitable_peers: list<string>
     * }|null
     */
    private function buildRow(
        string $resumeId,
        string $resumeTitle,
        string $source,
        string $reason,
        array $candidates,
        array $suitablePeers,
    ): ?array {
        $resumeId = trim($resumeId);
        if ($resumeId === '') {
            return null;
        }
        $cand = $this->normalizeIdList($candidates);
        $cand[] = $resumeId;
        $cand = array_values(array_unique($cand));

        $suitable = $this->normalizeIdList($suitablePeers);
        if ($suitable === []) {
            $suitable = [$resumeId];
        } else {
            $suitable[] = $resumeId;
            $suitable = array_values(array_unique($suitable));
        }

        return [
            'resume_id' => $resumeId,
            'resume_title' => trim($resumeTitle),
            'decided_at' => gmdate('c'),
            'source' => $source !== '' ? $source : 'first',
            'reason' => trim($reason),
            'candidates' => $cand,
            'suitable_peers' => $suitable,
        ];
    }

    /**
     * @param mixed $raw
     *
     * @return list<string>
     */
    private function normalizeIdList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return array_values(array_unique($out));
    }
}
