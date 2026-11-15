<?php
declare(strict_types=1);

namespace JobSearch\Application\Schedule;

/**
 * Долгоживущий цикл: каждые ~30 с проверяет due-задачи из config/schedule.json.
 */
final class ScheduleDaemon
{
    private const TICK_SLEEP_SEC = 30;

    public function __construct(
        private readonly RunScheduledTasks $runner,
        private readonly ScheduleStore $store,
        private readonly string $pidPath,
        private readonly string $logPath,
    ) {}

    public function run(): int
    {
        if ($this->store->isDaemonAlive()) {
            $pid = $this->store->read()['pid'];
            fwrite(STDERR, "Schedule уже запущен (pid {$pid}).\n");

            return 2;
        }

        $pid = getmypid() ?: 0;
        $this->store->markDaemon($pid);
        file_put_contents($this->pidPath, (string) $pid . "\n");
        $this->log('schedule daemon start pid=' . $pid);

        // Сразу прогоняем due (в т.ч. первый chats, если ещё не было last_run).
        $this->safeTick(forceAll: false);

        while (true) {
            sleep(self::TICK_SLEEP_SEC);
            $this->safeTick(forceAll: false);
        }
    }

    private function safeTick(bool $forceAll): void
    {
        try {
            $results = $this->runner->tick($forceAll);
            foreach ($results as $row) {
                if (!$row['ran']) {
                    if ($row['skipped'] === 'job_busy') {
                        $this->log('skip ' . $row['id'] . ': job_busy');
                    }
                    continue;
                }
                $ok = !empty($row['ok']) ? 'ok' : 'fail';
                $this->log($row['id'] . ' ' . $ok . ' — ' . $row['message']);
            }
        } catch (\Throwable $e) {
            $this->log('tick error: ' . $e->getMessage());
        }
    }

    private function log(string $line): void
    {
        $stamp = gmdate('Y-m-d H:i:s') . 'Z';
        @file_put_contents(
            $this->logPath,
            '[' . $stamp . '] ' . $line . "\n",
            FILE_APPEND,
        );
    }
}
