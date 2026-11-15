<?php
declare(strict_types=1);

namespace JobSearch\Application\JobApplication;

use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;

/**
 * Сколько откликов сегодня (Europe/Moscow) по каждому resume_version.
 */
final class CountTodayApplies
{
    public const GOAL_PER_RESUME = 10;

    private const TZ = 'Europe/Moscow';

    public function __construct(private readonly CoreApi $core) {}

    /**
     * @return array{
     *   day: string,
     *   goal: int,
     *   by_resume: array<string, int>,
     *   total_today: int
     * }
     */
    public function summarize(): array
    {
        $tz = new \DateTimeZone(self::TZ);
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $byResume = [];
        $total = 0;

        try {
            $apps = $this->core->listApplications();
        } catch (CoreException) {
            return [
                'day' => $today,
                'goal' => self::GOAL_PER_RESUME,
                'by_resume' => [],
                'total_today' => 0,
            ];
        }

        foreach ($apps['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $resumeId = trim((string) ($row['resume_version'] ?? ''));
            if ($resumeId === '') {
                continue;
            }
            $at = trim((string) ($row['applied_at'] ?? $row['created_at'] ?? ''));
            if ($at === '' || !$this->isSameMoscowDay($at, $today, $tz)) {
                continue;
            }
            $byResume[$resumeId] = ($byResume[$resumeId] ?? 0) + 1;
            $total++;
        }

        return [
            'day' => $today,
            'goal' => self::GOAL_PER_RESUME,
            'by_resume' => $byResume,
            'total_today' => $total,
        ];
    }

    private function isSameMoscowDay(string $iso, string $today, \DateTimeZone $tz): bool
    {
        try {
            $dt = new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return false;
        }

        return $dt->setTimezone($tz)->format('Y-m-d') === $today;
    }
}
