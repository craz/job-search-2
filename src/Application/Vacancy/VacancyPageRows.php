<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Application\Company\CompanyExclusionStore;
use JobSearch\Application\Company\CompanyRejectionIndex;
use JobSearch\Application\Error\AppErrorStore;
use JobSearch\Application\Resume\ResumeViewsStore;

/**
 * Аннотация строк списка: applied / views / отказы / owner / ошибки + счётчики.
 */
final class VacancyPageRows
{
    public function __construct(private readonly VacancyPageFilter $filter) {}

    /**
     * @param list<array<string, mixed>> $all
     * @param array<string, array{applied: true, resume_external_id: string, resume_title: string}> $applied
     * @param array<string, array{company_name: string, viewed_at: list<string>}> $viewEmployers
     * @param array{by_id: array<string, mixed>, by_name: array<string, mixed>} $rejectionIndex
     * @param array{by_id: array<string, mixed>, by_name: array<string, mixed>} $exclusionIndex
     * @param array<string, array{resume_id: string, resume_title: string, reason: string, candidates?: list<string>}> $owners
     * @param array<string, array{peers: list<string>, earliest_resume_id: string}> $peerRich
     * @param array<string, string> $titlesByResume
     * @param array<string, array{message: string, at: string, source: string}> $errorsByPair
     * @param array<string, array{message: string, at: string, source: string}> $skippedByPair
     * @param VacancyPageCollection $collection
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   counts: array{all: int, consider: int, sent: int, errors: int, skip: int, tg_skip: int, pending: int, viewed: int, rejected: int, duplicates: int}
     * }
     */
    public function annotate(
        array $all,
        string $resumeId,
        array $applied,
        array $viewEmployers,
        array $rejectionIndex,
        array $exclusionIndex,
        array $owners,
        array $peerRich,
        array $titlesByResume,
        array $errorsByPair,
        VacancyPageCollection $collection,
        array $skippedByPair = [],
    ): array {
        $counts = $this->filter->emptyCounts();
        foreach ($all as $index => $row) {
            $extId = trim((string) ($row['external_id'] ?? ''));
            $meta = $collection->appliedMeta($applied, $extId);
            if ($meta !== null) {
                $all[$index]['applied'] = true;
                if (trim((string) ($all[$index]['applied_resume_id'] ?? '')) === '') {
                    $all[$index]['applied_resume_id'] = (string) ($meta['resume_external_id'] ?? '');
                }
                if (trim((string) ($all[$index]['applied_resume_title'] ?? '')) === '') {
                    $all[$index]['applied_resume_title'] = (string) ($meta['resume_title'] ?? '');
                }
            } elseif (!array_key_exists('applied', $all[$index])) {
                $all[$index]['applied'] = false;
                $all[$index]['applied_resume_id'] = '';
                $all[$index]['applied_resume_title'] = '';
            }
            $viewedAt = $this->resumeViewedAt($viewEmployers, $all[$index]);
            $all[$index]['resume_viewed_at'] = $viewedAt ?? '';
            $rejectHint = CompanyRejectionIndex::hintFor($rejectionIndex, $all[$index]);
            $all[$index]['company_rejected'] = $rejectHint !== null;
            $all[$index]['company_rejection_hint'] = $rejectHint ?? '';
            $exclHint = CompanyExclusionStore::hintFor($exclusionIndex, $all[$index]);
            $all[$index]['company_excluded'] = $exclHint !== null;
            $all[$index]['company_exclusion_hint'] = $exclHint ?? '';

            $ownership = $collection->ownershipFor(
                $extId,
                $resumeId,
                $owners,
                $peerRich,
                $titlesByResume,
            );
            $all[$index]['is_duplicate'] = $ownership['is_duplicate'];
            $all[$index]['owner_resume_id'] = $ownership['owner_resume_id'];
            $all[$index]['owner_resume_title'] = $ownership['owner_resume_title'];
            $all[$index]['owner_reason'] = $ownership['owner_reason'];
            $all[$index]['owner_source'] = $ownership['owner_source'];
            $all[$index]['can_apply'] = !$ownership['is_duplicate']
                && ($ownership['owner_resume_id'] === '' || $ownership['owner_resume_id'] === $resumeId);

            $errKey = $resumeId . "\0" . $extId;
            $err = $extId !== '' ? ($errorsByPair[$errKey] ?? null) : null;
            $skipHint = $extId !== '' ? ($skippedByPair[$errKey] ?? null) : null;
            // Уже откликнулись (в т.ч. вручную → чат) — ошибку apply не показываем.
            if (
                empty($all[$index]['applied'])
                && is_array($err)
                && trim((string) ($err['message'] ?? '')) !== ''
            ) {
                $msg = trim((string) $err['message']);
                $all[$index]['apply_error'] = true;
                $all[$index]['apply_error_message'] = $msg;
                $all[$index]['apply_error_at'] = trim((string) ($err['at'] ?? ''));
                $all[$index]['apply_error_letter'] = AppErrorStore::isLetterRequiredMessage($msg)
                    && !AppErrorStore::isQuestionsRequiredMessage($msg);
                $all[$index]['apply_error_questions'] = AppErrorStore::isQuestionsRequiredMessage($msg);
                $all[$index]['apply_skipped'] = false;
            } elseif (
                empty($all[$index]['applied'])
                && is_array($skipHint)
                && trim((string) ($skipHint['message'] ?? '')) !== ''
            ) {
                $all[$index]['apply_error'] = false;
                $all[$index]['apply_error_message'] = '';
                $all[$index]['apply_error_at'] = '';
                $all[$index]['apply_error_letter'] = false;
                $all[$index]['apply_error_questions'] = false;
                $all[$index]['apply_skipped'] = true;
                $all[$index]['apply_skipped_message'] = trim((string) ($skipHint['message'] ?? ''));
                $all[$index]['apply_skipped_at'] = trim((string) ($skipHint['at'] ?? ''));
            } else {
                $all[$index]['apply_error'] = false;
                $all[$index]['apply_error_message'] = '';
                $all[$index]['apply_error_at'] = '';
                $all[$index]['apply_error_letter'] = false;
                $all[$index]['apply_error_questions'] = false;
                $all[$index]['apply_skipped'] = false;
                $all[$index]['apply_skipped_message'] = '';
                $all[$index]['apply_skipped_at'] = '';
            }

            if ($ownership['is_duplicate']) {
                $counts['duplicates']++;
                continue;
            }

            $counts['all']++;
            if ($viewedAt !== null) {
                $counts['viewed']++;
            }
            if (!empty($all[$index]['company_rejected'])) {
                $counts['rejected']++;
            }
            if (!empty($all[$index]['applied'])) {
                $counts['sent']++;
            } elseif (!empty($all[$index]['apply_error'])) {
                $counts['errors']++;
            } elseif (!empty($all[$index]['apply_skipped'])) {
                $counts['tg_skip']++;
            }
            $tone = $this->filter->tone((string) ($row['verdict'] ?? ''));
            if ($tone === 'skip') {
                $counts['skip']++;
            } elseif ($tone === 'consider') {
                if ($this->filter->stillConsidering($all[$index])) {
                    $counts['consider']++;
                }
            } else {
                $counts['pending']++;
            }
        }

        return ['items' => $all, 'counts' => $counts];
    }

    /**
     * @param list<array<string, mixed>> $all
     * @param array<string, array{applied: true, resume_external_id: string, resume_title: string}> $applied
     * @param array<string, array{company_name: string, viewed_at: list<string>}> $viewEmployers
     * @param array{by_id: array<string, mixed>, by_name: array<string, mixed>} $rejectionIndex
     * @param array{by_id: array<string, mixed>, by_name: array<string, mixed>} $exclusionIndex
     * @param VacancyPageCollection $collection
     *
     * @return array<string, mixed>
     */
    public function hydrateSelected(
        array $selected,
        array $all,
        array $applied,
        array $viewEmployers,
        array $rejectionIndex,
        array $exclusionIndex,
        ListForResume $list,
        VacancyPageCollection $collection,
    ): array {
        if (trim((string) ($selected['core_id'] ?? '')) !== '') {
            $selected = $list->hydrateDetail($selected);
        }
        $extId = trim((string) ($selected['external_id'] ?? ''));
        $meta = $collection->appliedMeta($applied, $extId);
        if ($meta !== null) {
            $selected['applied'] = true;
            if (trim((string) ($selected['applied_resume_id'] ?? '')) === '') {
                $selected['applied_resume_id'] = (string) ($meta['resume_external_id'] ?? '');
            }
            if (trim((string) ($selected['applied_resume_title'] ?? '')) === '') {
                $selected['applied_resume_title'] = (string) ($meta['resume_title'] ?? '');
            }
        }
        foreach ($all as $row) {
            if (trim((string) ($row['external_id'] ?? '')) !== $extId) {
                continue;
            }
            foreach ([
                'chat_status',
                'chat_status_label',
                'applied_at',
                'activity_at',
                'origin',
                'resume_viewed_at',
                'company_external_id',
                'owner_resume_id',
                'owner_resume_title',
                'owner_reason',
                'owner_source',
                'company_rejection_hint',
                'apply_error_message',
                'apply_error_at',
            ] as $key) {
                if (trim((string) ($selected[$key] ?? '')) === '' && trim((string) ($row[$key] ?? '')) !== '') {
                    $selected[$key] = $row[$key];
                }
            }
            if (!empty($row['applied'])) {
                $selected['applied'] = true;
            }
            if (array_key_exists('is_duplicate', $row)) {
                $selected['is_duplicate'] = !empty($row['is_duplicate']);
            }
            if (array_key_exists('can_apply', $row)) {
                $selected['can_apply'] = !empty($row['can_apply']);
            }
            if (!empty($row['company_rejected'])) {
                $selected['company_rejected'] = true;
            }
            if (!empty($row['apply_error'])) {
                $selected['apply_error'] = true;
            }
            if (!empty($row['apply_error_letter'])) {
                $selected['apply_error_letter'] = true;
            }
            if (!empty($row['apply_error_questions'])) {
                $selected['apply_error_questions'] = true;
            }
            break;
        }
        if (trim((string) ($selected['resume_viewed_at'] ?? '')) === '') {
            $viewedAt = $this->resumeViewedAt($viewEmployers, $selected);
            if ($viewedAt !== null) {
                $selected['resume_viewed_at'] = $viewedAt;
            }
        }
        $rejectHint = CompanyRejectionIndex::hintFor($rejectionIndex, $selected);
        if ($rejectHint !== null) {
            $selected['company_rejected'] = true;
            $selected['company_rejection_hint'] = $rejectHint;
        }
        $exclHint = CompanyExclusionStore::hintFor($exclusionIndex, $selected);
        if ($exclHint !== null) {
            $selected['company_excluded'] = true;
            $selected['company_exclusion_hint'] = $exclHint;
        }

        return $selected;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $selected
     * @param array{all: int, consider: int, sent: int, errors: int, skip: int, pending: int, viewed: int, rejected: int, duplicates: int} $counts
     *
     * @return list<array<string, mixed>>
     */
    public function syncSelectedIntoItems(array $items, array $selected, array &$counts): array
    {
        $selExt = trim((string) ($selected['external_id'] ?? ''));
        $selCore = trim((string) ($selected['core_id'] ?? ''));
        if ($selExt === '' && $selCore === '') {
            return $items;
        }
        $selCompanyExt = trim((string) ($selected['company_external_id'] ?? ''));
        $selViewed = trim((string) ($selected['resume_viewed_at'] ?? ''));

        foreach ($items as $i => $row) {
            $ext = trim((string) ($row['external_id'] ?? ''));
            $core = trim((string) ($row['core_id'] ?? ''));
            if (($selExt === '' || $ext !== $selExt) && ($selCore === '' || $core !== $selCore)) {
                continue;
            }
            if ($selCompanyExt !== '' && trim((string) ($items[$i]['company_external_id'] ?? '')) === '') {
                $items[$i]['company_external_id'] = $selCompanyExt;
            }
            $hadViewed = trim((string) ($items[$i]['resume_viewed_at'] ?? '')) !== '';
            if ($selViewed !== '' && !$hadViewed) {
                $items[$i]['resume_viewed_at'] = $selViewed;
                $counts['viewed']++;
            }
            if (!empty($selected['company_rejected'])) {
                $wasRejected = !empty($items[$i]['company_rejected']);
                $items[$i]['company_rejected'] = true;
                $hint = trim((string) ($selected['company_rejection_hint'] ?? ''));
                if ($hint !== '' && trim((string) ($items[$i]['company_rejection_hint'] ?? '')) === '') {
                    $items[$i]['company_rejection_hint'] = $hint;
                }
                if (!$wasRejected) {
                    $counts['rejected']++;
                    if (
                        $this->filter->tone((string) ($items[$i]['verdict'] ?? '')) === 'consider'
                        && $counts['consider'] > 0
                    ) {
                        $counts['consider']--;
                    }
                }
            }
            break;
        }

        return $items;
    }

    /**
     * @param array<string, array{company_name: string, viewed_at: list<string>}> $employers
     * @param array<string, mixed> $row
     */
    public function resumeViewedAt(array $employers, array $row): ?string
    {
        if ($employers === []) {
            return null;
        }
        $companyExt = trim((string) ($row['company_external_id'] ?? ''));
        $byId = ResumeViewsStore::latestAt($employers, $companyExt);
        if ($byId !== null) {
            return $byId;
        }

        return ResumeViewsStore::latestAtByCompanyName(
            $employers,
            trim((string) ($row['company_name'] ?? '')),
        );
    }
}
