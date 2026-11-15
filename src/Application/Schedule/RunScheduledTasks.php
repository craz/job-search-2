<?php
declare(strict_types=1);

namespace JobSearch\Application\Schedule;

/**
 * Один тик планировщика: запускает due-задачи через `php bin/hh.php <command>`.
 * Пропускает, если UI-job уже running (общий job.status.json).
 */
final class RunScheduledTasks
{
    /** Команды, которые можно гонять из schedule (белый список). */
    private const ALLOWED = [
        'chats',
        'vacancies',
        'resume-views',
        'leave-rejections',
        'leave-old',
        'refresh',
    ];

    public function __construct(
        private readonly string $root,
        private readonly ScheduleConfig $config,
        private readonly ScheduleStore $store,
        private readonly string $jobStatusPath,
        private readonly string $phpBinary = PHP_BINARY,
    ) {}

    /**
     * @return list<array{id: string, ran: bool, skipped: string, ok: bool|null, message: string}>
     */
    public function tick(bool $forceAll = false): array
    {
        $now = time();
        $state = $this->store->read();
        $busy = $this->jobIsBusy();
        $results = [];

        foreach ($this->config->tasks() as $task) {
            if (!$task['enabled']) {
                $results[] = [
                    'id' => $task['id'],
                    'ran' => false,
                    'skipped' => 'disabled',
                    'ok' => null,
                    'message' => '',
                ];
                continue;
            }
            $prev = $state['tasks'][$task['id']] ?? null;
            $lastAt = is_array($prev) ? (strtotime((string) ($prev['last_run_at'] ?? '')) ?: 0) : 0;
            $due = $forceAll || $lastAt === 0 || ($now - $lastAt) >= $task['every_seconds'];
            if (!$due) {
                $results[] = [
                    'id' => $task['id'],
                    'ran' => false,
                    'skipped' => 'not_due',
                    'ok' => null,
                    'message' => '',
                ];
                continue;
            }
            if ($busy) {
                $results[] = [
                    'id' => $task['id'],
                    'ran' => false,
                    'skipped' => 'job_busy',
                    'ok' => null,
                    'message' => 'UI-job занят — пропуск.',
                ];
                continue;
            }
            if (!in_array($task['command'], self::ALLOWED, true)) {
                $results[] = [
                    'id' => $task['id'],
                    'ran' => false,
                    'skipped' => 'forbidden',
                    'ok' => false,
                    'message' => 'Команда не в белом списке: ' . $task['command'],
                ];
                continue;
            }

            $run = $this->runCommand($task['command']);
            $this->store->recordTask($task['id'], $run['ok'], $run['message']);
            $results[] = [
                'id' => $task['id'],
                'ran' => true,
                'skipped' => '',
                'ok' => $run['ok'],
                'message' => $run['message'],
            ];
            // Одна задача за тик — не гоняем Playwright пачкой.
            break;
        }

        return $results;
    }

    /** @return array{ok: bool, message: string} */
    private function runCommand(string $command): array
    {
        $php = $this->phpBinary !== '' ? $this->phpBinary : 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($this->root . '/bin/hh.php')
            . ' ' . escapeshellarg($command);
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        $text = trim(implode("\n", $output));
        $message = $text;
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $message = trim((string) ($decoded['message'] ?? $text));
        }
        if ($message === '') {
            $message = $code === 0 ? 'ok' : 'exit ' . $code;
        }

        return ['ok' => $code === 0, 'message' => $message];
    }

    private function jobIsBusy(): bool
    {
        if (!is_readable($this->jobStatusPath)) {
            return false;
        }
        $raw = file_get_contents($this->jobStatusPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return false;
        }
        $state = strtolower(trim((string) ($data['state'] ?? '')));
        if ($state !== 'running') {
            return false;
        }
        $updated = (int) ($data['updated_at'] ?? 0);
        // Зависший running старше 30 минут не блокируем навсегда.
        if ($updated > 0 && (time() - $updated) > 1800) {
            return false;
        }

        return true;
    }
}
