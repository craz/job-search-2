<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;
use JobSearch\Domain\Scoring\ScoringApi;
use JobSearch\Domain\Scoring\ScoringException;

/**
 * Use case: догон DevAI — неоценённые по всем suitable-run резюме + owner-stub'ы.
 */
final class ScoreTen
{
    /** Догон только по свежему окну — иначе обход всех historical run слишком тяжёлый. */
    private const CATCHUP_DAYS = 7;

    public function __construct(
        private readonly CoreApi $core,
        private readonly ScoringApi $scoring,
        private readonly SyncResumeContext $syncResume,
        private readonly ?VacancyResumeOwnerStore $owners = null,
    ) {}

    /**
     * Догон по всем резюме (кнопка «Оценить оставшиеся»).
     *
     * @param list<string> $resumeIds
     *
     * @return array{ok: bool, enqueued: int, message: string, vacancy_ids?: list<string>, by_resume?: array<string, int>}
     */
    public function forAllResumes(array $resumeIds, int $limitPerResume = 40): array
    {
        $total = 0;
        $ids = [];
        $byResume = [];
        $ok = true;
        $parts = [];
        foreach ($resumeIds as $raw) {
            $resumeId = trim((string) $raw);
            if ($resumeId === '') {
                continue;
            }
            $result = $this->forResume($resumeId, $limitPerResume);
            $n = (int) ($result['enqueued'] ?? 0);
            $byResume[$resumeId] = $n;
            $total += $n;
            foreach ($result['vacancy_ids'] ?? [] as $vid) {
                $vid = trim((string) $vid);
                if ($vid !== '') {
                    $ids[] = $vid;
                }
            }
            if (empty($result['ok'])) {
                $ok = false;
                $msg = trim((string) ($result['message'] ?? ''));
                if ($msg !== '') {
                    $parts[] = $msg;
                }
            }
        }

        if ($total > 0) {
            return [
                'ok' => true,
                'enqueued' => $total,
                'message' => 'Оценил оставшиеся через DevAI: ' . $total . ' '
                    . self::vacancyWord($total) . '.',
                'vacancy_ids' => array_values(array_unique($ids)),
                'by_resume' => $byResume,
            ];
        }

        return [
            'ok' => $ok,
            'enqueued' => 0,
            'message' => $parts !== []
                ? implode(' ', $parts)
                : 'Неоценённых не осталось — догонять нечего.',
            'vacancy_ids' => [],
            'by_resume' => $byResume,
        ];
    }

    /**
     * До $limit неоценённых: все suitable-run резюме + owner (не только последний run).
     *
     * @return array{ok: bool, enqueued: int, message: string, vacancy_ids?: list<string>, raw?: array<string, mixed>}
     */
    public function forResume(string $resumeId, int $limit = 10): array
    {
        $resumeId = trim($resumeId);
        if ($resumeId === '') {
            return ['ok' => false, 'enqueued' => 0, 'message' => 'Укажите resume.'];
        }

        $sync = $this->syncResume->ensureSynced($resumeId);
        if (!$sync['ok']) {
            return [
                'ok' => false,
                'enqueued' => 0,
                'message' => $sync['message'],
                'sync' => $sync,
            ];
        }

        try {
            $ids = $this->unscoredVacancyIdsForResume($resumeId, $limit);
        } catch (CoreException $e) {
            return ['ok' => false, 'enqueued' => 0, 'message' => $e->getMessage(), 'sync' => $sync];
        }

        if ($ids === []) {
            return [
                'ok' => true,
                'enqueued' => 0,
                'message' => 'Все скачанные по этому резюме уже оценены DevAI — оценивать нечего.',
                'vacancy_ids' => [],
                'sync' => $sync,
            ];
        }

        $markedActive = $this->ensureActiveSourceStatus($ids);

        try {
            $raw = $this->scoring->enqueueSemanticBulk($ids, 'devai_auto');
        } catch (ScoringException $e) {
            return [
                'ok' => false,
                'enqueued' => 0,
                'message' => $e->getMessage(),
                'vacancy_ids' => $ids,
                'marked_active' => $markedActive,
                'sync' => $sync,
            ];
        }

        $enqueued = (int) ($raw['enqueued'] ?? 0);
        $failed = (int) ($raw['failed'] ?? 0);
        $alreadyQueued = (int) ($raw['already_queued'] ?? 0);
        $alreadyScored = (int) ($raw['already_scored'] ?? 0);
        $skippedRows = is_array($raw['skipped'] ?? null) ? $raw['skipped'] : [];
        $skipped = count($skippedRows);
        $skipCode = '';
        if ($skippedRows !== [] && is_array($skippedRows[0])) {
            $skipCode = (string) ($skippedRows[0]['code'] ?? '');
        }

        if ($enqueued > 0) {
            $message = "Оценил через DevAI {$enqueued} "
                . self::vacancyWord($enqueued)
                . ' (промпт из config/scoring). Смотри вердикты на странице вакансий.';
            $ok = true;
        } elseif ($alreadyQueued > 0 || $alreadyScored > 0) {
            if ($alreadyQueued > 0 && $alreadyScored === 0) {
                $message = "Уже в очереди DevAI ({$alreadyQueued}) — оценка прямо сейчас идёт. Смотри полоску у резюме, повторно слать не надо.";
            } elseif ($alreadyQueued > 0) {
                $message = "Часть уже в очереди ({$alreadyQueued}), часть уже оценена ({$alreadyScored}). Нового слать нечего — смотри полоску.";
            } else {
                $message = "Эти уже оценены ({$alreadyScored}). Нового не ставил.";
            }
            $ok = true;
        } else {
            $message = 'Не смог оценить через DevAI'
                . ($failed > 0 ? " (ошибок {$failed})" : '')
                . ($skipped > 0 ? " (пропуск {$skipped}" . ($skipCode !== '' ? ": {$skipCode}" : '') . ')' : '')
                . '.';
            $ok = false;
        }

        return [
            'ok' => $ok,
            'enqueued' => $enqueued,
            'message' => $message,
            'vacancy_ids' => $ids,
            'marked_active' => $markedActive,
            'sync' => $sync,
            'raw' => $raw,
        ];
    }

    /**
     * Сразу оценить свежескачанную вакансию; вернуть вердикт и флаг apply/maybe.
     *
     * @return array{ok: bool, enqueued: int, verdict: string, is_consider: bool, message: string}
     */
    public function scoreFreshVacancy(string $resumeId, string $vacancyId, bool $forceRescore = false): array
    {
        $result = $this->forVacancy($resumeId, $vacancyId, $forceRescore);
        $verdict = $this->verdictFromScoreResult($result, $vacancyId);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'enqueued' => (int) ($result['enqueued'] ?? 0),
            'verdict' => $verdict,
            'is_consider' => $this->isConsiderVerdict($verdict),
            'message' => (string) ($result['message'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function verdictFromScoreResult(array $result, string $vacancyId): string
    {
        $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
        $results = is_array($raw['results'] ?? null) ? $raw['results'] : [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['vacancy_id'] ?? '')) !== $vacancyId) {
                continue;
            }
            $v = strtolower(trim((string) ($row['verdict'] ?? '')));
            if ($v !== '') {
                return $v;
            }
        }

        return $this->latestVerdict($vacancyId);
    }

    private function latestVerdict(string $vacancyId): string
    {
        try {
            $list = $this->core->listAssessments($vacancyId, 1);
        } catch (CoreException) {
            return '';
        }
        $row = $list['items'][0] ?? null;
        if (!is_array($row)) {
            return '';
        }

        return strtolower(trim((string) ($row['verdict'] ?? '')));
    }

    private function isConsiderVerdict(string $verdict): bool
    {
        return in_array(strtolower(trim($verdict)), ['maybe', 'apply', 'consider', 'ok', 'yes'], true);
    }

    /**
     * Одна вакансия в DevAI (со страницы вакансий).
     * $forceRescore — переоценка уже оценённой (skip → новый assessment).
     *
     * @return array{ok: bool, enqueued: int, message: string, vacancy_ids?: list<string>, raw?: array<string, mixed>}
     */
    public function forVacancy(string $resumeId, string $vacancyId, bool $forceRescore = false): array
    {
        $resumeId = trim($resumeId);
        $vacancyId = trim($vacancyId);
        if ($resumeId === '' || $vacancyId === '') {
            return ['ok' => false, 'enqueued' => 0, 'message' => 'Укажите resume и vacancy.'];
        }

        $sync = $this->syncResume->ensureSynced($resumeId);
        if (!$sync['ok']) {
            return [
                'ok' => false,
                'enqueued' => 0,
                'message' => $sync['message'],
                'sync' => $sync,
            ];
        }

        if (!$forceRescore && $this->hasAssessment($vacancyId)) {
            return [
                'ok' => true,
                'enqueued' => 0,
                'message' => 'Эта вакансия уже оценена DevAI.',
                'vacancy_ids' => [$vacancyId],
                'sync' => $sync,
            ];
        }

        $markedActive = $this->ensureActiveSourceStatus([$vacancyId]);

        try {
            $raw = $this->scoring->enqueueSemanticBulk(
                [$vacancyId],
                $forceRescore ? 'force_rescore' : 'devai_auto',
            );
        } catch (ScoringException $e) {
            return [
                'ok' => false,
                'enqueued' => 0,
                'message' => $e->getMessage(),
                'vacancy_ids' => [$vacancyId],
                'marked_active' => $markedActive,
                'sync' => $sync,
            ];
        }

        $enqueued = (int) ($raw['enqueued'] ?? 0);
        $alreadyQueued = (int) ($raw['already_queued'] ?? 0);
        $alreadyScored = (int) ($raw['already_scored'] ?? 0);

        if ($enqueued > 0) {
            $message = $forceRescore
                ? 'Переоценил через DevAI — новый вердикт записан.'
                : 'Оценил через DevAI — вердикт записан в Core.';
            $ok = true;
        } elseif ($alreadyQueued > 0) {
            $message = 'Уже в очереди DevAI — жду вердикт.';
            $ok = true;
        } elseif ($alreadyScored > 0) {
            $message = 'Уже оценена — подтягиваю вердикт.';
            $ok = true;
        } else {
            $message = 'Не смог оценить эту вакансию через DevAI.';
            $ok = false;
        }

        return [
            'ok' => $ok,
            'enqueued' => $enqueued,
            'message' => $message,
            'vacancy_ids' => [$vacancyId],
            'marked_active' => $markedActive,
            'sync' => $sync,
            'raw' => $raw,
        ];
    }

    /**
     * Live-скачанные вакансии часто лежат с source_status=unknown — Scoring их режет.
     *
     * @param list<string> $vacancyIds
     */
    private function ensureActiveSourceStatus(array $vacancyIds): int
    {
        $marked = 0;
        foreach ($vacancyIds as $id) {
            try {
                $vacancy = $this->core->getVacancy($id);
            } catch (CoreException) {
                continue;
            }
            $status = (string) ($vacancy['source_status'] ?? 'unknown');
            if ($status === 'active' || $status === 'archived') {
                continue;
            }
            try {
                $this->core->updateVacancySourceStatus(
                    $id,
                    'active',
                    'job_search_2:live_detail_fetched',
                );
                $marked++;
            } catch (CoreException) {
                // оставляем unknown — Scoring сообщит в skipped
            }
        }

        return $marked;
    }

    /**
     * Неоценённые core UUID: все suitable-run этого резюме (свежие первые) + owner-привязки.
     *
     * @return list<string>
     */
    private function unscoredVacancyIdsForResume(string $resumeId, int $limit): array
    {
        $limit = max(1, $limit);
        /** @var array<string, true> $seen */
        $seen = [];
        $ids = [];

        foreach ($this->coreIdsFromAllSuitableRuns($resumeId) as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($this->hasAssessment($id)) {
                continue;
            }
            $ids[] = $id;
            if (count($ids) >= $limit) {
                return $ids;
            }
        }

        foreach ($this->coreIdsOwnedByResume($resumeId) as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($this->hasAssessment($id)) {
                continue;
            }
            $ids[] = $id;
            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Core vacancy_id из suitable-run резюме за CATCHUP_DAYS (новые discovered первыми).
     *
     * @return list<string>
     */
    private function coreIdsFromAllSuitableRuns(string $resumeId): array
    {
        try {
            $runs = $this->core->listSearchRuns();
        } catch (CoreException) {
            return [];
        }

        $cutoff = $this->catchupCutoffIso();
        /** @var array<string, string> $discoveredById */
        $discoveredById = [];
        foreach ($runs as $run) {
            if (($run['acquisition_kind'] ?? '') !== 'resume_suitable') {
                continue;
            }
            $status = (string) ($run['status'] ?? '');
            if (!in_array($status, ['success', 'partial'], true)) {
                continue;
            }
            $ctx = is_array($run['candidate_context_snapshot'] ?? null)
                ? $run['candidate_context_snapshot']
                : [];
            if (trim((string) ($ctx['hh_resume_external_id'] ?? '')) !== $resumeId) {
                continue;
            }
            $runFinished = trim((string) ($run['finished_at'] ?? $run['started_at'] ?? ''));
            if ($runFinished !== '' && $runFinished < $cutoff) {
                continue;
            }
            $runId = trim((string) ($run['id'] ?? ''));
            if ($runId === '') {
                continue;
            }
            $runStarted = trim((string) ($run['started_at'] ?? ''));
            try {
                $items = $this->core->listSearchRunItems($runId);
            } catch (CoreException) {
                continue;
            }
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['vacancy_id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $discovered = trim((string) ($row['discovered_at'] ?? ''));
                if ($discovered === '') {
                    $discovered = $runStarted;
                }
                if ($discovered !== '' && $discovered < $cutoff) {
                    continue;
                }
                $prev = $discoveredById[$id] ?? null;
                if ($prev === null || $discovered > $prev) {
                    $discoveredById[$id] = $discovered;
                }
            }
        }

        $ids = array_keys($discoveredById);
        usort(
            $ids,
            static fn (string $a, string $b): int => ($discoveredById[$b] ?? '') <=> ($discoveredById[$a] ?? ''),
        );

        return $ids;
    }

    /**
     * Owner за CATCHUP_DAYS → core UUID (скачаны peer'ом, привязаны сюда).
     *
     * @return list<string>
     */
    private function coreIdsOwnedByResume(string $resumeId): array
    {
        if ($this->owners === null) {
            return [];
        }
        $cutoff = $this->catchupCutoffIso();
        $externalIds = [];
        foreach ($this->owners->all() as $ext => $row) {
            if (trim((string) ($row['resume_id'] ?? '')) !== $resumeId) {
                continue;
            }
            $decided = trim((string) ($row['decided_at'] ?? ''));
            if ($decided !== '' && $decided < $cutoff) {
                continue;
            }
            $ext = trim((string) $ext);
            if ($ext !== '') {
                $externalIds[] = $ext;
            }
        }
        if ($externalIds === []) {
            return [];
        }

        try {
            $map = $this->core->lookupVacancyIdsByExternalIds($externalIds);
        } catch (CoreException) {
            return [];
        }

        $ids = [];
        foreach ($externalIds as $ext) {
            $id = trim((string) ($map[$ext] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function catchupCutoffIso(): string
    {
        return gmdate('c', time() - self::CATCHUP_DAYS * 86400);
    }

    private function hasAssessment(string $vacancyId): bool
    {
        try {
            $list = $this->core->listAssessments($vacancyId, 1);
        } catch (CoreException) {
            return false;
        }

        return $list['total'] > 0 || $list['items'] !== [];
    }

    private static function vacancyWord(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return 'вакансию';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'вакансии';
        }

        return 'вакансий';
    }
}
