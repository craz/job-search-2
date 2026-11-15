<?php
declare(strict_types=1);

namespace JobSearch\Application\Schedule;

use JobSearch\Infrastructure\Cache\Cache;

/**
 * Состояние планировщика: last_run задач + pid демона (FileCache).
 */
final class ScheduleStore
{
    public const CACHE_KEY = 'schedule_state';

    public function __construct(private readonly Cache $cache) {}

    /**
     * @return array{
     *   pid: int,
     *   started_at: string,
     *   tasks: array<string, array{last_run_at: string, last_ok: bool|null, last_message: string}>
     * }
     */
    public function read(): array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        $payload = is_array($entry?->payload) ? $entry->payload : [];
        $tasks = [];
        if (is_array($payload['tasks'] ?? null)) {
            foreach ($payload['tasks'] as $id => $row) {
                if (!is_string($id) || !is_array($row)) {
                    continue;
                }
                $tasks[$id] = [
                    'last_run_at' => trim((string) ($row['last_run_at'] ?? '')),
                    'last_ok' => array_key_exists('last_ok', $row) ? (
                        $row['last_ok'] === null ? null : (bool) $row['last_ok']
                    ) : null,
                    'last_message' => trim((string) ($row['last_message'] ?? '')),
                ];
            }
        }

        return [
            'pid' => (int) ($payload['pid'] ?? 0),
            'started_at' => trim((string) ($payload['started_at'] ?? '')),
            'tasks' => $tasks,
        ];
    }

    public function markDaemon(int $pid): void
    {
        $state = $this->read();
        $state['pid'] = $pid;
        $state['started_at'] = gmdate('c');
        $this->cache->set(self::CACHE_KEY, $state);
    }

    public function clearDaemon(): void
    {
        $state = $this->read();
        $state['pid'] = 0;
        $this->cache->set(self::CACHE_KEY, $state);
    }

    public function recordTask(string $taskId, bool $ok, string $message): void
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            return;
        }
        $state = $this->read();
        $state['tasks'][$taskId] = [
            'last_run_at' => gmdate('c'),
            'last_ok' => $ok,
            'last_message' => trim($message),
        ];
        $this->cache->set(self::CACHE_KEY, $state);
    }

    public function isDaemonAlive(): bool
    {
        $pid = $this->read()['pid'];
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir('/proc/' . $pid);
    }
}
