<?php
declare(strict_types=1);

namespace JobSearch\Application\Company;

use JobSearch\Application\Chat\ChatRejectionArchive;
use JobSearch\Application\JobApplication\AppliedByVacancyStore;
use JobSearch\Application\Resume\ResumeViewsStore;
use JobSearch\Domain\Chat\ChatStore;
use JobSearch\Domain\Chat\ChatSummary;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;

/**
 * Справочник компаний из скачанных вакансий + пометки отказов из чатов HH.
 */
final class ListCompanies
{
    public function __construct(
        private readonly CoreApi $core,
        private readonly ChatStore $chats,
        private readonly ?AppliedByVacancyStore $appliedResumes = null,
        private readonly ?ChatRejectionArchive $rejectionArchive = null,
    ) {}

    /**
     * @return array{
     *   total: int,
     *   rejected_count: int,
     *   shown: int,
     *   filter: string,
     *   query: string,
     *   items: list<array<string, mixed>>
     * }
     */
    public function page(string $filter = 'all', string $query = ''): array
    {
        $filter = strtolower(trim($filter)) === 'rejected' ? 'rejected' : 'all';
        $query = trim($query);

        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];
        /** @var array<string, string> $vacancyToCompany vacancy_ext → company key */
        $vacancyToCompany = [];

        $this->collectFromSearchRuns($byKey, $vacancyToCompany);
        $appliedMap = $this->appliedResumes?->all() ?? [];
        $this->applyChatRejections($byKey, $vacancyToCompany, $appliedMap);

        $items = array_values($byKey);
        usort($items, static function (array $a, array $b): int {
            $ar = !empty($a['rejected']) ? 0 : 1;
            $br = !empty($b['rejected']) ? 0 : 1;
            if ($ar !== $br) {
                return $ar <=> $br;
            }
            $an = mb_strtolower((string) ($a['company_name'] ?? ''), 'UTF-8');
            $bn = mb_strtolower((string) ($b['company_name'] ?? ''), 'UTF-8');

            return $an <=> $bn;
        });

        $rejectedCount = 0;
        foreach ($items as $row) {
            if (!empty($row['rejected'])) {
                $rejectedCount++;
            }
        }

        if ($filter === 'rejected') {
            $items = array_values(array_filter(
                $items,
                static fn (array $row): bool => !empty($row['rejected']),
            ));
        }

        if ($query !== '') {
            $q = mb_strtolower($query, 'UTF-8');
            $items = array_values(array_filter(
                $items,
                static function (array $row) use ($q): bool {
                    $hay = mb_strtolower(
                        (string) ($row['company_name'] ?? '') . ' '
                        . (string) ($row['company_external_id'] ?? ''),
                        'UTF-8',
                    );
                    if (str_contains($hay, $q)) {
                        return true;
                    }
                    foreach (is_array($row['rejections'] ?? null) ? $row['rejections'] : [] as $rej) {
                        if (!is_array($rej)) {
                            continue;
                        }
                        $rh = mb_strtolower(
                            (string) ($rej['vacancy_title'] ?? '') . ' '
                            . (string) ($rej['vacancy_external_id'] ?? ''),
                            'UTF-8',
                        );
                        if (str_contains($rh, $q)) {
                            return true;
                        }
                    }

                    return false;
                },
            ));
        }

        return [
            'total' => count($byKey),
            'rejected_count' => $rejectedCount,
            'shown' => count($items),
            'filter' => $filter,
            'query' => $query,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     * @param array<string, string> $vacancyToCompany
     */
    private function collectFromSearchRuns(array &$byKey, array &$vacancyToCompany): void
    {
        try {
            $runs = $this->core->listSearchRuns();
        } catch (CoreException) {
            return;
        }

        foreach ($runs as $run) {
            if (($run['acquisition_kind'] ?? '') !== 'resume_suitable') {
                continue;
            }
            $status = (string) ($run['status'] ?? '');
            if (!in_array($status, ['success', 'partial', 'running'], true)) {
                continue;
            }
            $runId = trim((string) ($run['id'] ?? ''));
            if ($runId === '') {
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
                $vacancyExt = trim((string) ($item['source_external_id'] ?? ''));
                if ($vacancyExt === '') {
                    $url = trim((string) ($serp['url'] ?? ''));
                    if ($url !== '' && preg_match('~/vacancy/(\d+)~', $url, $m) === 1) {
                        $vacancyExt = $m[1];
                    }
                }
                $companyName = trim((string) ($serp['company_name'] ?? ''));
                $companyExt = trim((string) ($serp['company_external_id'] ?? ''));
                if ($companyExt !== '' && str_starts_with($companyExt, 'name:')) {
                    $companyExt = '';
                }
                if ($companyName === '' && $companyExt === '') {
                    continue;
                }
                $title = trim((string) ($serp['title'] ?? ''));
                $this->touchCompany(
                    $byKey,
                    $vacancyToCompany,
                    $companyName,
                    $companyExt,
                    $vacancyExt,
                    $title,
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     * @param array<string, string> $vacancyToCompany
     * @param array<string, array{resume_external_id: string, resume_title: string, applied_at: string, source: string}> $appliedMap
     */
    private function applyChatRejections(array &$byKey, array &$vacancyToCompany, array $appliedMap): void
    {
        foreach ($this->rejectionChats() as $chat) {
            $companyName = trim($chat->companyName);
            $vacancyExt = trim($chat->vacancyExternalId);
            $title = trim($chat->vacancyTitle);
            if ($companyName === '' && $vacancyExt === '') {
                continue;
            }

            $key = '';
            if ($vacancyExt !== '' && isset($vacancyToCompany[$vacancyExt])) {
                $key = $vacancyToCompany[$vacancyExt];
            }
            if ($key === '' && $companyName !== '') {
                $key = $this->findKeyByName($byKey, $companyName);
            }
            if ($key === '') {
                $key = $this->touchCompany(
                    $byKey,
                    $vacancyToCompany,
                    $companyName,
                    '',
                    $vacancyExt,
                    $title,
                );
            }
            if ($key === '' || !isset($byKey[$key])) {
                continue;
            }

            // Вакансия из чата тоже входит в счётчик (не только suitable-run).
            if ($vacancyExt !== '') {
                $ids = is_array($byKey[$key]['vacancy_ids'] ?? null) ? $byKey[$key]['vacancy_ids'] : [];
                $ids[$vacancyExt] = true;
                $byKey[$key]['vacancy_ids'] = $ids;
                $vacancyToCompany[$vacancyExt] = $key;
            }

            if (!$chat->isRejection()) {
                continue;
            }
            $chat = $chat->withClosedVacancyNormalized();

            $byKey[$key]['rejected'] = true;
            $rejKey = $vacancyExt !== '' ? $vacancyExt : ('t:' . mb_strtolower($title, 'UTF-8'));
            $existing = is_array($byKey[$key]['rejections'] ?? null) ? $byKey[$key]['rejections'] : [];
            if (isset($existing[$rejKey])) {
                continue;
            }
            $at = trim($chat->lastMessageAt);
            if ($at === '') {
                $at = trim($chat->lastActivityAt);
            }
            $resumeTitle = trim($chat->resumeTitle);
            $resumeId = trim($chat->resumeExternalId);
            $meta = $appliedMap[$vacancyExt] ?? null;
            if ($meta === null && $vacancyExt !== '' && ctype_digit($vacancyExt)) {
                $meta = $appliedMap[(string) (int) $vacancyExt] ?? null;
            }
            if (is_array($meta)) {
                if ($resumeTitle === '') {
                    $resumeTitle = trim((string) ($meta['resume_title'] ?? ''));
                }
                if ($resumeId === '') {
                    $resumeId = trim((string) ($meta['resume_external_id'] ?? ''));
                }
            }
            $existing[$rejKey] = [
                'vacancy_external_id' => $vacancyExt,
                'vacancy_title' => $title !== '' ? $title : ('Вакансия ' . $vacancyExt),
                'rejected_at' => $at,
                'status_label' => $chat->displayStatusLabel() !== '' ? $chat->displayStatusLabel() : 'Отказ',
                'resume_external_id' => $resumeId,
                'resume_title' => $resumeTitle,
            ];
            $byKey[$key]['rejections'] = $existing;
        }

        foreach ($byKey as $key => $row) {
            $rejections = is_array($row['rejections'] ?? null) ? array_values($row['rejections']) : [];
            usort(
                $rejections,
                static fn (array $a, array $b): int => strcmp(
                    (string) ($b['rejected_at'] ?? ''),
                    (string) ($a['rejected_at'] ?? ''),
                ),
            );
            $byKey[$key]['rejections'] = $rejections;
            $byKey[$key]['rejected'] = $rejections !== [];
            $byKey[$key]['vacancy_count'] = count(
                is_array($row['vacancy_ids'] ?? null) ? $row['vacancy_ids'] : [],
            );
            unset($byKey[$key]['vacancy_ids']);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     * @param array<string, string> $vacancyToCompany
     */
    private function touchCompany(
        array &$byKey,
        array &$vacancyToCompany,
        string $companyName,
        string $companyExt,
        string $vacancyExt,
        string $vacancyTitle,
    ): string {
        $key = $this->companyKey($companyName, $companyExt);
        if ($key === '') {
            return '';
        }

        if (!isset($byKey[$key])) {
            // Если уже есть запись по имени без id — подтянем к id-ключу.
            $nameKey = $this->companyKey($companyName, '');
            if ($companyExt !== '' && $nameKey !== '' && isset($byKey[$nameKey]) && $nameKey !== $key) {
                $byKey[$key] = $byKey[$nameKey];
                unset($byKey[$nameKey]);
                foreach ($vacancyToCompany as $vid => $ck) {
                    if ($ck === $nameKey) {
                        $vacancyToCompany[$vid] = $key;
                    }
                }
            } else {
                $byKey[$key] = [
                    'company_key' => $key,
                    'company_name' => $companyName !== '' ? $companyName : ('Компания ' . $companyExt),
                    'company_external_id' => $companyExt,
                    'vacancy_ids' => [],
                    'vacancy_count' => 0,
                    'rejected' => false,
                    'rejections' => [],
                ];
            }
        }

        if ($companyName !== '' && (
            trim((string) ($byKey[$key]['company_name'] ?? '')) === ''
            || str_starts_with((string) $byKey[$key]['company_name'], 'Компания ')
        )) {
            $byKey[$key]['company_name'] = $companyName;
        }
        if ($companyExt !== '' && trim((string) ($byKey[$key]['company_external_id'] ?? '')) === '') {
            $byKey[$key]['company_external_id'] = $companyExt;
        }

        if ($vacancyExt !== '') {
            $ids = is_array($byKey[$key]['vacancy_ids'] ?? null) ? $byKey[$key]['vacancy_ids'] : [];
            $ids[$vacancyExt] = true;
            $byKey[$key]['vacancy_ids'] = $ids;
            $vacancyToCompany[$vacancyExt] = $key;
        }

        return $key;
    }

    private function companyKey(string $companyName, string $companyExt): string
    {
        $companyExt = trim($companyExt);
        if ($companyExt !== '' && !str_starts_with($companyExt, 'name:')) {
            return 'id:' . $companyExt;
        }
        $norm = ResumeViewsStore::normalizeName($companyName);
        if ($norm === '') {
            return '';
        }

        return 'name:' . $norm;
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     */
    private function findKeyByName(array $byKey, string $companyName): string
    {
        $want = ResumeViewsStore::normalizeName($companyName);
        if ($want === '') {
            return '';
        }
        $nameKey = 'name:' . $want;
        if (isset($byKey[$nameKey])) {
            return $nameKey;
        }
        foreach ($byKey as $key => $row) {
            if (ResumeViewsStore::normalizeName((string) ($row['company_name'] ?? '')) === $want) {
                return (string) $key;
            }
        }

        return '';
    }

    /** @return list<ChatSummary> */
    private function rejectionChats(): array
    {
        /** @var array<string, ChatSummary> $byKey */
        $byKey = [];
        foreach ($this->chats->listAll() as $chat) {
            if (!$chat->isRejection()) {
                continue;
            }
            $byKey[$this->rejectionKey($chat)] = $chat->withClosedVacancyNormalized();
        }
        foreach ($this->rejectionArchive?->all() ?? [] as $chat) {
            if (!$chat->isRejection()) {
                continue;
            }
            $key = $this->rejectionKey($chat);
            if (!isset($byKey[$key])) {
                $byKey[$key] = $chat->withClosedVacancyNormalized();
            }
        }

        return array_values($byKey);
    }

    private function rejectionKey(ChatSummary $chat): string
    {
        if ($chat->chatId !== '') {
            return 'c:' . $chat->chatId;
        }
        if ($chat->vacancyExternalId !== '') {
            return 'v:' . $chat->vacancyExternalId;
        }

        return 't:' . mb_strtolower($chat->companyName . '|' . $chat->vacancyTitle, 'UTF-8');
    }
}
