<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Telegram;

use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;

/**
 * Журнал TG DM: Core Postgres (основное) + локальный JSONL (fallback / offline).
 */
final class TelegramDmJournal
{
    public function __construct(
        private readonly string $jsonlPath,
        private readonly ?CoreApi $core = null,
    ) {}

    /**
     * @param array<string, mixed> $meta
     */
    public function write(
        string $direction,
        string $kind,
        string $text,
        string $vacancyId = '',
        string $resumeId = '',
        array $meta = [],
        string $turnId = '',
    ): void {
        $direction = strtolower(trim($direction));
        if (!in_array($direction, ['out', 'in', 'meta'], true)) {
            $direction = 'meta';
        }
        $kind = trim($kind) !== '' ? trim($kind) : 'other';
        $at = gmdate('c');
        $pid = getmypid() ?: 0;
        $row = [
            'at' => $at,
            'direction' => $direction,
            'kind' => $kind,
            'vacancy_id' => trim($vacancyId),
            'resume_id' => trim($resumeId),
            'text' => $text,
            'meta_json' => $meta === [] ? '' : (string) json_encode($meta, JSON_UNESCAPED_UNICODE),
            'pid' => $pid,
            'turn_id' => trim($turnId),
        ];
        $this->appendJsonl($row);
        if ($this->core === null) {
            return;
        }
        try {
            $this->core->createTelegramDmEvent([
                'at' => $at,
                'direction' => $direction,
                'kind' => $kind,
                'vacancy_external_id' => trim($vacancyId),
                'resume_external_id' => trim($resumeId),
                'text' => $text,
                'meta_json' => $row['meta_json'],
                'pid' => $pid,
                'turn_id' => trim($turnId),
            ]);
        } catch (CoreException $e) {
            fwrite(STDERR, 'TG_DM_LOG Core fail: ' . $e->getMessage() . "\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, 'TG_DM_LOG Core fail: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * Сначала Core; если пусто/ошибка — хвост JSONL.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        if ($this->core !== null) {
            try {
                $payload = $this->core->listTelegramDmEvents(null, null, $limit);
                $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
                if ($items !== []) {
                    $out = [];
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $out[] = [
                            'at' => (string) ($item['at'] ?? ''),
                            'direction' => (string) ($item['direction'] ?? ''),
                            'kind' => (string) ($item['kind'] ?? ''),
                            'vacancy_id' => (string) ($item['vacancy_external_id'] ?? ''),
                            'resume_id' => (string) ($item['resume_external_id'] ?? ''),
                            'text' => (string) ($item['text'] ?? ''),
                            'meta_json' => (string) ($item['meta_json'] ?? ''),
                            'pid' => (int) ($item['pid'] ?? 0),
                            'turn_id' => (string) ($item['turn_id'] ?? ''),
                        ];
                    }

                    return $out;
                }
            } catch (\Throwable) {
                // fallback JSONL
            }
        }

        return $this->recentJsonl($limit);
    }

    /** @param array<string, mixed> $row */
    private function appendJsonl(array $row): void
    {
        try {
            $dir = dirname($this->jsonlPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Не создать каталог TG journal: ' . $dir);
            }
            $line = json_encode($row, JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                return;
            }
            $fh = fopen($this->jsonlPath, 'ab');
            if ($fh === false) {
                return;
            }
            try {
                flock($fh, LOCK_EX);
                fwrite($fh, $line . "\n");
            } finally {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'TG_DM_LOG jsonl fail: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentJsonl(int $limit): array
    {
        if (!is_readable($this->jsonlPath)) {
            return [];
        }
        $raw = file($this->jsonlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false || $raw === []) {
            return [];
        }
        $slice = array_slice($raw, -$limit);
        $out = [];
        foreach (array_reverse($slice) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }
}
