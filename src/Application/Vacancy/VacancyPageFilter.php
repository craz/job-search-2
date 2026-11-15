<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Domain\Vacancy\HhVacancyClosed;

/**
 * Фильтры / поиск / нормализация входа страницы вакансий.
 */
final class VacancyPageFilter
{
    /** @return array{all: int, consider: int, sent: int, errors: int, skip: int, tg_skip: int, pending: int, viewed: int, rejected: int, duplicates: int} */
    public function emptyCounts(): array
    {
        return [
            'all' => 0,
            'consider' => 0,
            'sent' => 0,
            'errors' => 0,
            'skip' => 0,
            'tg_skip' => 0,
            'pending' => 0,
            'viewed' => 0,
            'rejected' => 0,
            'duplicates' => 0,
        ];
    }

    public function normalizeFilter(string $filter): string
    {
        $filter = strtolower(trim($filter));

        return in_array(
            $filter,
            ['all', 'consider', 'sent', 'errors', 'skip', 'tg_skip', 'pending', 'viewed', 'rejected', 'duplicates'],
            true,
        ) ? $filter : 'all';
    }

    /**
     * @param list<array{id?: mixed, title?: mixed}|mixed> $resumes
     *
     * @return list<array{id: string, title: string}>
     */
    public function normalizeResumes(array $resumes): array
    {
        $out = [];
        foreach ($resumes as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $out[] = ['id' => $id, 'title' => $title !== '' ? $title : $id];
        }

        return $out;
    }

    /** ID из `139203618` или `https://hh.ru/vacancy/139203618?...`. */
    public function vacancyIdFromQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        if (preg_match('~(?:hh\.ru)?/vacancy/(\d+)~i', $query, $m) === 1) {
            return $m[1];
        }
        if (preg_match('~^\d{5,}$~', $query) === 1) {
            return $query;
        }

        return '';
    }

    /** @param array<string, mixed> $row */
    public function matchesQuery(array $row, string $query, string $vacancyId): bool
    {
        if ($vacancyId !== '') {
            return trim((string) ($row['external_id'] ?? '')) === $vacancyId;
        }
        $hay = mb_strtolower(
            trim((string) ($row['title'] ?? '')) . ' '
            . trim((string) ($row['company_name'] ?? '')) . ' '
            . trim((string) ($row['external_id'] ?? '')),
            'UTF-8',
        );

        return str_contains($hay, mb_strtolower($query, 'UTF-8'));
    }

    /** @param array<string, mixed> $row */
    public function matchesFilter(array $row, string $filter): bool
    {
        $isDup = !empty($row['is_duplicate']);
        if ($filter === 'duplicates') {
            return $isDup;
        }
        if ($isDup) {
            return false;
        }
        if ($filter === 'all') {
            return true;
        }
        if ($filter === 'viewed') {
            return trim((string) ($row['resume_viewed_at'] ?? '')) !== '';
        }
        if ($filter === 'rejected') {
            return !empty($row['company_rejected']);
        }
        if ($filter === 'sent') {
            return !empty($row['applied']);
        }
        if ($filter === 'errors') {
            return !empty($row['apply_error']) && empty($row['applied']) && empty($row['apply_skipped']);
        }
        if ($filter === 'tg_skip') {
            return !empty($row['apply_skipped']) && empty($row['applied']);
        }
        $tone = $this->tone((string) ($row['verdict'] ?? ''));
        if ($filter === 'consider') {
            return $tone === 'consider' && $this->stillConsidering($row);
        }

        return $tone === $filter;
    }

    /**
     * Ещё в «Смотрим»: без отказа/исключения/отклика/ошибки apply/скипа формы / архива HH.
     *
     * @param array<string, mixed> $row
     */
    public function stillConsidering(array $row): bool
    {
        if ($this->isClosedOnHh($row)) {
            return false;
        }

        return empty($row['company_rejected'])
            && empty($row['company_excluded'])
            && empty($row['applied'])
            && empty($row['apply_error'])
            && empty($row['apply_skipped']);
    }

    /**
     * Архив / снята: не в «Смотрим» (в т.ч. title «…В архиве с 13 ноября…» после кривого scrape).
     *
     * @param array<string, mixed> $row
     */
    public function isClosedOnHh(array $row): bool
    {
        if (!empty($row['archived'])) {
            return true;
        }
        $status = strtolower(trim((string) ($row['source_status'] ?? '')));
        if ($status === 'archived') {
            return true;
        }

        return HhVacancyClosed::looksLike(
            (string) ($row['title'] ?? ''),
            (string) ($row['description'] ?? ''),
        );
    }

    public function tone(string $verdict): string
    {
        $v = strtolower(trim($verdict));
        if ($v === 'skip' || $v === 'reject' || $v === 'no') {
            return 'skip';
        }
        if ($v === 'maybe' || $v === 'apply' || $v === 'consider' || $v === 'ok' || $v === 'yes') {
            return 'consider';
        }

        return 'pending';
    }
}
