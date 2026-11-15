<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Слияние suitable + история откликов + owner-stub'ы в единый список страницы.
 */
final class VacancyPageCollection
{
    private const RECENT_DAYS = 3;

    public function __construct(
        private readonly ListForResume $list,
        private readonly ?AppliedHistoryForResume $history = null,
    ) {}

    public function recentDays(): int
    {
        return self::RECENT_DAYS;
    }

    /**
     * @param list<array<string, mixed>> $all
     * @param array<string, array{applied: true, resume_external_id: string, resume_title: string}> $applied
     * @param array<string, true> $knownExternalIds HH id всей suitable-истории резюме (для дедупа в scope=recent)
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    public function mergeAppliedHistory(
        array $all,
        string $resumeId,
        string $listScope,
        array $applied,
        int $recentCount,
        int $totalAll,
        array $knownExternalIds = [],
    ): array {
        if ($this->history === null) {
            return [$all, $recentCount, $totalAll];
        }

        $history = $this->history->forResume($resumeId);
        if ($history === []) {
            return [$all, $recentCount, $totalAll];
        }

        /** @var array<string, int> $indexByExt */
        $indexByExt = [];
        foreach ($all as $i => $row) {
            $ext = trim((string) ($row['external_id'] ?? ''));
            if ($ext !== '') {
                $indexByExt[$ext] = $i;
            }
        }

        foreach ($history as $hist) {
            $ext = trim((string) ($hist['external_id'] ?? ''));
            if ($ext === '') {
                continue;
            }
            if (isset($indexByExt[$ext])) {
                // Enrich suitable-карточки даже вне окна (бейдж отклика).
                $all[$indexByExt[$ext]] = $this->enrichSuitableWithHistory(
                    $all[$indexByExt[$ext]],
                    $hist,
                    $applied,
                );
                continue;
            }
            // Уже в suitable-истории, но вне текущего окна — не плодим карточку.
            if (isset($knownExternalIds[$ext]) || isset($knownExternalIds[(string) (int) $ext])) {
                continue;
            }
            // Applied-only stub'ы — только в scope=all. В recent activity чата
            // маскируется под applied_at и раздувает «Все / Отправлено».
            if ($listScope === 'recent') {
                continue;
            }
            // Старше HISTORY_SINCE — в UI не показываем (в Core/known остаются).
            if (!$this->inHistoryWindow((string) ($hist['applied_at'] ?? $hist['discovered_at'] ?? ''))) {
                continue;
            }

            $row = $hist;
            $meta = $this->appliedMeta($applied, $ext);
            if ($meta !== null) {
                $row['applied'] = true;
                if (trim((string) ($row['applied_resume_title'] ?? '')) === '') {
                    $row['applied_resume_title'] = (string) ($meta['resume_title'] ?? '');
                }
                if (trim((string) ($row['applied_resume_id'] ?? '')) === '') {
                    $row['applied_resume_id'] = (string) ($meta['resume_external_id'] ?? '');
                }
            }
            $indexByExt[$ext] = count($all);
            $all[] = $row;
            // Счётчики «скачанных» — только suitable; applied-only не раздуваем.
        }

        return [$all, $recentCount, $totalAll];
    }

    /**
     * Вакансии, где текущее резюме — owner, но их нет в его suitable-run
     * (скачаны peer'ом, assign отдал сюда). В «Смотрим» owner'а они должны быть.
     *
     * Окно: recent — по реальному discovered_at suitable (не decided_at assign);
     * all — с HISTORY_SINCE. Иначе reconcile заливает сотни stub'ов в 3 дня.
     *
     * @param list<array<string, mixed>> $all
     * @param array<string, array{resume_id: string, resume_title: string}> $owners
     * @param array<string, array{peers?: list<string>, core_id?: string, discovered_at?: string}> $peerRich
     * @param array<string, true> $knownExternalIds HH id всей suitable-истории резюме
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    public function mergeOwnedVacancies(
        array $all,
        string $resumeId,
        string $listScope,
        array $owners,
        array $peerRich,
        int $recentCount,
        int $totalAll,
        array $knownExternalIds = [],
    ): array {
        if ($owners === []) {
            return [$all, $recentCount, $totalAll];
        }

        $have = $knownExternalIds;
        foreach ($all as $row) {
            $ext = trim((string) ($row['external_id'] ?? ''));
            if ($ext !== '') {
                $have[$ext] = true;
            }
        }

        $recentCutoff = $this->recentCutoff();

        foreach ($owners as $extKey => $own) {
            $ext = trim((string) $extKey);
            if ($ext === '' || isset($have[$ext]) || isset($have[(string) (int) $ext])) {
                continue;
            }
            if (trim((string) ($own['resume_id'] ?? '')) !== $resumeId) {
                continue;
            }
            $peerMeta = $peerRich[$ext] ?? $peerRich[(string) (int) $ext] ?? null;
            $coreId = '';
            $peerDiscovered = '';
            $serpTitle = '';
            $serpCompany = '';
            if (is_array($peerMeta)) {
                $coreId = trim((string) ($peerMeta['core_id'] ?? ''));
                $peerDiscovered = trim((string) ($peerMeta['discovered_at'] ?? ''));
                $serpTitle = trim((string) ($peerMeta['title'] ?? ''));
                $serpCompany = trim((string) ($peerMeta['company_name'] ?? ''));
            }
            $decidedAt = trim((string) ($own['decided_at'] ?? ''));
            // Дата скачивания (suitable), не момент assign/reconcile.
            $discoveredHint = $peerDiscovered !== '' ? $peerDiscovered : $decidedAt;

            if ($listScope === 'recent') {
                // Без даты suitable в recent не тащим — decided_at от reconcile врёт.
                if ($peerDiscovered === '' || !$this->isAtOrAfter($peerDiscovered, $recentCutoff)) {
                    continue;
                }
            } elseif (!$this->inHistoryWindow($discoveredHint)) {
                continue;
            }

            $stub = [
                'core_id' => $coreId,
                'external_id' => $ext,
                'title' => $serpTitle,
                'company_name' => $serpCompany,
                'url' => 'https://hh.ru/vacancy/' . rawurlencode($ext),
                'description' => '',
                'key_skills' => [],
                'salary_text' => '',
                'area_text' => '',
                'outcome' => '',
                'verdict' => '',
                'reason' => '',
                'action' => '',
                'assessed_at' => '',
                'discovered_at' => $discoveredHint,
                'search_run_id' => '',
                'origin' => 'owner',
                'applied' => false,
                'applied_resume_id' => '',
                'applied_resume_title' => '',
            ];
            // vacancy_id в SearchRun мог быть null (ошибка detail), а в Core уже есть после перекачки.
            $hydrated = $this->list->hydrateDetail($stub);
            $hydrated['origin'] = 'owner';
            $hydrated['external_id'] = $ext;
            if (trim((string) ($hydrated['url'] ?? '')) === '') {
                $hydrated['url'] = $stub['url'];
            }
            if (trim((string) ($hydrated['discovered_at'] ?? '')) === '') {
                $hydrated['discovered_at'] = $discoveredHint;
            }
            // Пустой stub без Core и без SERP title — не засоряем «Без названия».
            if (trim((string) ($hydrated['title'] ?? '')) === '') {
                continue;
            }
            $stub = $hydrated;
            $discoveredAt = trim((string) ($stub['discovered_at'] ?? ''));
            if ($discoveredAt === '') {
                $discoveredAt = $discoveredHint;
                $stub['discovered_at'] = $discoveredAt;
            }
            if ($listScope === 'recent' && !$this->isAtOrAfter($discoveredAt, $recentCutoff)) {
                continue;
            }
            $all[] = $stub;
            $have[$ext] = true;
            // recent_count / total_all — только suitable этого резюме; stub не трогаем.
        }

        return [$all, $recentCount, $totalAll];
    }

    /**
     * @param array<string, array{resume_id: string, resume_title: string, reason: string, candidates?: list<string>}> $owners
     * @param array<string, array{peers: list<string>, earliest_resume_id: string}> $peerRich
     * @param array<string, string> $titlesByResume
     *
     * @return array{
     *   is_duplicate: bool,
     *   owner_resume_id: string,
     *   owner_resume_title: string,
     *   owner_reason: string
     * }
     */
    public function ownershipFor(
        string $externalId,
        string $currentResumeId,
        array $owners,
        array $peerRich,
        array $titlesByResume,
    ): array {
        $empty = [
            'is_duplicate' => false,
            'owner_resume_id' => '',
            'owner_resume_title' => '',
            'owner_reason' => '',
        ];
        if ($externalId === '') {
            return $empty;
        }

        $stored = $owners[$externalId] ?? null;
        $peerMeta = $peerRich[$externalId] ?? null;
        $peers = is_array($peerMeta) ? ($peerMeta['peers'] ?? []) : [];
        // suitable_peers = факт выдачи; candidates — пул DevAI, им пересечение не мерить.
        if ($peers === [] && is_array($stored)) {
            $peers = is_array($stored['suitable_peers'] ?? null) ? $stored['suitable_peers'] : [];
        }
        // Удалённые резюме (SM/HIT) не участвуют в дедупе UI.
        if ($titlesByResume !== []) {
            $peers = array_values(array_filter(
                array_map(static fn ($p): string => trim((string) $p), is_array($peers) ? $peers : []),
                static fn (string $p): bool => $p !== '' && isset($titlesByResume[$p]),
            ));
        }

        $ownerId = '';
        $ownerTitle = '';
        $reason = '';
        if (is_array($stored)) {
            $ownerId = trim((string) ($stored['resume_id'] ?? ''));
            $ownerTitle = trim((string) ($stored['resume_title'] ?? ''));
            $reason = trim((string) ($stored['reason'] ?? ''));
        } elseif (is_array($peerMeta)) {
            $ownerId = trim((string) ($peerMeta['earliest_resume_id'] ?? ''));
            $reason = 'Первое появление в suitable.';
        }

        if ($ownerTitle === '' && $ownerId !== '') {
            $ownerTitle = trim((string) ($titlesByResume[$ownerId] ?? ''));
        }

        if (count($peers) <= 1 && $ownerId === '') {
            return $empty;
        }
        if (count($peers) <= 1 && ($ownerId === '' || $ownerId === $currentResumeId)) {
            return [
                'is_duplicate' => false,
                'owner_resume_id' => $ownerId !== '' ? $ownerId : $currentResumeId,
                'owner_resume_title' => $ownerTitle,
                'owner_reason' => $reason,
            ];
        }

        if ($ownerId === '') {
            $ownerId = is_array($peerMeta)
                ? trim((string) ($peerMeta['earliest_resume_id'] ?? ''))
                : $currentResumeId;
        }

        $isDup = $ownerId !== '' && $ownerId !== $currentResumeId;

        return [
            'is_duplicate' => $isDup,
            'owner_resume_id' => $ownerId,
            'owner_resume_title' => $ownerTitle !== ''
                ? $ownerTitle
                : trim((string) ($titlesByResume[$ownerId] ?? '')),
            'owner_reason' => $reason,
        ];
    }

    /**
     * @param array<string, array{applied: true, resume_external_id: string, resume_title: string}> $applied
     *
     * @return array{applied: true, resume_external_id: string, resume_title: string}|null
     */
    public function appliedMeta(array $applied, string $extId): ?array
    {
        if ($extId === '') {
            return null;
        }
        $meta = $applied[$extId] ?? $applied[(string) (int) $extId] ?? null;
        if ($meta === null && ctype_digit($extId)) {
            $meta = $applied[(int) $extId] ?? null;
        }

        return is_array($meta) ? $meta : null;
    }

    /**
     * @param array<string, mixed> $suitable
     * @param array<string, mixed> $hist
     * @param array<string, array{applied: true, resume_external_id: string, resume_title: string}> $applied
     *
     * @return array<string, mixed>
     */
    private function enrichSuitableWithHistory(array $suitable, array $hist, array $applied): array
    {
        $suitable['applied'] = true;
        foreach (['chat_status', 'chat_status_label', 'applied_at', 'activity_at'] as $key) {
            if (trim((string) ($suitable[$key] ?? '')) === '' && trim((string) ($hist[$key] ?? '')) !== '') {
                $suitable[$key] = $hist[$key];
            }
        }
        if (trim((string) ($suitable['applied_resume_id'] ?? '')) === '') {
            $suitable['applied_resume_id'] = (string) ($hist['applied_resume_id'] ?? '');
        }
        if (trim((string) ($suitable['applied_resume_title'] ?? '')) === '') {
            $suitable['applied_resume_title'] = (string) ($hist['applied_resume_title'] ?? '');
        }
        $ext = trim((string) ($suitable['external_id'] ?? ''));
        $meta = $this->appliedMeta($applied, $ext);
        if ($meta !== null) {
            if (trim((string) ($suitable['applied_resume_id'] ?? '')) === '') {
                $suitable['applied_resume_id'] = (string) ($meta['resume_external_id'] ?? '');
            }
            if (trim((string) ($suitable['applied_resume_title'] ?? '')) === '') {
                $suitable['applied_resume_title'] = (string) ($meta['resume_title'] ?? '');
            }
        }

        return $suitable;
    }

    /** Пустая дата → показываем; иначе только ≥ HISTORY_SINCE (МСК). */
    private function inHistoryWindow(string $isoOrEmpty): bool
    {
        $isoOrEmpty = trim($isoOrEmpty);
        if ($isoOrEmpty === '') {
            return true;
        }
        try {
            $at = new DateTimeImmutable($isoOrEmpty);
        } catch (\Exception) {
            return true;
        }
        $since = (new DateTimeImmutable(
            ListForResume::HISTORY_SINCE . ' 00:00:00',
            new DateTimeZone('Europe/Moscow'),
        ))->setTimezone(new DateTimeZone('UTC'));

        return $at >= $since;
    }

    /** Как ListForResume: now UTC − RECENT_DAYS. */
    private function recentCutoff(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('P' . self::RECENT_DAYS . 'D'));
    }

    private function isAtOrAfter(string $iso, DateTimeImmutable $cutoff): bool
    {
        $iso = trim($iso);
        if ($iso === '') {
            return false;
        }
        try {
            $at = new DateTimeImmutable($iso);
        } catch (\Exception) {
            return false;
        }

        return $at >= $cutoff;
    }

}
