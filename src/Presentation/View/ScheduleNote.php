<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * Текст статуса авто-задачи планировщика (SSR + JSON для live-poll).
 */
final class ScheduleNote
{
    /**
     * @param array{
     *   running?: bool,
     *   tasks?: list<array<string, mixed>>
     * } $schedule
     */
    public static function forTask(array $schedule, string $taskId, string $autoLabel = 'Авто'): string
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            return '';
        }
        /** @var list<array<string, mixed>> $tasks */
        $tasks = is_array($schedule['tasks'] ?? null) ? $schedule['tasks'] : [];
        $task = null;
        foreach ($tasks as $row) {
            if (($row['id'] ?? '') === $taskId) {
                $task = $row;
                break;
            }
        }
        if ($task === null) {
            return '';
        }
        $every = (int) ($task['every_seconds'] ?? 600);
        $mins = max(1, (int) round($every / 60));
        $last = RuDate::dateTime($task['last_run_at'] ?? null);
        $parts = [];
        if (!empty($schedule['running'])) {
            $parts[] = trim($autoLabel) . ' каждые ' . $mins . ' мин';
        } else {
            $parts[] = trim($autoLabel) . ' выключен (php bin/hh.php schedule)';
        }
        if ($last !== '') {
            $ok = $task['last_ok'] ?? null;
            $parts[] = 'последний: ' . $last
                . ($ok === true ? ' · ok' : ($ok === false ? ' · ошибка' : ''));
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array{
     *   running?: bool,
     *   tasks?: list<array<string, mixed>>
     * } $schedule
     */
    public static function chats(array $schedule): string
    {
        return self::forTask($schedule, 'chats', 'Авто');
    }

    /**
     * @param array{
     *   running?: bool,
     *   tasks?: list<array<string, mixed>>
     * } $schedule
     */
    public static function resumeViews(array $schedule): string
    {
        return self::forTask($schedule, 'resume_views', 'Просмотры');
    }

    /**
     * @param array{
     *   running?: bool,
     *   tasks?: list<array<string, mixed>>
     * } $schedule
     */
    public static function vacancies(array $schedule): string
    {
        return self::forTask($schedule, 'vacancies', 'Скачивание');
    }
}
