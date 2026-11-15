<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Application\Company\CompanyExclusionStore;
use JobSearch\Application\Company\CompanyRejectionIndex;
use JobSearch\Application\Error\AppErrorStore;
use JobSearch\Application\Error\ReconcileApplyErrorsFromChats;
use JobSearch\Application\Resume\ResumeViewsStore;

/**
 * Данные страницы вакансий по резюме: окно 3 дня / все + фильтр + детальная карточка.
 * Оркестратор: сбор списка → аннотация → фильтр → selected.
 */
final class VacancyPage
{
    private readonly VacancyPageFilter $filter;
    private readonly VacancyPageCollection $collection;
    private readonly VacancyPageRows $rows;

    public function __construct(
        private readonly ListForResume $list,
        private readonly ?AppliedVacancyIndex $applied = null,
        private readonly ?AppliedHistoryForResume $history = null,
        private readonly ?ResumeViewsStore $resumeViews = null,
        private readonly ?VacancyResumeOwnerStore $owners = null,
        private readonly ?AssignVacancyResume $assignOwner = null,
        private readonly ?CompanyRejectionIndex $companyRejections = null,
        private readonly ?CompanyExclusionStore $companyExclusions = null,
        private readonly ?AppErrorStore $appErrors = null,
        private readonly ?ReconcileApplyErrorsFromChats $reconcileErrorsFromChats = null,
        ?VacancyPageFilter $filter = null,
        ?VacancyPageCollection $collection = null,
        ?VacancyPageRows $rows = null,
    ) {
        $this->filter = $filter ?? new VacancyPageFilter();
        $this->collection = $collection ?? new VacancyPageCollection($this->list, $this->history);
        $this->rows = $rows ?? new VacancyPageRows($this->filter);
    }

    /**
     * @param list<array{id: string, title: string}> $resumes
     *
     * @return array{
     *   resume_id: string,
     *   resume_title: string,
     *   resumes: list<array{id: string, title: string}>,
     *   scope: string,
     *   recent_days: int,
     *   recent_count: int,
     *   total_all: int,
     *   search_run_id: string|null,
     *   filter: string,
     *   counts: array{all: int, consider: int, sent: int, errors: int, skip: int, pending: int, viewed: int, rejected: int, duplicates: int},
     *   items: list<array<string, mixed>>,
     *   selected: array<string, mixed>|null,
     *   query: string,
     *   vacancy_id: string,
     *   resume_error_count: int,
     *   error: string
     * }
     */
    public function forResume(
        string $resumeId,
        string $resumeTitle = '',
        string $filter = 'all',
        string $selectedId = '',
        string $scope = 'recent',
        array $resumes = [],
        string $query = '',
    ): array {
        $resumeId = trim($resumeId);
        $filter = $this->filter->normalizeFilter($filter);
        $selectedId = trim($selectedId);
        $scope = strtolower(trim($scope)) === 'all' ? 'all' : 'recent';
        $query = trim($query);
        $vacancyId = $this->filter->vacancyIdFromQuery($query);
        $resumes = $this->filter->normalizeResumes($resumes);

        if ($resumeId === '' && $resumes !== []) {
            $resumeId = $resumes[0]['id'];
            $resumeTitle = $resumes[0]['title'];
        }
        if ($resumeTitle === '') {
            foreach ($resumes as $row) {
                if ($row['id'] === $resumeId) {
                    $resumeTitle = $row['title'];
                    break;
                }
            }
        }

        if ($resumeId === '') {
            return [
                'resume_id' => '',
                'resume_title' => $resumeTitle,
                'resumes' => $resumes,
                'scope' => $scope,
                'recent_days' => $this->collection->recentDays(),
                'history_since' => ListForResume::HISTORY_SINCE,
                'recent_count' => 0,
                'total_all' => 0,
                'search_run_id' => null,
                'filter' => $filter,
                'counts' => $this->filter->emptyCounts(),
                'items' => [],
                'selected' => null,
                'query' => $query,
                'vacancy_id' => $vacancyId,
                'resume_error_count' => 0,
                'error' => 'Не указано резюме.',
            ];
        }

        // Ошибка apply + чат HH по vacancy (ручной отклик) → закрыть до аннотации.
        $this->reconcileErrorsFromChats?->run();
        $errorHints = $this->appErrors?->openVacancyHints()
            ?? ['by_pair' => [], 'by_resume' => [], 'skipped_by_pair' => []];
        /** @var array<string, array{message: string, at: string, source: string}> $errorsByPair */
        $errorsByPair = $errorHints['by_pair'];
        /** @var array<string, array{message: string, at: string, source: string}> $skippedByPair */
        $skippedByPair = is_array($errorHints['skipped_by_pair'] ?? null)
            ? $errorHints['skipped_by_pair']
            : [];
        /** @var array<string, int> $errorsByResume */
        $errorsByResume = $errorHints['by_resume'];
        foreach ($resumes as $i => $row) {
            $resumes[$i]['error_count'] = (int) ($errorsByResume[$row['id']] ?? 0);
        }

        // Поиск по HH id: смотрим всю историю резюме, иначе только текущий scope.
        $listScope = ($vacancyId !== '' && $scope === 'recent') ? 'all' : $scope;
        $payload = $this->list->listForResume($resumeId, $listScope);
        /** @var list<array<string, mixed>> $all */
        $all = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        /** @var array<string, true> $knownExternalIds */
        $knownExternalIds = is_array($payload['known_external_ids'] ?? null)
            ? $payload['known_external_ids']
            : [];
        $applied = $this->applied?->byExternalId() ?? [];
        $recentCount = (int) ($payload['recent_count'] ?? 0);
        $totalAll = (int) ($payload['total_all'] ?? 0);

        [$all, $recentCount, $totalAll] = $this->collection->mergeAppliedHistory(
            $all,
            $resumeId,
            $listScope,
            $applied,
            $recentCount,
            $totalAll,
            $knownExternalIds,
        );

        $peerRich = $this->assignOwner?->peerIndexRichFromCore() ?? [];
        $owners = $this->owners?->all() ?? [];
        [$all, $recentCount, $totalAll] = $this->collection->mergeOwnedVacancies(
            $all,
            $resumeId,
            $listScope,
            $owners,
            $peerRich,
            $recentCount,
            $totalAll,
            $knownExternalIds,
        );

        $viewEmployers = $this->resumeViews?->employersForResume($resumeId) ?? [];
        $rejectionIndex = $this->companyRejections?->index()
            ?? ['by_id' => [], 'by_name' => []];
        $rejectionIndex = CompanyRejectionIndex::enrichIdsFromVacancies($rejectionIndex, $all);
        $exclusionIndex = $this->companyExclusions?->index()
            ?? ['by_id' => [], 'by_name' => []];
        $exclusionIndex = CompanyRejectionIndex::enrichIdsFromVacancies($exclusionIndex, $all);
        $titlesByResume = [];
        foreach ($resumes as $r) {
            $titlesByResume[$r['id']] = $r['title'];
        }

        $annotated = $this->rows->annotate(
            $all,
            $resumeId,
            $applied,
            $viewEmployers,
            $rejectionIndex,
            $exclusionIndex,
            $owners,
            $peerRich,
            $titlesByResume,
            $errorsByPair,
            $this->collection,
            $skippedByPair,
        );
        $all = $annotated['items'];
        $counts = $annotated['counts'];

        $items = array_values(array_filter(
            $all,
            fn (array $row): bool => $this->filter->matchesFilter($row, $filter),
        ));
        if ($query !== '') {
            $items = array_values(array_filter(
                $items,
                fn (array $row): bool => $this->filter->matchesQuery($row, $query, $vacancyId),
            ));
        }

        $selected = null;
        $wantId = $selectedId !== '' ? $selectedId : $vacancyId;
        if ($wantId !== '') {
            foreach ($all as $row) {
                $coreId = (string) ($row['core_id'] ?? '');
                $extId = (string) ($row['external_id'] ?? '');
                if ($coreId === $wantId || $extId === $wantId) {
                    $selected = $row;
                    break;
                }
            }
        }
        if ($selected === null && $items !== []) {
            $selected = $items[0];
        }
        if ($selected !== null) {
            $selected = $this->rows->hydrateSelected(
                $selected,
                $all,
                $applied,
                $viewEmployers,
                $rejectionIndex,
                $exclusionIndex,
                $this->list,
                $this->collection,
            );
            $items = $this->rows->syncSelectedIntoItems($items, $selected, $counts);
            if ($filter === 'consider') {
                $items = array_values(array_filter(
                    $items,
                    fn (array $row): bool => $this->filter->stillConsidering($row),
                ));
            }
        }

        return [
            'resume_id' => $resumeId,
            'resume_title' => $resumeTitle,
            'resumes' => $resumes,
            'scope' => (string) ($payload['scope'] ?? $listScope),
            'recent_days' => (int) ($payload['recent_days'] ?? $this->collection->recentDays()),
            'history_since' => (string) ($payload['history_since'] ?? ListForResume::HISTORY_SINCE),
            'recent_count' => $recentCount,
            'total_all' => $totalAll,
            'search_run_id' => $payload['search_run_id'] ?? null,
            'filter' => $filter,
            'counts' => $counts,
            'items' => $items,
            'selected' => $selected,
            'query' => $query,
            'vacancy_id' => $vacancyId,
            'resume_error_count' => (int) ($errorsByResume[$resumeId] ?? 0),
            'error' => '',
        ];
    }
}
