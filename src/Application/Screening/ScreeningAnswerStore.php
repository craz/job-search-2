<?php
declare(strict_types=1);

namespace JobSearch\Application\Screening;

use JobSearch\Domain\Applicant\ScreeningAnswerMemory;
use JobSearch\Infrastructure\Cache\Cache;

/**
 * Learned-ответы на анкеты HH: FileCache + опциональный сид из resume.
 */
final class ScreeningAnswerStore implements ScreeningAnswerMemory
{
    private const CACHE_KEY = 'screening_answers_learned';
    public const DEFAULT_SEED = '/data/Projects/resume/drafts/screening-answers-learned.json';

    public function __construct(
        private readonly Cache $cache,
        private readonly string $seedPath = self::DEFAULT_SEED,
        private readonly string $localSeedPath = '',
    ) {}

    public function find(string $question): ?string
    {
        $key = self::norm($question);
        if ($key === '') {
            return null;
        }
        $items = $this->items();
        foreach ($items as $it) {
            $nk = (string) ($it['norm'] ?? self::norm((string) ($it['question'] ?? '')));
            if ($nk === $key) {
                $answer = trim((string) ($it['answer'] ?? ''));

                return $answer !== '' ? $answer : null;
            }
        }
        foreach ($items as $it) {
            $nk = (string) ($it['norm'] ?? self::norm((string) ($it['question'] ?? '')));
            if ($nk === '' || mb_strlen($nk) < 24) {
                continue;
            }
            if (str_contains($key, $nk) || str_contains($nk, $key)) {
                $answer = trim((string) ($it['answer'] ?? ''));

                return $answer !== '' ? $answer : null;
            }
        }

        return null;
    }

    public function remember(
        array $pairs,
        string $source = 'tg',
        ?string $vacancyId = null,
        ?string $title = null,
    ): int {
        $items = $this->items();
        $now = time();
        $changed = 0;
        foreach ($pairs as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            $q = trim((string) $pair[0]);
            $a = trim((string) $pair[1]);
            if ($q === '' || $a === '' || in_array($a, ['-', '—', '–', '.'], true)) {
                continue;
            }
            $items[] = [
                'question' => mb_substr($q, 0, 500),
                'answer' => mb_substr($a, 0, 4000),
                'source' => $source !== '' ? $source : 'tg',
                'vacancy_id' => $vacancyId,
                'title' => $title,
                'ts' => $now,
            ];
            $changed++;
        }
        if ($changed === 0) {
            return 0;
        }
        $this->saveItems($items);

        return $changed;
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        $payload = is_array($entry?->payload) ? $entry->payload : null;
        $cached = is_array($payload['items'] ?? null)
            ? array_values(array_filter($payload['items'], 'is_array'))
            : [];
        $seeded = $this->seedItems();
        if ($cached === [] && $seeded !== []) {
            $this->saveItems($seeded);

            return $seeded;
        }
        if ($seeded === []) {
            return $cached;
        }
        // Подмешать новые seed-ответы, не затирая уже выученные.
        $byKey = [];
        foreach ($cached as $it) {
            $k = self::norm((string) ($it['question'] ?? ''));
            if ($k !== '') {
                $byKey[$k] = $it;
            }
        }
        $added = 0;
        foreach ($seeded as $it) {
            $k = self::norm((string) ($it['question'] ?? ''));
            if ($k === '' || isset($byKey[$k])) {
                continue;
            }
            $byKey[$k] = $it;
            $added++;
        }
        if ($added > 0) {
            $merged = array_values($byKey);
            $this->saveItems($merged);

            return $merged;
        }

        return $cached;
    }

    /** @param list<array<string, mixed>> $items */
    private function saveItems(array $items): void
    {
        $byKey = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $key = self::norm((string) ($it['question'] ?? ''));
            $answer = trim((string) ($it['answer'] ?? ''));
            if ($key === '' || $answer === '') {
                continue;
            }
            $byKey[$key] = [
                'question' => mb_substr(trim((string) ($it['question'] ?? '')), 0, 500),
                'answer' => mb_substr($answer, 0, 4000),
                'norm' => $key,
                'source' => (string) ($it['source'] ?? 'tg'),
                'vacancy_id' => $it['vacancy_id'] ?? null,
                'title' => $it['title'] ?? null,
                'ts' => isset($it['ts']) ? (float) $it['ts'] : time(),
            ];
        }
        $this->cache->set(self::CACHE_KEY, [
            'version' => 1,
            'updated_at' => time(),
            'items' => array_values($byKey),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function seedItems(): array
    {
        $out = [];
        foreach ([$this->localSeedPath, $this->seedPath] as $path) {
            foreach ($this->loadSeedFile($path) as $it) {
                $out[] = $it;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function loadSeedFile(string $path): array
    {
        if ($path === '' || !is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($decoded['items'] as $it) {
            if (is_array($it)) {
                $out[] = $it;
            }
        }

        return $out;
    }

    public static function norm(string $text): string
    {
        $t = mb_strtolower($text);
        $t = str_replace("\u{00a0}", ' ', $t);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = preg_replace('/[«»"\'„“]/u', '', $t) ?? $t;
        $t = trim($t);

        return mb_substr($t, 0, 220);
    }
}
