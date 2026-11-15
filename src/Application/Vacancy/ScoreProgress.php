<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

/**
 * Прогресс оценки последней пачки резюме: done = skip+consider, waiting = без Assessment.
 */
final class ScoreProgress
{
    public function __construct(private readonly ListForResume $list) {}

    /**
     * @return array{
     *   ok: bool,
     *   resume_id: string,
     *   total: int,
     *   done: int,
     *   waiting: int,
     *   skip: int,
     *   consider: int,
     *   unscored: int,
     *   pct: int,
     *   active: bool,
     *   message: string
     * }
     */
    public function forResume(string $resumeId): array
    {
        $resumeId = trim($resumeId);
        if ($resumeId === '') {
            return [
                'ok' => false,
                'resume_id' => '',
                'total' => 0,
                'done' => 0,
                'waiting' => 0,
                'skip' => 0,
                'consider' => 0,
                'unscored' => 0,
                'pct' => 0,
                'active' => false,
                'message' => 'Укажите resume.',
            ];
        }

        $summary = $this->list->summaryByResume();
        $meta = $summary[$resumeId] ?? null;
        if (!is_array($meta)) {
            return [
                'ok' => true,
                'resume_id' => $resumeId,
                'total' => 0,
                'done' => 0,
                'waiting' => 0,
                'skip' => 0,
                'consider' => 0,
                'unscored' => 0,
                'pct' => 0,
                'active' => false,
                'message' => 'Нет скачанной пачки для этого резюме.',
            ];
        }

        $skip = (int) ($meta['skip_count'] ?? 0);
        $consider = (int) ($meta['consider_count'] ?? 0);
        $unscored = (int) ($meta['unscored_count'] ?? 0);
        $total = max(0, $skip + $consider + $unscored);
        if ($total === 0) {
            $total = (int) ($meta['count'] ?? 0);
        }
        $done = $skip + $consider;
        $pct = $total > 0 ? (int) min(100, round(100 * $done / $total)) : 0;
        $active = $unscored > 0;

        if ($active) {
            $message = "DevAI по этому резюме: {$done}/{$total}, ждём {$unscored}…";
        } elseif ($total > 0) {
            $message = "По этому резюме оценка пачки: {$done}/{$total}"
                . " (skip {$skip}, смотрим {$consider}).";
        } else {
            $message = 'Оценивать нечего.';
        }

        return [
            'ok' => true,
            'resume_id' => $resumeId,
            'total' => $total,
            'done' => $done,
            'waiting' => $unscored,
            'skip' => $skip,
            'consider' => $consider,
            'unscored' => $unscored,
            'pct' => $pct,
            'active' => $active,
            'message' => $message,
        ];
    }
}
