<?php
declare(strict_types=1);

namespace JobSearch\Application\Error;

use JobSearch\Application\Vacancy\ListForResume;

/**
 * Список ошибок для страницы «Ошибки».
 */
final class ListAppErrors
{
    /** @var list<string> */
    public const FILTERS = ['all', 'open', 'resolved', 'unavailable', 'skipped'];

    public function __construct(
        private readonly AppErrorStore $store,
        private readonly ?ListForResume $vacancies = null,
        private readonly ?ReconcileApplyErrorsFromChats $reconcileFromChats = null,
    ) {}

    /**
     * @return array{
     *   total: int,
     *   filtered: int,
     *   retryable: int,
     *   filter: string,
     *   counts: array<string, int>,
     *   items: list<array<string, mixed>>
     * }
     */
    public function page(string $filter = 'open'): array
    {
        $filter = $this->normalizeFilter($filter);
        // Старые дубли resume+vacancy схлопываем при открытии журнала.
        $this->store->compactDuplicates();
        // Ручной отклик → чат HH без resume_id: закрываем ошибку по vacancy.
        $this->reconcileFromChats?->run();
        $this->enrichMissingVacancyTitles();
        $items = $this->store->all();
        $counts = [
            'all' => 0,
            'open' => 0,
            'resolved' => 0,
            'unavailable' => 0,
            'skipped' => 0,
        ];
        $filtered = [];
        foreach ($items as $row) {
            $message = (string) ($row['message'] ?? '');
            $skipped = trim((string) ($row['skipped_at'] ?? '')) !== ''
                || AppErrorStore::isSkipMessage($message);
            $resolved = trim((string) ($row['resolved_at'] ?? '')) !== '';
            $unavailable = !$skipped && AppErrorStore::isUnavailableMessage($message);
            $counts['all']++;
            if ($skipped) {
                $counts['skipped']++;
            } elseif ($resolved) {
                $counts['resolved']++;
            } elseif ($unavailable) {
                $counts['unavailable']++;
            } else {
                $counts['open']++;
            }
            if (!$this->matches($filter, $resolved, $unavailable, $skipped)) {
                continue;
            }
            $filtered[] = $row;
        }

        return [
            'total' => $counts['all'],
            'filtered' => count($filtered),
            'retryable' => count($this->store->retryableTargets()),
            'filter' => $filter,
            'counts' => $counts,
            'items' => $filtered,
        ];
    }

    public function clear(): void
    {
        $this->store->clear();
    }

    public function resolveById(string $id, string $note = 'Закрыто вручную.'): bool
    {
        return $this->store->markResolvedById($id, $note);
    }

    public function skipById(string $id, string $note = 'Пропуск — вручную из журнала ошибок.'): bool
    {
        return $this->store->markSkippedById($id, $note);
    }

    public function normalizeFilter(string $filter): string
    {
        $filter = strtolower(trim($filter));
        if ($filter === '' || $filter === 'open') {
            return 'open';
        }
        // Старые URL ?filter=apply|apply_consider → открытые.
        if ($filter === 'apply' || $filter === 'apply_consider') {
            return 'open';
        }

        return in_array($filter, self::FILTERS, true) ? $filter : 'open';
    }

    private function matches(string $filter, bool $resolved, bool $unavailable, bool $skipped): bool
    {
        return match ($filter) {
            'all' => true,
            'open' => !$resolved && !$unavailable && !$skipped,
            'resolved' => $resolved && !$skipped,
            'unavailable' => !$resolved && $unavailable && !$skipped,
            'skipped' => $skipped,
            default => !$resolved && !$unavailable && !$skipped,
        };
    }

    /** Подтянуть названия вакансий из suitable-истории, если в журнале пусто. */
    private function enrichMissingVacancyTitles(): void
    {
        if ($this->vacancies === null) {
            return;
        }
        $needResumes = [];
        foreach ($this->store->all() as $row) {
            if (trim((string) ($row['vacancy_title'] ?? '')) !== '') {
                continue;
            }
            $vid = trim((string) ($row['vacancy_id'] ?? ''));
            $rid = trim((string) ($row['resume_id'] ?? ''));
            if ($vid === '' || $rid === '') {
                continue;
            }
            $needResumes[$rid] = true;
        }
        if ($needResumes === []) {
            return;
        }
        $map = [];
        foreach (array_keys($needResumes) as $resumeId) {
            try {
                $payload = $this->vacancies->listForResume($resumeId, 'all');
            } catch (\Throwable) {
                continue;
            }
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $ext = trim((string) ($item['external_id'] ?? ''));
                $title = trim((string) ($item['title'] ?? ''));
                if ($ext === '' || $title === '' || $title === $ext) {
                    continue;
                }
                $company = trim((string) ($item['company_name'] ?? ''));
                $map[$ext] = $company !== '' ? $title . ' · ' . $company : $title;
            }
        }
        $this->store->fillMissingVacancyTitles($map);
    }
}
