<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;
use JobSearch\Domain\Vacancy\HhBotWall;
use JobSearch\Domain\Vacancy\HhVacancyClosed;

/**
 * Чтение скачанных вакансий из Core (SearchRun resume_suitable → Vacancy).
 */
final class ListForResume
{
    private const RECENT_DAYS = 3;

    /**
     * Пол UI/«Ещё»: только с этой даты (МСК).
     * Та же граница, что owner-cutoff статистики (28.10.2026).
     * В Core ничего не удаляем; known_external_ids по-прежнему по всей истории.
     */
    public const HISTORY_SINCE = '2026-10-28';

    public function __construct(private readonly CoreApi $core) {}

    /**
     * Сводка по резюме: пачка = suitable за recent (3 дня), не «последний пустой run».
     * search_run_id — последний успешный run с ненулевым found/created (для отладки).
     *
     * @return array<string, array{
     *   search_run_id: string,
     *   count: int,
     *   status: string,
     *   unscored_count: int,
     *   skip_count: int,
     *   consider_count: int
     * }>
     */
    public function summaryByResume(): array
    {
        $out = [];
        try {
            $runs = $this->core->listSearchRuns();
        } catch (CoreException) {
            return [];
        }

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
            $resumeId = trim((string) ($ctx['hh_resume_external_id'] ?? ''));
            if ($resumeId === '') {
                continue;
            }
            $runId = (string) ($run['id'] ?? '');
            if ($runId === '') {
                continue;
            }
            $count = (int) ($run['found_count'] ?? 0);
            if ($count <= 0) {
                $count = (int) ($run['created_count'] ?? 0)
                    + (int) ($run['updated_count'] ?? 0)
                    + (int) ($run['unchanged_count'] ?? 0);
            }
            $prev = $out[$resumeId] ?? null;
            // Берём первый (новейший) run; пустой позже заменяем на первый ненулевой.
            if ($prev === null) {
                $out[$resumeId] = [
                    'search_run_id' => $runId,
                    'count' => $count,
                    'status' => $status,
                    'unscored_count' => 0,
                    'skip_count' => 0,
                    'consider_count' => 0,
                ];
                continue;
            }
            if ((int) ($prev['count'] ?? 0) <= 0 && $count > 0) {
                $out[$resumeId]['search_run_id'] = $runId;
                $out[$resumeId]['count'] = $count;
                $out[$resumeId]['status'] = $status;
            }
        }

        foreach ($out as $resumeId => $meta) {
            $stats = $this->summarizeRecentBatch($resumeId);
            $out[$resumeId]['unscored_count'] = $stats['unscored'];
            $out[$resumeId]['skip_count'] = $stats['skip'];
            $out[$resumeId]['consider_count'] = $stats['consider'];
            $out[$resumeId]['count'] = $stats['seen'];
        }

        return $out;
    }

    /**
     * Вердикты suitable за RECENT_DAYS (то, что видит UI «за 3 дня»).
     *
     * @return array{seen: int, unscored: int, skip: int, consider: int}
     */
    private function summarizeRecentBatch(string $resumeId): array
    {
        $stats = ['seen' => 0, 'unscored' => 0, 'skip' => 0, 'consider' => 0];
        try {
            $list = $this->listForResume($resumeId, 'recent');
        } catch (\Throwable) {
            return $stats;
        }
        foreach ($list['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $stats['seen']++;
            $verdict = strtolower(trim((string) ($row['verdict'] ?? '')));
            if ($verdict === '') {
                $stats['unscored']++;
            } elseif (in_array($verdict, ['skip', 'reject', 'no'], true)) {
                $stats['skip']++;
            } elseif ($this->toneIsConsider($verdict)) {
                $stats['consider']++;
            } else {
                $stats['unscored']++;
            }
        }

        return $stats;
    }

    /**
     * Сколько apply/maybe среди suitable за recent-окно (для стопа «до 10 смотрим»).
     */
    public function countConsiderRecent(string $resumeId): int
    {
        $resumeId = trim($resumeId);
        if ($resumeId === '') {
            return 0;
        }
        $list = $this->listForResume($resumeId, 'recent');
        $n = 0;
        foreach ($list['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->toneIsConsider((string) ($row['verdict'] ?? ''))) {
                $n++;
            }
        }

        return $n;
    }

    private function toneIsConsider(string $verdict): bool
    {
        $v = strtolower(trim($verdict));

        return in_array($v, ['maybe', 'apply', 'consider', 'ok', 'yes'], true);
    }

    /**
     * Вердикты первых $limit вакансий run.
     *
     * @return array{seen: int, unscored: int, skip: int, consider: int}
     */
    private function summarizeRun(string $runId, int $limit): array
    {
        $stats = ['seen' => 0, 'unscored' => 0, 'skip' => 0, 'consider' => 0];
        if ($runId === '') {
            return $stats;
        }
        try {
            $items = $this->core->listSearchRunItems($runId);
        } catch (CoreException) {
            return $stats;
        }

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $vacancyId = trim((string) ($row['vacancy_id'] ?? ''));
            if ($vacancyId === '') {
                continue;
            }
            $stats['seen']++;
            $verdict = $this->verdictFor($vacancyId);
            if ($verdict === '') {
                $stats['unscored']++;
            } elseif ($verdict === 'skip' || $verdict === 'reject' || $verdict === 'no') {
                $stats['skip']++;
            } else {
                $stats['consider']++;
            }
            if ($stats['seen'] >= $limit) {
                break;
            }
        }

        return $stats;
    }

    private function verdictFor(string $vacancyId): string
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

    /**
     * Вакансии suitable-run'ов для резюме: recent = 3 дня, all = с HISTORY_SINCE.
     *
     * @return array{
     *   resume_id: string,
     *   scope: string,
     *   recent_days: int,
     *   history_since: string,
     *   search_run_id: string|null,
     *   count: int,
     *   total_all: int,
     *   recent_count: int,
     *   items: list<array<string, mixed>>
     * }
     */
    public function listForResume(string $resumeId, string $scope = 'recent'): array
    {
        $resumeId = trim($resumeId);
        $scope = $this->normalizeScope($scope);
        if ($resumeId === '') {
            throw new \InvalidArgumentException('Resume id is empty');
        }

        $collected = $this->collectForResume($resumeId);
        /** @var array<string, true> $knownExternalIds вся suitable-история (дедуп), даже старше HISTORY_SINCE. */
        $knownExternalIds = $this->externalIdsFromCollected($collected);

        $historySince = $this->historySinceCutoff();
        $recentCutoff = $this->recentCutoff();
        $recentItems = [];
        $allItems = [];
        foreach ($collected as $row) {
            $discovered = $this->parseTime((string) ($row['discovered_at'] ?? ''));
            if ($discovered === null || $discovered < $historySince) {
                continue;
            }
            $allItems[] = $row;
            if ($discovered >= $recentCutoff) {
                $recentItems[] = $row;
            }
        }

        $visible = $scope === 'all' ? $allItems : $recentItems;
        // Один bulk GET /assessments — иначе лимит «первые 100» давал ложные «без оценки».
        $assessmentByVacancy = $this->assessmentMap();
        $items = [];
        foreach ($visible as $row) {
            $items[] = $this->hydrateListItem($row, $assessmentByVacancy);
        }

        foreach ($items as $row) {
            $ext = trim((string) ($row['external_id'] ?? ''));
            if ($ext !== '') {
                $knownExternalIds[$ext] = true;
            }
        }

        return [
            'resume_id' => $resumeId,
            'scope' => $scope,
            'recent_days' => self::RECENT_DAYS,
            'history_since' => self::HISTORY_SINCE,
            'search_run_id' => $visible[0]['search_run_id'] ?? null,
            'count' => count($items),
            'total_all' => count($allItems),
            'recent_count' => count($recentItems),
            'known_external_ids' => $knownExternalIds,
            'items' => $items,
        ];
    }

    /**
     * HH vacancy id из serp url по всей истории suitable (без getVacancy).
     *
     * @param list<array<string, mixed>> $collected
     *
     * @return array<string, true>
     */
    private function externalIdsFromCollected(array $collected): array
    {
        $ids = [];
        foreach ($collected as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url !== '' && preg_match('~/vacancy/(\d+)~', $url, $m) === 1) {
                $ids[$m[1]] = true;
            }
        }

        return $ids;
    }

    /**
     * Полная карточка вакансии для детальной панели.
     *
     * @param array<string, mixed> $listItem
     *
     * @return array<string, mixed>
     */
    public function hydrateDetail(array $listItem): array
    {
        $vacancyId = trim((string) ($listItem['core_id'] ?? ''));
        if ($vacancyId === '') {
            $ext = trim((string) ($listItem['external_id'] ?? ''));
            if ($ext !== '') {
                try {
                    $map = $this->core->lookupVacancyIdsByExternalIds([$ext]);
                    $vacancyId = trim((string) ($map[$ext] ?? ''));
                } catch (CoreException) {
                    $vacancyId = '';
                }
            }
            if ($vacancyId === '') {
                return $listItem;
            }
            $listItem['core_id'] = $vacancyId;
        }
        try {
            $vacancy = $this->core->getVacancy($vacancyId);
        } catch (CoreException) {
            return $listItem;
        }

        $assessment = [
            'verdict' => (string) ($listItem['verdict'] ?? ''),
            'reason' => (string) ($listItem['reason'] ?? ''),
            'action' => (string) ($listItem['action'] ?? ''),
            'assessed_at' => (string) ($listItem['assessed_at'] ?? ''),
        ];
        if ($assessment['verdict'] === '') {
            $assessment = $this->latestAssessment($vacancyId);
        }

        return $this->mapVacancy(
            $vacancy,
            (string) ($listItem['outcome'] ?? ''),
            $assessment,
            (string) ($listItem['discovered_at'] ?? ''),
            (string) ($listItem['search_run_id'] ?? ''),
        );
    }

    /**
     * Уникальные vacancy_id по всем suitable-run резюме (свежие первые).
     *
     * @return list<array{
     *   vacancy_id: string,
     *   outcome: string,
     *   discovered_at: string,
     *   search_run_id: string,
     *   title: string,
     *   company_name: string,
     *   url: string
     * }>
     */
    private function collectForResume(string $resumeId): array
    {
        try {
            $runs = $this->core->listSearchRuns();
        } catch (CoreException) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $byVacancy */
        $byVacancy = [];
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
            $runId = trim((string) ($run['id'] ?? ''));
            if ($runId === '') {
                continue;
            }
            $runStarted = (string) ($run['started_at'] ?? '');
            try {
                $runItems = $this->core->listSearchRunItems($runId);
            } catch (CoreException) {
                continue;
            }
            foreach ($runItems as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $vacancyId = trim((string) ($row['vacancy_id'] ?? ''));
                if ($vacancyId === '') {
                    continue;
                }
                $discoveredAt = trim((string) ($row['discovered_at'] ?? ''));
                if ($discoveredAt === '') {
                    $discoveredAt = $runStarted;
                }
                $serp = is_array($row['serp_meta'] ?? null) ? $row['serp_meta'] : [];
                $candidate = [
                    'vacancy_id' => $vacancyId,
                    'outcome' => (string) ($row['outcome'] ?? ''),
                    'discovered_at' => $discoveredAt,
                    'search_run_id' => $runId,
                    'title' => trim((string) ($serp['title'] ?? '')),
                    'company_name' => trim((string) ($serp['company_name'] ?? '')),
                    'company_external_id' => trim((string) ($serp['company_external_id'] ?? '')),
                    'url' => trim((string) ($serp['url'] ?? '')),
                ];
                $prev = $byVacancy[$vacancyId] ?? null;
                if ($prev === null || $this->isNewer(
                    (string) $candidate['discovered_at'],
                    (string) ($prev['discovered_at'] ?? ''),
                )) {
                    $byVacancy[$vacancyId] = $candidate;
                }
            }
        }

        $list = array_values($byVacancy);
        usort(
            $list,
            fn (array $a, array $b): int => $this->isNewer(
                (string) ($b['discovered_at'] ?? ''),
                (string) ($a['discovered_at'] ?? ''),
            ) ? 1 : -1,
        );

        return $list;
    }

    /**
     * Latest assessment на vacancy_id (первый в выдаче Core = новее).
     *
     * @return array<string, array{verdict: string, reason: string, action: string, assessed_at: string}>
     */
    private function assessmentMap(): array
    {
        try {
            $list = $this->core->listAssessments(null, 200);
        } catch (CoreException) {
            return [];
        }
        $out = [];
        foreach ($list['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $vid = '';
            if (is_array($row['vacancy'] ?? null)) {
                $vid = trim((string) ($row['vacancy']['id'] ?? ''));
            }
            if ($vid === '') {
                $vid = trim((string) ($row['vacancy_id'] ?? ''));
            }
            if ($vid === '' || isset($out[$vid])) {
                continue;
            }
            $out[$vid] = [
                'verdict' => strtolower(trim((string) ($row['verdict'] ?? ''))),
                'reason' => trim((string) ($row['reason'] ?? '')),
                'action' => trim((string) ($row['action'] ?? '')),
                'assessed_at' => trim((string) ($row['assessed_at'] ?? $row['created_at'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array{verdict: string, reason: string, action: string, assessed_at: string}> $assessmentByVacancy
     */
    private function hydrateListItem(array $row, array $assessmentByVacancy = []): array
    {
        $vacancyId = (string) ($row['vacancy_id'] ?? '');
        $title = trim((string) ($row['title'] ?? ''));
        $company = trim((string) ($row['company_name'] ?? ''));
        $companyExt = trim((string) ($row['company_external_id'] ?? ''));
        $url = trim((string) ($row['url'] ?? ''));
        $externalId = '';
        if ($url !== '' && preg_match('~/vacancy/(\d+)~', $url, $m) === 1) {
            $externalId = $m[1];
        }
        // SERP/SearchRun мог запомнить title «не робот» — после перекачки в Core уже нормальный.
        $staleBotTitle = HhBotWall::looksLike($title);
        $sourceStatus = '';
        $archived = HhVacancyClosed::looksLike($title);
        // Старые SearchRun item без serp_meta / без HH id в url — добираем из Core.
        if ($vacancyId !== '' && ($title === '' || $staleBotTitle || $company === '' || $url === '' || $externalId === '')) {
            try {
                $vacancy = $this->core->getVacancy($vacancyId);
                $companyRow = is_array($vacancy['company'] ?? null) ? $vacancy['company'] : [];
                $coreTitle = trim((string) ($vacancy['title'] ?? ''));
                if ($title === '' || ($staleBotTitle && $coreTitle !== '' && !HhBotWall::looksLike($coreTitle))) {
                    $title = $coreTitle;
                }
                if ($company === '') {
                    $company = trim((string) ($companyRow['name'] ?? ''));
                }
                if ($companyExt === '') {
                    $companyExt = trim((string) ($companyRow['external_id'] ?? $companyRow['id'] ?? ''));
                }
                if ($url === '') {
                    $url = trim((string) ($vacancy['url'] ?? ''));
                }
                if ($externalId === '') {
                    $externalId = trim((string) ($vacancy['external_id'] ?? ''));
                }
                $sourceStatus = trim((string) ($vacancy['source_status'] ?? ''));
                $archived = $archived
                    || !empty($vacancy['archived'])
                    || strtolower($sourceStatus) === 'archived'
                    || HhVacancyClosed::looksLike($coreTitle);
            } catch (CoreException) {
                // оставляем пустые поля списка
            }
        }

        $assessment = $assessmentByVacancy[$vacancyId]
            ?? ['verdict' => '', 'reason' => '', 'action' => '', 'assessed_at' => ''];
        // Редкий miss bulk-карты (или bot-wall) — один точечный GET.
        if ($vacancyId !== '' && $assessment['verdict'] === '' && ($staleBotTitle || $assessmentByVacancy === [])) {
            $assessment = $this->latestAssessment($vacancyId);
        }

        return [
            'core_id' => $vacancyId,
            'external_id' => $externalId,
            'title' => $title,
            'url' => $url,
            'company_name' => $company,
            'company_external_id' => $companyExt,
            'description' => '',
            'key_skills' => [],
            'salary_text' => '',
            'area_text' => '',
            'outcome' => (string) ($row['outcome'] ?? ''),
            'verdict' => $assessment['verdict'],
            'reason' => $assessment['reason'],
            'action' => $assessment['action'],
            'assessed_at' => $assessment['assessed_at'],
            'discovered_at' => (string) ($row['discovered_at'] ?? ''),
            'search_run_id' => (string) ($row['search_run_id'] ?? ''),
            'source_status' => $sourceStatus,
            'archived' => $archived,
            'error' => '',
        ];
    }

    /**
     * @return array{verdict: string, reason: string, action: string, assessed_at: string}
     */
    private function latestAssessment(string $vacancyId): array
    {
        try {
            $list = $this->core->listAssessments($vacancyId, 1);
        } catch (CoreException) {
            return ['verdict' => '', 'reason' => '', 'action' => '', 'assessed_at' => ''];
        }
        $row = $list['items'][0] ?? null;
        if (!is_array($row)) {
            return ['verdict' => '', 'reason' => '', 'action' => '', 'assessed_at' => ''];
        }

        return [
            'verdict' => strtolower(trim((string) ($row['verdict'] ?? ''))),
            'reason' => trim((string) ($row['reason'] ?? '')),
            'action' => trim((string) ($row['action'] ?? '')),
            'assessed_at' => trim((string) ($row['assessed_at'] ?? $row['created_at'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $vacancy
     * @param array{verdict: string, reason: string, action: string, assessed_at?: string} $assessment
     *
     * @return array<string, mixed>
     */
    private function mapVacancy(
        array $vacancy,
        string $outcome,
        array $assessment,
        string $discoveredAt = '',
        string $searchRunId = '',
    ): array {
        $description = isset($vacancy['description']) && is_string($vacancy['description'])
            ? $vacancy['description']
            : '';
        [$body, $skills] = $this->splitDescriptionSkills($description);
        $company = is_array($vacancy['company'] ?? null) ? $vacancy['company'] : [];

        $archived = !empty($vacancy['archived'])
            || strtolower(trim((string) ($vacancy['source_status'] ?? ''))) === 'archived';

        return [
            'core_id' => (string) ($vacancy['id'] ?? ''),
            'external_id' => (string) ($vacancy['external_id'] ?? ''),
            'title' => (string) ($vacancy['title'] ?? ''),
            'url' => (string) ($vacancy['url'] ?? ''),
            'company_name' => (string) ($company['name'] ?? ''),
            'company_external_id' => trim((string) ($company['external_id'] ?? $company['id'] ?? '')),
            'description' => $body,
            'key_skills' => $skills,
            'salary_text' => (string) ($vacancy['salary_text'] ?? ''),
            'area_text' => (string) ($vacancy['area_text'] ?? ''),
            'outcome' => $outcome,
            'verdict' => $assessment['verdict'],
            'reason' => $assessment['reason'],
            'action' => $assessment['action'],
            'assessed_at' => (string) ($assessment['assessed_at'] ?? ''),
            'discovered_at' => $discoveredAt,
            'first_seen_at' => trim((string) ($vacancy['first_seen_at'] ?? '')),
            'created_at' => trim((string) ($vacancy['created_at'] ?? '')),
            'search_run_id' => $searchRunId,
            'source_status' => trim((string) ($vacancy['source_status'] ?? '')),
            'archived' => $archived,
            'error' => '',
        ];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function splitDescriptionSkills(string $description): array
    {
        if (!preg_match('/^(.*?)(?:\n\n|\s)*Ключевые навыки:\s*(.+)$/us', $description, $m)) {
            if (!preg_match('/^(.*?)(?:\n\n|\s)*Ключевые навыки\s+(.+)$/us', $description, $m)) {
                return [$description, []];
            }

            return [trim($description), []];
        }

        $body = trim($m[1]);
        $skills = [];
        foreach (explode('|', $m[2]) as $skill) {
            $skill = trim($skill);
            if ($skill !== '' && !in_array($skill, $skills, true)) {
                $skills[] = $skill;
            }
        }

        return [$body, $skills];
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));

        return $scope === 'all' ? 'all' : 'recent';
    }

    private function recentCutoff(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('P' . self::RECENT_DAYS . 'D'));
    }

    /** Начало календарного дня HISTORY_SINCE в Europe/Moscow → UTC-граница. */
    private function historySinceCutoff(): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::HISTORY_SINCE . ' 00:00:00', new DateTimeZone('Europe/Moscow')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function parseTime(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function isNewer(string $left, string $right): bool
    {
        $a = $this->parseTime($left);
        $b = $this->parseTime($right);
        if ($a === null) {
            return false;
        }
        if ($b === null) {
            return true;
        }

        return $a >= $b;
    }
}
