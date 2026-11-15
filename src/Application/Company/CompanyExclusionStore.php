<?php
declare(strict_types=1);

namespace JobSearch\Application\Company;

use JobSearch\Application\Resume\ResumeViewsStore;
use JobSearch\Infrastructure\Cache\Cache;

/**
 * Компании, куда нет смысла откликаться (ручной/сид список, FileCache).
 */
final class CompanyExclusionStore
{
    public const CACHE_KEY = 'company_exclusions';

    public function __construct(private readonly Cache $cache) {}

    /**
     * @return list<array{
     *   id: string,
     *   company_external_id: string,
     *   company_name: string,
     *   reason: string,
     *   source: string,
     *   added_at: string
     * }>
     */
    public function all(): array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        $payload = $entry?->payload;
        if (!is_array($payload)) {
            return [];
        }
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['company_name'] ?? ''));
            $ext = trim((string) ($row['company_external_id'] ?? ''));
            if ($name === '' && $ext === '') {
                continue;
            }
            $out[] = [
                'id' => trim((string) ($row['id'] ?? '')),
                'company_external_id' => $ext,
                'company_name' => $name,
                'reason' => trim((string) ($row['reason'] ?? '')),
                'source' => trim((string) ($row['source'] ?? '')),
                'added_at' => trim((string) ($row['added_at'] ?? '')),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return mb_strtolower($a['company_name'], 'UTF-8')
                <=> mb_strtolower($b['company_name'], 'UTF-8');
        });

        return $out;
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * @return array{by_id: array<string, string>, by_name: array<string, string>}
     */
    public function index(): array
    {
        /** @var array<string, string> $byId */
        $byId = [];
        /** @var array<string, string> $byName */
        $byName = [];
        foreach ($this->all() as $row) {
            $tip = $row['reason'] !== '' ? $row['reason'] : 'исключение';
            $ext = $row['company_external_id'];
            if ($ext !== '' && !str_starts_with($ext, 'name:')) {
                $byId[$ext] = $tip;
            }
            $norm = ResumeViewsStore::normalizeName($row['company_name']);
            if ($norm !== '') {
                $byName[$norm] = $tip;
            }
        }

        return ['by_id' => $byId, 'by_name' => $byName];
    }

    /**
     * Upsert по employer id или нормализованному имени.
     *
     * @param list<array{
     *   company_external_id?: string,
     *   company_name?: string,
     *   reason?: string,
     *   source?: string
     * }> $rows
     *
     * @return int сколько новых добавлено
     */
    public function upsertMany(array $rows): int
    {
        $items = $this->all();
        /** @var array<string, int> $byExt */
        $byExt = [];
        /** @var array<string, int> $byNorm */
        $byNorm = [];
        foreach ($items as $i => $row) {
            if ($row['company_external_id'] !== '') {
                $byExt[$row['company_external_id']] = $i;
            }
            $norm = ResumeViewsStore::normalizeName($row['company_name']);
            if ($norm !== '') {
                $byNorm[$norm] = $i;
            }
        }

        $added = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['company_name'] ?? ''));
            $ext = trim((string) ($row['company_external_id'] ?? ''));
            if ($ext !== '' && str_starts_with($ext, 'name:')) {
                $ext = '';
            }
            if ($name === '' && $ext === '') {
                continue;
            }
            $reason = trim((string) ($row['reason'] ?? ''));
            if ($reason === '') {
                $reason = 'исключение';
            }
            $source = trim((string) ($row['source'] ?? ''));
            if ($source === '') {
                $source = 'manual';
            }
            $norm = ResumeViewsStore::normalizeName($name);
            $idx = null;
            if ($ext !== '' && isset($byExt[$ext])) {
                $idx = $byExt[$ext];
            } elseif ($norm !== '' && isset($byNorm[$norm])) {
                $idx = $byNorm[$norm];
            }

            if ($idx !== null) {
                $cur = $items[$idx];
                if ($ext !== '' && $cur['company_external_id'] === '') {
                    $cur['company_external_id'] = $ext;
                }
                if ($name !== '' && $cur['company_name'] === '') {
                    $cur['company_name'] = $name;
                }
                if ($reason !== '' && $cur['reason'] === '') {
                    $cur['reason'] = $reason;
                }
                $items[$idx] = $cur;
                continue;
            }

            $items[] = [
                'id' => bin2hex(random_bytes(8)),
                'company_external_id' => $ext,
                'company_name' => $name !== '' ? $name : $ext,
                'reason' => $reason,
                'source' => $source,
                'added_at' => gmdate('c'),
            ];
            $added++;
            $newIdx = count($items) - 1;
            if ($ext !== '') {
                $byExt[$ext] = $newIdx;
            }
            if ($norm !== '') {
                $byNorm[$norm] = $newIdx;
            }
        }

        $this->cache->set(self::CACHE_KEY, ['items' => array_values($items)]);

        return $added;
    }

    public function clear(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * @param array{by_id: array<string, string>, by_name: array<string, string>} $index
     * @param array<string, mixed> $vacancy
     */
    public static function hintFor(array $index, array $vacancy): ?string
    {
        // Список исключений (id / точное имя).
        $fromList = CompanyRejectionIndex::hintFor($index, $vacancy);
        if ($fromList !== null) {
            return $fromList;
        }
        // Сбер и экосистема — всегда, даже если сид ещё не успел записать имя в стор.
        // Иначе «ООО Страховая компания Сбербанк страхование» проходит в Смотрим/отклик.
        $name = trim((string) ($vacancy['company_name'] ?? ''));
        if (self::looksLikeSber($name)) {
            return 'Сбер / экосистема — не подаёмся';
        }

        return null;
    }

    /** Сбер / Sber / Сбербанк / Sberbank / SberTech… (без ложных вроде «Айсберри»). */
    public static function looksLikeSber(string $name): bool
    {
        $n = mb_strtolower(trim($name), 'UTF-8');
        if ($n === '') {
            return false;
        }
        // «ООО … Сбербанк …» — сбербанк не в начале строки.
        if (str_contains($n, 'сбербанк') || str_contains($n, 'sberbank')) {
            return true;
        }
        if (str_contains($n, 'sbertech') || str_contains($n, 'сбертех')) {
            return true;
        }
        if (str_contains($n, 'университет сбер')) {
            return true;
        }
        // «Сбер. IT», «Сберздоровье» — префикс после юр. формы.
        $bare = preg_replace('/^(ооо|ао|пао|зао|оао|ип)\s+/u', '', $n) ?? $n;
        $bare = trim($bare);
        if (str_starts_with($bare, 'сбер') || str_starts_with($bare, 'sber')) {
            return true;
        }

        return false;
    }
}
