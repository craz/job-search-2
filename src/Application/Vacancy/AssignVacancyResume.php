<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;

/**
 * Provisional owner при ingest: title-rules или first suitable.
 * Финальный owner пишет LocalDevAiScoring (source=score) при оценке.
 */
final class AssignVacancyResume
{
    public function __construct(
        private readonly VacancyResumeOwnerStore $store,
        private readonly ?CoreApi $core = null,
    ) {}

    /**
     * @param list<string> $peerResumeIds резюме, у которых вакансия уже в suitable
     * @param array{title?: string, company_name?: string, url?: string, description?: string|null, core_id?: string} $vacancy
     * @param array<string, string> $resumeTitles resume_id → title
     *
     * @return array{resume_id: string, resume_title: string, source: string, reason: string}
     */
    public function assign(
        string $vacancyExternalId,
        array $peerResumeIds,
        array $vacancy,
        array $resumeTitles = [],
        bool $force = false,
    ): array {
        $vacancyExternalId = trim($vacancyExternalId);
        $peers = [];
        foreach ($peerResumeIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $peers[] = $id;
            }
        }
        $peers = $this->filterToKnownResumes($peers, $resumeTitles);
        if ($vacancyExternalId === '') {
            throw new \InvalidArgumentException('vacancy empty');
        }
        if ($peers === [] && $resumeTitles === []) {
            throw new \InvalidArgumentException('vacancy/peers empty');
        }

        $vacancy = $this->hydrateVacancyMeta($vacancyExternalId, $vacancy);
        // DevAI/rules выбирают среди ВСЕХ актуальных резюме (не только suitable-peers),
        // иначе лучший профиль (например TPM) не попадёт в candidates.
        $devAiCandidates = $this->candidateResumeIds($peers, $resumeTitles);
        if ($devAiCandidates === [] && $resumeTitles !== []) {
            $devAiCandidates = array_keys($resumeTitles);
        }
        if ($peers === [] && $devAiCandidates !== []) {
            // Suitable только у удалённых резюме — owner всё равно среди актуальных.
            $peers = [];
        } elseif ($peers === []) {
            throw new \InvalidArgumentException('vacancy/peers empty');
        }

        $existing = $this->store->get($vacancyExternalId);
        sort($peers);
        $candSorted = $devAiCandidates;
        sort($candSorted);
        if (!$force && $existing !== null) {
            // manual / score (финал из DevAI score) не перетираем provisional assign.
            if (in_array((string) ($existing['source'] ?? ''), ['manual', 'score'], true)) {
                return [
                    'resume_id' => $existing['resume_id'],
                    'resume_title' => $existing['resume_title'],
                    'source' => $existing['source'],
                    'reason' => $existing['reason'],
                ];
            }
            $prev = $existing['candidates'];
            sort($prev);
            $sameCandidates = $prev === $candSorted
                && in_array($existing['resume_id'], $devAiCandidates, true);
            $source = (string) ($existing['source'] ?? '');
            // rules/devai (legacy) при тех же кандидатах — кэш; first без force — ниже.
            if ($sameCandidates && in_array($source, ['devai', 'rules'], true)) {
                return [
                    'resume_id' => $existing['resume_id'],
                    'resume_title' => $existing['resume_title'],
                    'source' => $existing['source'],
                    'reason' => $existing['reason'],
                ];
            }
            // first + один peer: кэш только если title-rules тоже молчат / совпадают.
            if ($sameCandidates && $source === 'first' && count($peers) === 1) {
                $ruledCached = (new VacancyOwnerTitleRules())->pick(
                    $vacancy,
                    $devAiCandidates,
                    $resumeTitles,
                );
                if ($ruledCached === null || $ruledCached['resume_id'] === $existing['resume_id']) {
                    return [
                        'resume_id' => $existing['resume_id'],
                        'resume_title' => $existing['resume_title'],
                        'source' => $existing['source'],
                        'reason' => $existing['reason'],
                    ];
                }
                // иначе падаем ниже — перепишем rules.
            }
        }

        // Один suitable-peer: сначала title-rules по полному пулу candidates (TPM/PM/PHP),
        // иначе first. Иначе PM-вакансия, впервые попавшая в suitable PHP, залипает на PHP.
        if (count($peers) === 1 && !$force) {
            $ruledSingle = (new VacancyOwnerTitleRules())->pick(
                $vacancy,
                $devAiCandidates !== [] ? $devAiCandidates : $peers,
                $resumeTitles,
            );
            if ($ruledSingle !== null) {
                $this->store->put(
                    $vacancyExternalId,
                    $ruledSingle['resume_id'],
                    $ruledSingle['resume_title'],
                    'rules',
                    $ruledSingle['reason'],
                    $devAiCandidates !== [] ? $devAiCandidates : $peers,
                    $peers,
                );

                return [
                    'resume_id' => $ruledSingle['resume_id'],
                    'resume_title' => $ruledSingle['resume_title'],
                    'source' => 'rules',
                    'reason' => $ruledSingle['reason'],
                ];
            }
            $resumeId = $peers[0];
            $title = trim((string) ($resumeTitles[$resumeId] ?? ''));
            $this->store->put(
                $vacancyExternalId,
                $resumeId,
                $title,
                'first',
                'Первое появление в suitable.',
                $devAiCandidates !== [] ? $devAiCandidates : $peers,
                $peers,
            );

            return [
                'resume_id' => $resumeId,
                'resume_title' => $title,
                'source' => 'first',
                'reason' => 'Первое появление в suitable.',
            ];
        }

        $ruled = (new VacancyOwnerTitleRules())->pick($vacancy, $devAiCandidates, $resumeTitles);
        if ($ruled !== null) {
            $this->store->put(
                $vacancyExternalId,
                $ruled['resume_id'],
                $ruled['resume_title'],
                'rules',
                $ruled['reason'],
                $devAiCandidates,
                $peers,
            );

            return [
                'resume_id' => $ruled['resume_id'],
                'resume_title' => $ruled['resume_title'],
                'source' => 'rules',
                'reason' => $ruled['reason'],
            ];
        }

        // Без rules — provisional first; финальный owner выставит Score (source=score).
        $resumeId = $peers[0] ?? ($devAiCandidates[0] ?? '');
        if ($resumeId === '' && is_array($existing)) {
            $resumeId = trim((string) ($existing['resume_id'] ?? ''));
        }
        if ($resumeId === '') {
            throw new \InvalidArgumentException('no active resume for vacancy');
        }
        $title = trim((string) ($resumeTitles[$resumeId] ?? ''));
        if ($title === '' && is_array($existing)) {
            $title = trim((string) ($existing['resume_title'] ?? ''));
        }
        $reason = trim((string) ($vacancy['title'] ?? '')) === ''
            ? 'Первое появление в suitable (нет title для rules; owner уточнит score).'
            : 'Первое появление в suitable (owner уточнит score).';
        $this->store->put(
            $vacancyExternalId,
            $resumeId,
            $title,
            'first',
            $reason,
            $devAiCandidates !== [] ? $devAiCandidates : $peers,
            $peers,
        );

        return [
            'resume_id' => $resumeId,
            'resume_title' => $title,
            'source' => 'first',
            'reason' => $reason,
        ];
    }

    /**
     * @param list<string> $peers
     * @param array<string, string> $resumeTitles
     *
     * @return list<string>
     */
    private function candidateResumeIds(array $peers, array $resumeTitles): array
    {
        // Только актуальные резюме (удалённые SM/HIT из Core peers отбрасываем).
        $ids = $this->filterToKnownResumes($peers, $resumeTitles);
        foreach ($resumeTitles as $id => $title) {
            $id = trim((string) $id);
            $title = trim((string) $title);
            if ($id === '') {
                continue;
            }
            // Явно не-PM резюме не тащим в выбор владельца delivery/PM вакансий.
            if ($title !== '' && (
                preg_match('/php|битрикс|bitrix|1с-битрикс/ui', $title) === 1
            )) {
                continue;
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $ids
     * @param array<string, string> $resumeTitles
     *
     * @return list<string>
     */
    private function filterToKnownResumes(array $ids, array $resumeTitles): array
    {
        if ($resumeTitles === []) {
            $out = [];
            foreach ($ids as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $out[] = $id;
                }
            }

            return array_values(array_unique($out));
        }
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && isset($resumeTitles[$id])) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Индекс HH external_id → resume_ids по всем resume_suitable runs (для peers / UI).
     *
     * @return array<string, list<string>>
     */
    public function peerIndexFromCore(): array
    {
        $rich = $this->peerIndexRichFromCore();
        $out = [];
        foreach ($rich as $ext => $meta) {
            $out[$ext] = $meta['peers'];
        }

        return $out;
    }

    /**
     * @return array<string, array{peers: list<string>, earliest_resume_id: string, core_id: string}>
     */
    public function peerIndexRichFromCore(): array
    {
        if ($this->core === null) {
            return [];
        }
        try {
            $runs = $this->core->listSearchRuns();
        } catch (CoreException) {
            return [];
        }

        /** @var array<string, array<string, string>> $earliest ext → resume_id → iso discovered */
        $earliest = [];
        /** @var array<string, string> $coreIds */
        $coreIds = [];
        /** @var array<string, array{title?: string, company_name?: string}> $serpMeta */
        $serpMeta = [];

        foreach ($runs as $run) {
            if (($run['acquisition_kind'] ?? '') !== 'resume_suitable') {
                continue;
            }
            $status = (string) ($run['status'] ?? '');
            if (!in_array($status, ['success', 'partial', 'running'], true)) {
                continue;
            }
            $ctx = is_array($run['candidate_context_snapshot'] ?? null)
                ? $run['candidate_context_snapshot']
                : [];
            $resumeId = trim((string) ($ctx['hh_resume_external_id'] ?? ''));
            $runId = trim((string) ($run['id'] ?? ''));
            $runStarted = trim((string) ($run['started_at'] ?? $run['created_at'] ?? ''));
            if ($resumeId === '' || $runId === '') {
                continue;
            }
            try {
                $items = $this->core->listSearchRunItems($runId);
            } catch (CoreException) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $serp = is_array($item['serp_meta'] ?? null) ? $item['serp_meta'] : [];
                $ext = trim((string) ($item['source_external_id'] ?? ''));
                if ($ext === '') {
                    $url = trim((string) ($serp['url'] ?? ''));
                    if ($url !== '' && preg_match('~/vacancy/(\d+)~', $url, $m) === 1) {
                        $ext = $m[1];
                    }
                }
                if ($ext === '') {
                    continue;
                }
                $coreId = trim((string) ($item['vacancy_id'] ?? ''));
                if ($coreId !== '' && ($coreIds[$ext] ?? '') === '') {
                    $coreIds[$ext] = $coreId;
                }
                $serpTitle = trim((string) ($serp['title'] ?? ''));
                $serpCompany = trim((string) ($serp['company_name'] ?? ''));
                if ($serpTitle !== '' || $serpCompany !== '') {
                    $prevSerp = $serpMeta[$ext] ?? [];
                    if (trim((string) ($prevSerp['title'] ?? '')) === '' && $serpTitle !== '') {
                        $prevSerp['title'] = $serpTitle;
                    }
                    if (trim((string) ($prevSerp['company_name'] ?? '')) === '' && $serpCompany !== '') {
                        $prevSerp['company_name'] = $serpCompany;
                    }
                    $serpMeta[$ext] = $prevSerp;
                }
                $disc = trim((string) ($item['discovered_at'] ?? $runStarted));
                if ($disc === '') {
                    $disc = '9999';
                }
                $prev = $earliest[$ext][$resumeId] ?? null;
                if ($prev === null || $disc < $prev) {
                    $earliest[$ext][$resumeId] = $disc;
                }
            }
        }

        $out = [];
        foreach ($earliest as $ext => $byResume) {
            $peers = array_keys($byResume);
            asort($byResume);
            $earliestResume = (string) array_key_first($byResume);
            $discoveredAt = trim((string) ($byResume[$earliestResume] ?? ''));
            if ($discoveredAt === '9999') {
                $discoveredAt = '';
            }
            $meta = $serpMeta[$ext] ?? [];
            $out[$ext] = [
                'peers' => $peers,
                'earliest_resume_id' => $earliestResume,
                'core_id' => $coreIds[$ext] ?? '',
                // Самое раннее появление в любом suitable-run (для окна «3 дня» у owner).
                'discovered_at' => $discoveredAt,
                'title' => trim((string) ($meta['title'] ?? '')),
                'company_name' => trim((string) ($meta['company_name'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Быстрый пересмотр first/rules по title из assessments (без DevAI, batch write).
     *
     * @param array<string, string> $resumeTitles
     */
    public function reconcileStaleFirst(array $resumeTitles): int
    {
        if (count($resumeTitles) < 2) {
            return 0;
        }
        $metaByExt = $this->vacancyMetaFromAssessments();
        $rules = new VacancyOwnerTitleRules();
        /** @var array<string, array<string, mixed>> $batch */
        $batch = [];
        foreach ($this->store->all() as $extKey => $row) {
            $ext = trim((string) $extKey);
            if ($ext === '' || !is_array($row)) {
                continue;
            }
            $source = (string) ($row['source'] ?? '');
            if (!in_array($source, ['first', 'rules'], true)) {
                continue;
            }
            $fromAsm = $metaByExt[$ext] ?? null;
            if (!is_array($fromAsm) || trim((string) ($fromAsm['title'] ?? '')) === '') {
                continue;
            }
            $peers = [];
            foreach (is_array($row['suitable_peers'] ?? null) ? $row['suitable_peers'] : [] as $p) {
                $p = trim((string) $p);
                if ($p !== '') {
                    $peers[] = $p;
                }
            }
            $ownerId = trim((string) ($row['resume_id'] ?? ''));
            if ($peers === [] && $ownerId !== '') {
                $peers = [$ownerId];
            }
            $peers = $this->filterToKnownResumes($peers, $resumeTitles);
            if ($peers === []) {
                continue;
            }
            $vacancy = [
                'title' => (string) ($fromAsm['title'] ?? ''),
                'company_name' => (string) ($fromAsm['company_name'] ?? ''),
            ];
            $devAiCandidates = $this->candidateResumeIds($peers, $resumeTitles);
            $ruled = $rules->pick($vacancy, $devAiCandidates, $resumeTitles);
            if ($ruled === null) {
                // Owner снят (удалённое резюме) — переназначить на первое актуальное peer.
                if ($ownerId !== '' && isset($resumeTitles[$ownerId])) {
                    continue;
                }
                $fallback = $peers[0];
                $ruled = [
                    'resume_id' => $fallback,
                    'resume_title' => trim((string) ($resumeTitles[$fallback] ?? '')),
                    'reason' => 'Owner снят (резюме удалено) — ближайший suitable peer.',
                ];
            }
            if ($ruled['resume_id'] === $ownerId && $source === 'rules'
                && trim((string) ($row['reason'] ?? '')) === $ruled['reason']
            ) {
                continue;
            }
            $batch[$ext] = [
                'resume_id' => $ruled['resume_id'],
                'resume_title' => $ruled['resume_title'],
                'source' => 'rules',
                'reason' => $ruled['reason'],
                'candidates' => $devAiCandidates,
                'suitable_peers' => $peers,
            ];
        }

        return $this->store->putMany($batch);
    }

    /**
     * Полная пересборка owner из Core suitable-runs + title-rules (восстановление стора).
     *
     * @param array<string, string> $resumeTitles
     */
    public function rebuildFromCore(array $resumeTitles): int
    {
        $rich = $this->peerIndexRichFromCore();
        $metaByExt = $this->vacancyMetaFromAssessments();
        $rules = new VacancyOwnerTitleRules();
        $allIds = array_keys($resumeTitles);
        /** @var array<string, array<string, mixed>> $batch */
        $batch = [];
        foreach ($rich as $extKey => $meta) {
            $ext = trim((string) $extKey);
            if ($ext === '') {
                continue;
            }
            $rawPeers = [];
            foreach (is_array($meta['peers'] ?? null) ? $meta['peers'] : [] as $p) {
                $p = trim((string) $p);
                if ($p !== '') {
                    $rawPeers[] = $p;
                }
            }
            $peers = $this->filterToKnownResumes($rawPeers, $resumeTitles);
            if ($peers === []) {
                continue;
            }
            $earliestRaw = trim((string) ($meta['earliest_resume_id'] ?? ''));
            $earliest = isset($resumeTitles[$earliestRaw]) ? $earliestRaw : $peers[0];
            $fromAsm = $metaByExt[$ext] ?? null;
            $vacancy = [
                'title' => is_array($fromAsm) ? (string) ($fromAsm['title'] ?? '') : '',
                'company_name' => is_array($fromAsm) ? (string) ($fromAsm['company_name'] ?? '') : '',
            ];
            $devAiCandidates = $this->candidateResumeIds($peers, $resumeTitles);
            if ($devAiCandidates === []) {
                $devAiCandidates = $allIds !== [] ? $allIds : $peers;
            }
            $ruled = $rules->pick($vacancy, $devAiCandidates, $resumeTitles);
            if ($ruled !== null) {
                $batch[$ext] = [
                    'resume_id' => $ruled['resume_id'],
                    'resume_title' => $ruled['resume_title'],
                    'source' => 'rules',
                    'reason' => $ruled['reason'],
                    'candidates' => $devAiCandidates,
                    'suitable_peers' => $peers,
                ];
            } else {
                $batch[$ext] = [
                    'resume_id' => $earliest,
                    'resume_title' => trim((string) ($resumeTitles[$earliest] ?? '')),
                    'source' => 'first',
                    'reason' => 'Первое появление в suitable.',
                    'candidates' => $devAiCandidates,
                    'suitable_peers' => $peers,
                ];
            }
        }
        // Полная замена payload.
        $this->store->replaceAll($batch);

        return count($batch);
    }

    /**
     * Догнать provisional owner по пересечениям suitable; first→rules — через reconcileStaleFirst.
     * source=score/manual не трогаем (финал из оценки / ручной).
     *
     * @param array<string, string> $resumeTitles
     */
    public function reconcileMultiPeers(array $resumeTitles, bool $forceBlindDevAi = false): int
    {
        unset($forceBlindDevAi); // legacy arg; DevAI-assign убран — score финалит owner.
        $done = $this->reconcileStaleFirst($resumeTitles);

        $rich = $this->peerIndexRichFromCore();
        $metaByExt = $this->vacancyMetaFromAssessments();
        foreach ($rich as $extKey => $meta) {
            $ext = trim((string) $extKey);
            if ($ext === '') {
                continue;
            }
            $peers = [];
            foreach (is_array($meta['peers'] ?? null) ? $meta['peers'] : [] as $p) {
                $p = trim((string) $p);
                if ($p !== '') {
                    $peers[] = $p;
                }
            }
            $peers = $this->filterToKnownResumes($peers, $resumeTitles);
            if (count($peers) < 2) {
                if (count($peers) === 1 && $this->store->get($ext) === null) {
                    $fromAsm = $metaByExt[$ext] ?? null;
                    $this->assign(
                        $ext,
                        $peers,
                        [
                            'title' => is_array($fromAsm) ? (string) ($fromAsm['title'] ?? '') : '',
                            'company_name' => is_array($fromAsm) ? (string) ($fromAsm['company_name'] ?? '') : '',
                            'url' => 'https://hh.ru/vacancy/' . rawurlencode($ext),
                            'description' => '',
                            'core_id' => trim((string) ($meta['core_id'] ?? '')),
                        ],
                        $resumeTitles,
                    );
                    $done++;
                }
                continue;
            }
            $before = $this->store->get($ext);
            // score/manual/rules/legacy-devai при тех же candidates — не гоняем provisional.
            if (is_array($before) && in_array((string) ($before['source'] ?? ''), ['rules', 'devai', 'manual', 'score'], true)) {
                $prev = $before['candidates'] ?? [];
                sort($prev);
                $cand = $this->candidateResumeIds($peers, $resumeTitles);
                sort($cand);
                if ($prev === $cand) {
                    continue;
                }
                if (in_array((string) ($before['source'] ?? ''), ['manual', 'score'], true)) {
                    continue;
                }
            }
            $fromAsm = $metaByExt[$ext] ?? null;
            $this->assign(
                $ext,
                $peers,
                [
                    'title' => is_array($fromAsm) ? (string) ($fromAsm['title'] ?? '') : '',
                    'company_name' => is_array($fromAsm) ? (string) ($fromAsm['company_name'] ?? '') : '',
                    'url' => 'https://hh.ru/vacancy/' . rawurlencode($ext),
                    'description' => '',
                    'core_id' => trim((string) (($meta['core_id'] ?? '') !== ''
                        ? $meta['core_id']
                        : (is_array($fromAsm) ? ($fromAsm['core_id'] ?? '') : ''))),
                ],
                $resumeTitles,
            );
            $after = $this->store->get($ext);
            if ($before === null || ($after !== null && (
                ($after['resume_id'] ?? '') !== ($before['resume_id'] ?? '')
                || ($after['source'] ?? '') !== ($before['source'] ?? '')
            ))) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Title/company/core_id из assessments — чтобы rules работали без getVacancy.
     *
     * @return array<string, array{title: string, company_name: string, core_id: string}>
     */
    private function vacancyMetaFromAssessments(): array
    {
        if ($this->core === null) {
            return [];
        }
        try {
            $list = $this->core->listAssessments(null, 200);
        } catch (CoreException) {
            return [];
        }
        /** @var array<string, array{title: string, company_name: string, core_id: string, at: string}> $best */
        $best = [];
        foreach ($list['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $vacancy = is_array($row['vacancy'] ?? null) ? $row['vacancy'] : [];
            $ext = trim((string) ($vacancy['external_id'] ?? ''));
            if ($ext === '' || !preg_match('/^\d+$/', $ext)) {
                continue;
            }
            $at = trim((string) ($row['assessed_at'] ?? $row['created_at'] ?? ''));
            $prev = $best[$ext] ?? null;
            if ($prev !== null && !($at !== '' && $at > (string) ($prev['at'] ?? ''))) {
                continue;
            }
            $best[$ext] = [
                'title' => trim((string) ($vacancy['title'] ?? '')),
                'company_name' => trim((string) ($vacancy['company_name'] ?? '')),
                'core_id' => trim((string) ($vacancy['id'] ?? '')),
                'at' => $at,
            ];
        }
        $out = [];
        foreach ($best as $ext => $row) {
            $out[$ext] = [
                'title' => $row['title'],
                'company_name' => $row['company_name'],
                'core_id' => $row['core_id'],
            ];
        }

        return $out;
    }

    /**
     * @param array{title?: string, company_name?: string, url?: string, description?: string|null, core_id?: string} $vacancy
     *
     * @return array{title: string, company_name: string, url: string, description: string, core_id: string}
     */
    private function hydrateVacancyMeta(string $externalId, array $vacancy): array
    {
        $title = trim((string) ($vacancy['title'] ?? ''));
        $company = trim((string) ($vacancy['company_name'] ?? ''));
        $url = trim((string) ($vacancy['url'] ?? ''));
        $desc = trim((string) ($vacancy['description'] ?? ''));
        $coreId = trim((string) ($vacancy['core_id'] ?? ''));
        if ($url === '' && $externalId !== '') {
            $url = 'https://hh.ru/vacancy/' . rawurlencode($externalId);
        }
        if ($this->core === null || ($title !== '' && $desc !== '')) {
            return [
                'title' => $title,
                'company_name' => $company,
                'url' => $url,
                'description' => $desc,
                'core_id' => $coreId,
            ];
        }
        if ($coreId === '') {
            return [
                'title' => $title,
                'company_name' => $company,
                'url' => $url,
                'description' => $desc,
                'core_id' => $coreId,
            ];
        }
        try {
            $row = $this->core->getVacancy($coreId);
            $companyRow = is_array($row['company'] ?? null) ? $row['company'] : [];
            if ($title === '') {
                $title = trim((string) ($row['title'] ?? ''));
            }
            if ($company === '') {
                $company = trim((string) ($companyRow['name'] ?? ''));
            }
            if ($url === '') {
                $url = trim((string) ($row['url'] ?? ''));
            }
            if ($desc === '') {
                $desc = trim((string) ($row['description'] ?? ''));
            }
        } catch (CoreException) {
            // оставляем что есть
        }

        return [
            'title' => $title,
            'company_name' => $company,
            'url' => $url,
            'description' => $desc,
            'core_id' => $coreId,
        ];
    }
}
