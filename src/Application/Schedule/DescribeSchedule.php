<?php
declare(strict_types=1);

namespace JobSearch\Application\Schedule;

/**
 * Снимок планировщика для UI (чаты и др.).
 */
final class DescribeSchedule
{
    public function __construct(
        private readonly ScheduleConfig $config,
        private readonly ScheduleStore $store,
    ) {}

    /**
     * @return array{
     *   running: bool,
     *   pid: int,
     *   started_at: string,
     *   tasks: list<array{
     *     id: string,
     *     label: string,
     *     command: string,
     *     every_seconds: int,
     *     enabled: bool,
     *     last_run_at: string,
     *     last_ok: bool|null,
     *     last_message: string
     *   }>
     * }
     */
    public function snapshot(): array
    {
        $state = $this->store->read();
        $running = $this->store->isDaemonAlive();
        $tasks = [];
        foreach ($this->config->tasks() as $task) {
            $prev = $state['tasks'][$task['id']] ?? null;
            $tasks[] = [
                'id' => $task['id'],
                'label' => $task['label'],
                'command' => $task['command'],
                'every_seconds' => $task['every_seconds'],
                'enabled' => $task['enabled'],
                'last_run_at' => is_array($prev) ? (string) ($prev['last_run_at'] ?? '') : '',
                'last_ok' => is_array($prev) ? ($prev['last_ok'] ?? null) : null,
                'last_message' => is_array($prev) ? (string) ($prev['last_message'] ?? '') : '',
            ];
        }

        return [
            'running' => $running,
            'pid' => $running ? (int) $state['pid'] : 0,
            'started_at' => $running ? (string) $state['started_at'] : '',
            'tasks' => $tasks,
        ];
    }
}
