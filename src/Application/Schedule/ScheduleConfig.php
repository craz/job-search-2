<?php
declare(strict_types=1);

namespace JobSearch\Application\Schedule;

/**
 * Читает config/schedule.json — список периодических CLI-задач.
 */
final class ScheduleConfig
{
    public function __construct(private readonly string $path) {}

    /**
     * @return list<array{
     *   id: string,
     *   command: string,
     *   every_seconds: int,
     *   enabled: bool,
     *   label: string
     * }>
     */
    public function tasks(): array
    {
        if (!is_readable($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $items = is_array($decoded['tasks'] ?? null) ? $decoded['tasks'] : [];
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            $command = trim((string) ($row['command'] ?? ''));
            if ($id === '' || $command === '') {
                continue;
            }
            $every = (int) ($row['every_seconds'] ?? 0);
            if ($every < 60) {
                $every = 60;
            }
            $out[] = [
                'id' => $id,
                'command' => $command,
                'every_seconds' => $every,
                'enabled' => ($row['enabled'] ?? true) !== false,
                'label' => trim((string) ($row['label'] ?? $id)),
            ];
        }

        return $out;
    }
}
