<?php
declare(strict_types=1);

namespace JobSearch\Application\Resume;

use JobSearch\Domain\Resume\ResumeViewEvent;
use JobSearch\Infrastructure\Cache\Cache;

/**
 * Локальная карта просмотров резюме: resume_hash → employer_id → {name, viewed_at[]}.
 */
final class ResumeViewsStore
{
    public const CACHE_KEY = 'resume_views';

    public function __construct(private readonly Cache $cache) {}

    /**
     * @return array<string, array{
     *   company_name: string,
     *   viewed_at: list<string>
     * }>
     */
    public function employersForResume(string $resumeId): array
    {
        $resumeId = trim($resumeId);
        if ($resumeId === '') {
            return [];
        }
        $all = $this->all();
        $bucket = $all[$resumeId] ?? null;
        if (!is_array($bucket)) {
            return [];
        }
        $employers = is_array($bucket['employers'] ?? null) ? $bucket['employers'] : [];
        $out = [];
        foreach ($employers as $employerId => $row) {
            $employerId = trim((string) $employerId);
            if ($employerId === '' || !is_array($row)) {
                continue;
            }
            $times = [];
            foreach (is_array($row['viewed_at'] ?? null) ? $row['viewed_at'] : [] as $t) {
                $t = trim((string) $t);
                if ($t !== '') {
                    $times[] = $t;
                }
            }
            sort($times);
            $out[$employerId] = [
                'company_name' => trim((string) ($row['company_name'] ?? '')),
                'viewed_at' => array_values(array_unique($times)),
            ];
        }

        return $out;
    }

    /**
     * Заменить карту работодателей для резюме (полный снимок после синка).
     *
     * @param list<ResumeViewEvent> $events
     */
    public function replaceForResume(string $resumeId, array $events): int
    {
        $resumeId = trim($resumeId);
        if ($resumeId === '') {
            return 0;
        }

        /** @var array<string, array{company_name: string, viewed_at: list<string>}> $employers */
        $employers = [];
        foreach ($events as $event) {
            if (!$event instanceof ResumeViewEvent) {
                continue;
            }
            $employerId = trim($event->employerId);
            if ($employerId === '') {
                // без id — ключ по нормализованному имени
                $employerId = 'name:' . mb_strtolower(trim($event->companyName), 'UTF-8');
            }
            if ($employerId === 'name:') {
                continue;
            }
            if (!isset($employers[$employerId])) {
                $employers[$employerId] = [
                    'company_name' => trim($event->companyName),
                    'viewed_at' => [],
                ];
            }
            if ($employers[$employerId]['company_name'] === '' && $event->companyName !== '') {
                $employers[$employerId]['company_name'] = trim($event->companyName);
            }
            $at = trim($event->viewedAt);
            if ($at !== '') {
                $employers[$employerId]['viewed_at'][] = $at;
            }
        }

        foreach ($employers as $id => $row) {
            $times = array_values(array_unique($row['viewed_at']));
            sort($times);
            $employers[$id]['viewed_at'] = $times;
        }

        $all = $this->all();
        $all[$resumeId] = [
            'synced_at' => gmdate('c'),
            'employers' => $employers,
        ];
        $this->cache->set(self::CACHE_KEY, $all);

        return count($employers);
    }

    /**
     * Последний просмотр по employer_id (или null).
     *
     * @param array<string, array{company_name: string, viewed_at: list<string>}> $employers
     */
    public static function latestAt(array $employers, string $employerId): ?string
    {
        $employerId = trim($employerId);
        if ($employerId === '') {
            return null;
        }
        $row = $employers[$employerId] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $times = is_array($row['viewed_at'] ?? null) ? $row['viewed_at'] : [];
        if ($times === []) {
            return null;
        }

        return (string) $times[array_key_last($times)];
    }

    /**
     * Последний просмотр по имени компании (нормализованное сравнение).
     *
     * @param array<string, array{company_name: string, viewed_at: list<string>}> $employers
     */
    public static function latestAtByCompanyName(array $employers, string $companyName): ?string
    {
        $want = self::normalizeName($companyName);
        if ($want === '') {
            return null;
        }
        $best = null;
        foreach ($employers as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (self::normalizeName((string) ($row['company_name'] ?? '')) !== $want) {
                continue;
            }
            $times = is_array($row['viewed_at'] ?? null) ? $row['viewed_at'] : [];
            if ($times === []) {
                continue;
            }
            $at = (string) $times[array_key_last($times)];
            if ($best === null || $at > $best) {
                $best = $at;
            }
        }

        return $best;
    }

    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        // HH views и вакансии пишут форму по-разному: «ООО РСХБ-Интех» vs «РСХБ-Интех».
        $name = preg_replace(
            '/^(ооо|ао|пао|зао|оао|ип|гбу|гку|фгуп|муп|пк|нко)\s+/u',
            '',
            $name,
        ) ?? $name;
        $name = preg_replace('/[\"«»„“”]/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        if ($entry === null || !is_array($entry->payload)) {
            return [];
        }

        return $entry->payload;
    }
}
