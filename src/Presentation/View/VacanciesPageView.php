<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

use JobSearch\Application\Error\AppErrorStore;

/**
 * Страница скачанных вакансий по резюме: фильтр вердикта + список + деталь.
 */
final class VacanciesPageView implements View
{
    public function __construct(private readonly ?ViteAssets $vite = null) {}

    /** @param array<string, mixed> $data */
    public function render(array $data): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->page($data);
    }

    /** @param array<string, mixed> $data */
    private function page(array $data): string
    {
        $error = $this->str($data['error'] ?? null);
        $flash = $this->str($data['flash'] ?? null);
        $flashOk = (bool) ($data['flash_ok'] ?? true);
        $watch = (bool) ($data['watch'] ?? false);
        $resumeId = $this->str($data['resume_id'] ?? null);
        $resumeTitle = $this->str($data['resume_title'] ?? null);
        $filter = $this->str($data['filter'] ?? 'all') ?: 'all';
        $scope = $this->str($data['scope'] ?? 'recent') ?: 'recent';
        $query = $this->str($data['query'] ?? null);
        $vacancyId = $this->str($data['vacancy_id'] ?? null);
        $recentDays = (int) ($data['recent_days'] ?? 3);
        $historySince = $this->str($data['history_since'] ?? null) ?: '2026-10-28';
        $recentCount = (int) ($data['recent_count'] ?? 0);
        $totalAll = (int) ($data['total_all'] ?? 0);
        /** @var list<array{id?: string, title?: string, error_count?: int}> $resumes */
        $resumes = is_array($data['resumes'] ?? null) ? $data['resumes'] : [];
        /** @var array{all?: int, consider?: int, sent?: int, errors?: int, skip?: int, pending?: int, viewed?: int, rejected?: int, duplicates?: int} $counts */
        $counts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
        /** @var list<array<string, mixed>> $items */
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        /** @var array<string, mixed>|null $selected */
        $selected = is_array($data['selected'] ?? null) ? $data['selected'] : null;
        $resumeErrorCount = (int) ($data['resume_error_count'] ?? 0);
        $heading = $resumeTitle !== '' ? $resumeTitle : ($resumeId !== '' ? $resumeId : 'Вакансии');
        if ($resumeErrorCount > 0) {
            $heading .= ' · ошибки отклика: ' . $resumeErrorCount;
        }
        $viteTags = $this->vite !== null ? $this->vite->tags('vacancies') : '';

        return '<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Вакансии — job_search_2</title>
  <style>html,body{margin:0;background:#0f1115;color:#e8edf5}</style>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,500,0,0&display=swap">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/style.css">
</head>
<body>
  ' . $this->topbar($resumeId) . '
  <main class="wrap wrap-wide">
    <section class="panel vacancies-page">
      <div class="panel-head">
        <div>
          <h1 class="page-title">Вакансии</h1>
          <p class="muted vacancies-page-sub">' . $this->e($heading) . '</p>
        </div>
        <div class="vacancies-page-head-actions">
          ' . ($resumeId !== ''
            ? '<a class="btn btn-sm btn-secondary" href="/?busy=resume_views&amp;resume='
                . rawurlencode($resumeId)
                . ($filter !== 'all' ? '&amp;filter=' . rawurlencode($filter) : '')
                . ($scope === 'all' ? '&amp;scope=all' : '')
                . '">Просмотры резюме</a>'
            : '') . '
          <a class="btn btn-sm btn-secondary" href="/">← На главную</a>
        </div>
      </div>
      ' . $this->scheduleNote(is_array($data['schedule'] ?? null) ? $data['schedule'] : []) . '
      ' . $this->resumeSwitcher($resumeId, $resumes, $filter, $scope, $query) . '
      ' . $this->searchForm($resumeId, $filter, $scope, $query, $vacancyId) . '
      ' . $this->pageLegend() . '
      ' . $this->flashBlock($flash, $flashOk) . '
      ' . $this->scopeBar($resumeId, $filter, $scope, $recentDays, $recentCount, $totalAll, $query, $historySince) . '
      ' . $this->filters($resumeId, $filter, $scope, $counts, $query) . '
      ' . $this->considerBatchApply($resumeId, $filter, $scope, $counts) . '
      ' . ($error !== '' ? '<p class="err">' . $this->e($error) . '</p>' : '') . '
      ' . $this->layout($resumeId, $filter, $scope, $items, $selected, $watch, $query) . '
    </section>
  </main>
  <footer class="footer"><div class="footer-inner">SearchRun resume_suitable · вердикты DevAI</div></footer>
  ' . $viteTags . '
</body>
</html>
';
    }

    private function searchForm(
        string $resumeId,
        string $filter,
        string $scope,
        string $query,
        string $vacancyId,
    ): string {
        if ($resumeId === '') {
            return '';
        }
        $hint = $vacancyId !== ''
            ? 'Ищу HH id <code>' . $this->e($vacancyId) . '</code>.'
            : 'ID вакансии, ссылка hh.ru/vacancy/… или текст названия/компании.';
        $reset = '/?page=vacancies&amp;resume=' . rawurlencode($resumeId)
            . ($filter !== 'all' ? '&amp;filter=' . rawurlencode($filter) : '')
            . ($scope === 'all' ? '&amp;scope=all' : '');

        return '<form class="vacancies-search" method="get" action="/">'
            . '<input type="hidden" name="page" value="vacancies">'
            . '<input type="hidden" name="resume" value="' . $this->e($resumeId) . '">'
            . ($filter !== 'all' ? '<input type="hidden" name="filter" value="' . $this->e($filter) . '">' : '')
            . ($scope === 'all' ? '<input type="hidden" name="scope" value="all">' : '')
            . '<label class="vacancies-search-label" for="vacancies-q">Поиск</label>'
            . '<input id="vacancies-q" class="vacancies-search-input" type="search" name="q" value="'
            . $this->e($query) . '" placeholder="139203618 или https://hh.ru/vacancy/139203618" autocomplete="off">'
            . '<button class="btn btn-sm btn-secondary" type="submit">Найти</button>'
            . ($query !== '' ? '<a class="btn btn-sm btn-ghost" href="' . $reset . '">Сброс</a>' : '')
            . '<span class="vacancies-search-hint muted">' . $hint . '</span>'
            . '</form>';
    }

    private function topbar(string $resumeId): string
    {
        $vacanciesHref = '/?page=vacancies'
            . ($resumeId !== '' ? '&amp;resume=' . rawurlencode($resumeId) : '');

        return '<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/">job_search_2</a>
    <nav class="top-nav">
      <a class="top-nav-link" href="/">Главная</a>
      <a class="top-nav-link" href="http://127.0.0.1:3000/d/job-search-2/poisk-raboty?orgId=1&amp;refresh=30s" target="_blank" rel="noopener">Статистика</a>
      <a class="top-nav-link" href="/?page=applications">Отклики</a>
      <a class="top-nav-link is-active" href="' . $vacanciesHref . '">Вакансии</a>
      <a class="top-nav-link" href="/?page=companies">Компании</a>
      <a class="top-nav-link" href="/?page=exclusions">Исключения</a>
      <a class="top-nav-link" href="/?page=rejections">Отказы</a>
    </nav>
    <div class="topbar-right">
      <a class="top-nav-link" href="/?page=rehydrate">Перекачка</a>
      <a class="top-nav-link" href="/?page=errors">Ошибки</a>
      <a class="top-nav-link" href="/?page=chats">Чат</a>
    </div>
  </div>
</header>';
    }

    /** @param array<string, mixed> $schedule */
    /** Легенда вердиктов и иконок списка — сетка, без лапши в одну строку. */
    private function pageLegend(): string
    {
        $chip = static function (string $cls, string $html): string {
            return '<li class="vacancies-legend-chip ' . $cls . '">' . $html . '</li>';
        };

        $tones = '<ul class="vacancies-legend-chips">'
            . $chip('is-consider', '<span class="vacancies-legend-swatch" aria-hidden="true"></span>apply / maybe')
            . $chip('is-skip', '<span class="vacancies-legend-swatch" aria-hidden="true"></span>skip')
            . $chip('is-pending', '<span class="vacancies-legend-swatch" aria-hidden="true"></span>без оценки')
            . '</ul>';

        $icons = '<ul class="vacancies-legend-chips">'
            . $chip('is-applied', $this->materialIcon('mark_email_read') . ' отклик')
            . $chip('is-viewed', $this->materialIcon('visibility') . ' смотрели')
            . $chip('is-dup', $this->materialIcon('content_copy') . ' дубль')
            . $chip('is-reject', $this->materialIcon('cancel') . ' отказ компании')
            . $chip('is-error', $this->materialIcon('error') . ' ошибка')
            . $chip('is-letter', $this->materialIcon('description') . ' письмо')
            . $chip('is-questions', $this->materialIcon('quiz') . ' анкета')
            . $chip('is-tg-skip', $this->materialIcon('skip_next') . ' скип TG')
            . '</ul>';

        return '<div class="vacancies-page-legend" role="note">'
            . '<div class="vacancies-legend-row">'
            . '<div class="vacancies-legend-label">Вердикт</div>'
            . $tones
            . '</div>'
            . '<div class="vacancies-legend-row">'
            . '<div class="vacancies-legend-label">Метки</div>'
            . $icons
            . '</div>'
            . '<p class="vacancies-legend-note muted">'
            . '«Откликнуться» — Playwright · «Открыть на hh.ru» — сайт'
            . '</p>'
            . '</div>';
    }

    private function scheduleNote(array $schedule): string
    {
        $note = ScheduleNote::resumeViews($schedule);

        return '<p class="muted vacancies-schedule-note js-schedule-status" data-schedule-task="resume_views">'
            . $this->e($note !== '' ? $note : 'Просмотры: загружаю статус…')
            . '</p>';
    }

    /**
     * @param list<array{id?: string, title?: string, error_count?: int}> $resumes
     */
    private function resumeSwitcher(
        string $resumeId,
        array $resumes,
        string $filter,
        string $scope,
        string $query = '',
    ): string {
        if ($resumes === []) {
            return '';
        }
        $html = '<div class="vacancies-resume-row">'
            . '<form class="vacancies-resume-switch" method="get" action="/">'
            . '<input type="hidden" name="page" value="vacancies">'
            . ($filter !== 'all' ? '<input type="hidden" name="filter" value="' . $this->e($filter) . '">' : '')
            . ($scope === 'all' ? '<input type="hidden" name="scope" value="all">' : '')
            . ($query !== '' ? '<input type="hidden" name="q" value="' . $this->e($query) . '">' : '')
            . '<label class="vacancies-resume-label" for="vacancies-resume">Резюме</label>'
            . '<select id="vacancies-resume" name="resume" onchange="this.form.submit()">';
        $currentErrors = 0;
        foreach ($resumes as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $label = $title !== '' ? $title : $id;
            $errN = (int) ($row['error_count'] ?? 0);
            if ($errN > 0) {
                $label .= ' · ошибка' . ($errN > 1 ? ' ×' . $errN : '');
            }
            if ($id === $resumeId) {
                $currentErrors = $errN;
            }
            $sel = $id === $resumeId ? ' selected' : '';
            $html .= '<option value="' . $this->e($id) . '"' . $sel . '>'
                . $this->e($label) . '</option>';
        }
        $html .= '</select></form>';
        if ($currentErrors > 0) {
            $html .= '<a class="vacancies-resume-errors" href="/?page=errors&amp;filter=open"'
                . ' title="Открытые ошибки отклика по этому резюме">'
                . $this->materialIcon('error') . ' Ошибки '
                . CountBadge::html($currentErrors)
                . '</a>';
        }
        if ($resumeId !== '') {
            $html .= '<a class="btn btn-sm btn-secondary" href="/?busy=vacancies&amp;resume='
                . rawurlencode($resumeId)
                . '" title="Качать новые suitable и сразу оценивать DevAI">Качать suitable</a>';
        }

        return $html . '</div>';
    }

    private function scopeBar(
        string $resumeId,
        string $filter,
        string $scope,
        int $recentDays,
        int $recentCount,
        int $totalAll,
        string $query = '',
        string $historySince = '2026-10-28',
    ): string {
        if ($resumeId === '') {
            return '';
        }
        $q = $query !== '' ? '&amp;q=' . rawurlencode($query) : '';
        $base = '/?page=vacancies&amp;resume=' . rawurlencode($resumeId)
            . ($filter !== 'all' ? '&amp;filter=' . rawurlencode($filter) : '')
            . $q;
        $sinceLabel = $this->historySinceLabel($historySince);
        if ($scope === 'all') {
            return '<p class="vacancies-scope-bar">'
                . 'С ' . $this->e($sinceLabel) . ': <strong>' . $this->e((string) $totalAll) . '</strong>.'
                . ' <a class="vacancies-scope-link" href="' . $base . '">Только ' . $this->e((string) $recentDays) . ' дня</a>'
                . ' (' . $this->e((string) $recentCount) . ').'
                . '</p>';
        }
        $more = '';
        if ($totalAll > $recentCount) {
            $more = ' <a class="btn btn-sm btn-secondary" href="' . $base . '&amp;scope=all">Ещё'
                . ' (' . $this->e((string) ($totalAll - $recentCount)) . ' старше, с '
                . $this->e($sinceLabel) . ')</a>';
        }

        return '<p class="vacancies-scope-bar">'
            . 'За последние ' . $this->e((string) $recentDays) . ' дня: <strong>'
            . $this->e((string) $recentCount) . '</strong>'
            . ' из ' . $this->e((string) $totalAll) . ' с ' . $this->e($sinceLabel) . '.'
            . $more
            . '</p>';
    }

    /** Y-m-d → dd.mm.YYYY для подписи в UI. */
    private function historySinceLabel(string $ymd): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($ymd), $m) === 1) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }

        return $ymd;
    }

    /**
     * @param array{all?: int, consider?: int, sent?: int, errors?: int, tg_skip?: int, skip?: int, pending?: int, viewed?: int, rejected?: int, duplicates?: int} $counts
     */
    private function filters(
        string $resumeId,
        string $filter,
        string $scope,
        array $counts,
        string $query = '',
    ): string {
        if ($resumeId === '') {
            return '';
        }
        $tabs = [
            'all' => 'Все (' . (int) ($counts['all'] ?? 0) . ')',
            'consider' => 'Смотрим (' . (int) ($counts['consider'] ?? 0) . ')',
            'sent' => 'Отправлено (' . (int) ($counts['sent'] ?? 0) . ')',
            'errors' => 'Ошибки (' . (int) ($counts['errors'] ?? 0) . ')',
            'tg_skip' => 'Скип TG (' . (int) ($counts['tg_skip'] ?? 0) . ')',
            'skip' => 'Skip (' . (int) ($counts['skip'] ?? 0) . ')',
            'pending' => 'Без оценки (' . (int) ($counts['pending'] ?? 0) . ')',
            'viewed' => 'Смотрели (' . (int) ($counts['viewed'] ?? 0) . ')',
            'rejected' => 'Отказы (' . (int) ($counts['rejected'] ?? 0) . ')',
            'duplicates' => 'Дубли (' . (int) ($counts['duplicates'] ?? 0) . ')',
        ];
        $html = '<div class="filter-tabs">';
        $q = $query !== '' ? '&amp;q=' . rawurlencode($query) : '';
        foreach ($tabs as $key => $label) {
            $cls = $key === $filter ? 'filter-tab is-active' : 'filter-tab';
            $href = '/?page=vacancies&amp;resume=' . rawurlencode($resumeId)
                . ($key === 'all' ? '' : '&amp;filter=' . rawurlencode($key))
                . ($scope === 'all' ? '&amp;scope=all' : '')
                . $q;
            $html .= '<a class="' . $cls . '" href="' . $href . '">' . $this->e($label) . '</a>';
        }

        return $html . '</div>';
    }

    /**
     * На фильтре «Смотрим» — пакетный отклик Playwright.
     * При N > 15 основная кнопка — пачка 15 (норма дня), «все N» — отдельно.
     *
     * @param array{all?: int, consider?: int, sent?: int, errors?: int, tg_skip?: int, skip?: int, pending?: int, viewed?: int, rejected?: int, duplicates?: int} $counts
     */
    private function considerBatchApply(
        string $resumeId,
        string $filter,
        string $scope,
        array $counts,
    ): string {
        if ($resumeId === '' || $filter !== 'consider') {
            return '';
        }
        $n = (int) ($counts['consider'] ?? 0);
        if ($n <= 0) {
            return '';
        }
        $batch = 15;
        $base = '/?busy=apply_consider'
            . '&amp;resume=' . rawurlencode($resumeId)
            . '&amp;filter=consider'
            . ($scope === 'all' ? '&amp;scope=all' : '');
        if ($n <= $batch) {
            return '<p class="vacancies-batch-apply">'
                . '<a class="btn btn-sm btn-primary" href="' . $base . '">'
                . 'Откликнуться на ' . $this->e((string) $n)
                . '</a>'
                . ' <span class="muted">Playwright → hh.ru</span>'
                . '</p>';
        }

        $chunk = $batch;
        $hrefChunk = $base . '&amp;limit=' . $chunk;
        $hrefAll = $base;

        return '<p class="vacancies-batch-apply">'
            . '<a class="btn btn-sm btn-primary" href="' . $hrefChunk . '">'
            . 'Откликнуться на ' . $this->e((string) $chunk)
            . '</a>'
            . ' <a class="btn btn-sm btn-ghost" href="' . $hrefAll . '">'
            . 'все ' . $this->e((string) $n)
            . '</a>'
            . ' <span class="muted">пачка ' . $this->e((string) $chunk)
            . ' · норма 10/день · Playwright → hh.ru</span>'
            . '</p>';
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>|null $selected
     */
    private function layout(
        string $resumeId,
        string $filter,
        string $scope,
        array $items,
        ?array $selected,
        bool $watch = false,
        string $query = '',
    ): string {
        if ($resumeId === '') {
            return '<p class="muted">Выбери резюме выше или открой «Смотреть» на главной.</p>';
        }
        if ($items === []) {
            if ($query !== '') {
                return '<p class="muted">Среди скачанных вакансий ничего не найдено по запросу.</p>';
            }
            $hint = $scope === 'all'
                ? 'Для этого резюме скачанных вакансий нет.'
                : 'За последние 3 дня пусто. Нажми «Ещё» (с 28.10.2026), или скачай suitable выше.';

            return '<p class="muted">' . $hint . '</p>';
        }

        $selectedKey = '';
        if ($selected !== null) {
            $selectedKey = (string) ($selected['core_id'] ?? $selected['external_id'] ?? '');
        }

        $list = '<ul class="vacancies-page-list">';
        $q = $query !== '' ? '&amp;q=' . rawurlencode($query) : '';
        foreach ($items as $row) {
            $coreId = (string) ($row['core_id'] ?? '');
            $extId = (string) ($row['external_id'] ?? '');
            $key = $coreId !== '' ? $coreId : $extId;
            $tone = $this->tone((string) ($row['verdict'] ?? ''));
            $cls = 'vacancies-page-item'
                . ($tone === 'skip' ? ' is-skip' : ($tone === 'consider' ? ' is-consider' : ''))
                . ($key !== '' && $key === $selectedKey ? ' is-active' : '');
            $href = '/?page=vacancies&amp;resume=' . rawurlencode($resumeId)
                . ($filter !== 'all' ? '&amp;filter=' . rawurlencode($filter) : '')
                . ($scope === 'all' ? '&amp;scope=all' : '')
                . $q
                . '&amp;id=' . rawurlencode($key);
            $title = (string) ($row['title'] ?? '');
            $company = (string) ($row['company_name'] ?? '');
            $verdictLabel = $this->verdictLabel((string) ($row['verdict'] ?? ''));
            $discovered = RuDate::dateTime($row['discovered_at'] ?? null);
            $applied = !empty($row['applied']);
            $appliedResume = trim((string) ($row['applied_resume_title'] ?? ''));
            $chatStatus = trim((string) ($row['chat_status_label'] ?? ''));
            $viewedAt = RuDate::dateTime($row['resume_viewed_at'] ?? null);
            $isDup = !empty($row['is_duplicate']);
            $ownerTitle = trim((string) ($row['owner_resume_title'] ?? ''));
            $companyRejected = !empty($row['company_rejected']);
            $rejectHint = trim((string) ($row['company_rejection_hint'] ?? ''));
            $companyExcluded = !empty($row['company_excluded']);
            $exclHint = trim((string) ($row['company_exclusion_hint'] ?? ''));
            $applyError = !empty($row['apply_error']);
            $applyErrorMsg = trim((string) ($row['apply_error_message'] ?? ''));
            $applyErrorLetter = !empty($row['apply_error_letter']);
            $applyErrorQuestions = !empty($row['apply_error_questions']);
            $applySkipped = !empty($row['apply_skipped']);
            $applySkippedMsg = trim((string) ($row['apply_skipped_message'] ?? ''));
            $flags = '';
            if ($applyError) {
                $errTip = $applyErrorMsg !== '' ? $applyErrorMsg : 'Ошибка отклика';
                $flags .= '<span class="vacancies-list-flag vacancies-list-apply-error"'
                    . ' title="' . $this->e($errTip) . '">'
                    . $this->materialIcon('error', $errTip) . '</span>';
                if ($applyErrorQuestions) {
                    $qTip = 'Нужна анкета / вопросы работодателя';
                    $flags .= '<span class="vacancies-list-flag vacancies-list-questions-error"'
                        . ' title="' . $this->e($qTip) . '">'
                        . $this->materialIcon('quiz', $qTip) . '</span>';
                } elseif ($applyErrorLetter) {
                    $letterTip = 'Нужно сопроводительное письмо';
                    $flags .= '<span class="vacancies-list-flag vacancies-list-letter-error"'
                        . ' title="' . $this->e($letterTip) . '">'
                        . $this->materialIcon('description', $letterTip) . '</span>';
                }
            } elseif ($applySkipped) {
                $skipTip = $applySkippedMsg !== '' ? $applySkippedMsg : 'Скип формы в TG';
                $flags .= '<span class="vacancies-list-flag vacancies-list-tg-skip"'
                    . ' title="' . $this->e($skipTip) . '">'
                    . $this->materialIcon('skip_next', $skipTip) . '</span>';
            }
            if ($isDup) {
                $dupTip = 'Дубль · лучше к '
                    . ($ownerTitle !== '' ? $ownerTitle : 'другому резюме');
                $reason = trim((string) ($row['owner_reason'] ?? ''));
                if ($reason !== '') {
                    $dupTip .= ' — ' . $reason;
                }
                $flags .= '<span class="vacancies-list-flag vacancies-list-dup"'
                    . ' title="' . $this->e($dupTip) . '">'
                    . $this->materialIcon('content_copy', $dupTip) . '</span>';
            }
            if ($companyExcluded) {
                $exclTip = 'Компания в исключениях'
                    . ($exclHint !== '' ? ' · ' . $exclHint : '');
                $flags .= '<span class="vacancies-list-flag vacancies-list-company-exclude"'
                    . ' title="' . $this->e($exclTip) . '">'
                    . $this->materialIcon('block', $exclTip) . '</span>';
            }
            if ($companyRejected) {
                $rejTip = 'Компания уже отказывала'
                    . ($rejectHint !== '' ? ' · ' . $rejectHint : '');
                $flags .= '<span class="vacancies-list-flag vacancies-list-company-reject"'
                    . ' title="' . $this->e($rejTip) . '">'
                    . $this->materialIcon('cancel', $rejTip) . '</span>';
            }
            if ($viewedAt !== '') {
                $viewTip = 'Смотрели резюме · ' . $viewedAt;
                $flags .= '<span class="vacancies-list-flag vacancies-list-viewed"'
                    . ' title="' . $this->e($viewTip) . '">'
                    . $this->materialIcon('visibility', $viewTip) . '</span>';
            }
            if ($applied) {
                $applyTip = 'Отклик'
                    . ($appliedResume !== '' ? ' · ' . $appliedResume : '')
                    . ($chatStatus !== '' ? ' · ' . $chatStatus : '');
                $flags .= '<span class="vacancies-list-flag vacancies-list-applied"'
                    . ' title="' . $this->e($applyTip) . '">'
                    . $this->materialIcon('mark_email_read', $applyTip) . '</span>';
            }
            $list .= '<li><a class="' . $cls . ($applied ? ' is-applied' : '')
                . ($viewedAt !== '' ? ' is-viewed' : '')
                . ($companyRejected ? ' is-company-reject' : '')
                . ($applyError ? ' is-apply-error' : '')
                . ($applySkipped ? ' is-tg-skip' : '')
                . ($isDup ? ' is-dup' : '') . '" href="' . $href . '">'
                . '<span class="vacancies-list-main">'
                . '<span class="vacancies-list-title">'
                . $this->e($title !== '' ? $title : 'Без названия') . '</span>'
                . '<span class="vacancies-list-company">' . $this->e($company !== '' ? $company : 'Без компании') . '</span>'
                . ($discovered !== ''
                    ? '<span class="vacancies-list-date" title="Скачана / активность">' . $this->e($discovered) . '</span>'
                    : '')
                . ($verdictLabel !== ''
                    ? '<span class="vacancies-list-verdict">' . $verdictLabel . '</span>'
                    : '')
                . '</span>'
                . ($flags !== '' ? '<span class="vacancies-list-flags">' . $flags . '</span>' : '')
                . '</a></li>';
        }
        $list .= '</ul>';

        return '<div class="vacancies-page-layout">'
            . '<div class="vacancies-page-aside">' . $list . '</div>'
            . '<div class="vacancies-page-detail">'
            . $this->detail($selected, $resumeId, $filter, $scope, $watch)
            . '</div>'
            . '</div>';
    }

    /** @param array<string, mixed>|null $item */
    private function detail(
        ?array $item,
        string $resumeId = '',
        string $filter = 'all',
        string $scope = 'recent',
        bool $watch = false,
    ): string {

        if ($item === null) {
            return '<p class="muted">Выберите вакансию слева.</p>';
        }
        $title = (string) ($item['title'] ?? '');
        $company = (string) ($item['company_name'] ?? '');
        $salary = (string) ($item['salary_text'] ?? '');
        $area = (string) ($item['area_text'] ?? '');
        $url = (string) ($item['url'] ?? '');
        $externalId = trim((string) ($item['external_id'] ?? ''));
        $verdict = (string) ($item['verdict'] ?? '');
        $reason = (string) ($item['reason'] ?? '');
        $action = (string) ($item['action'] ?? '');
        $tone = $this->tone($verdict);
        $desc = (string) ($item['description'] ?? '');
        /** @var list<string> $skills */
        $skills = [];
        if (is_array($item['key_skills'] ?? null)) {
            foreach ($item['key_skills'] as $skill) {
                if (is_string($skill) && trim($skill) !== '') {
                    $skills[] = trim($skill);
                }
            }
        }

        $meta = array_values(array_filter([$company, $salary, $area], static fn (string $v): bool => $v !== ''));
        $dates = [];
        $discovered = RuDate::dateTime($item['discovered_at'] ?? $item['first_seen_at'] ?? $item['created_at'] ?? null);
        $assessed = RuDate::dateTime($item['assessed_at'] ?? null);
        $viewedDate = RuDate::dateTime($item['resume_viewed_at'] ?? null);
        if ($discovered !== '') {
            $dates[] = 'Скачана: ' . $discovered;
        }
        if ($assessed !== '') {
            $dates[] = 'Оценена: ' . $assessed;
        }
        if ($viewedDate !== '') {
            $dates[] = 'Смотрели резюме: ' . $viewedDate;
        }
        $datesHtml = $dates !== []
            ? '<p class="vacancy-dates">' . $this->e(implode(' · ', $dates)) . '</p>'
            : '';
        $verdictHtml = '';
        $badge = $this->verdictBadge($verdict);
        if ($badge !== '') {
            $verdictHtml = '<p class="vacancy-verdict'
                . ($tone === 'skip' ? ' is-skip' : ($tone === 'consider' ? ' is-consider' : ''))
                . '">' . $badge
                . ($reason !== '' ? ' <span class="vacancy-verdict-reason">' . $this->e($reason) . '</span>' : '')
                . ($action !== '' ? ' <span class="muted">(' . $this->e($action) . ')</span>' : '')
                . '</p>';
        }

        $skillsHtml = '';
        if ($skills !== []) {
            $skillsHtml = '<div class="vacancy-skills"><div class="vacancy-skills-label">Ключевые навыки</div><ul class="vacancy-skills-list">';
            foreach ($skills as $skill) {
                $skillsHtml .= '<li>' . $this->e($skill) . '</li>';
            }
            $skillsHtml .= '</ul></div>';
        }

        $coreId = trim((string) ($item['core_id'] ?? ''));
        $toneNow = $this->tone($verdict);
        $isDup = !empty($item['is_duplicate']);
        $needsScore = $toneNow === 'pending' && $coreId !== '';
        $canRescore = $toneNow === 'skip' && $coreId !== '' && $resumeId !== '';
        $canManualSkip = !$isDup && $toneNow === 'consider' && $coreId !== '';
        $canManualConsider = !$isDup && $toneNow === 'skip' && $coreId !== '';
        $applied = !empty($item['applied']);
        $appliedResume = trim((string) ($item['applied_resume_title'] ?? ''));
        $chatStatus = trim((string) ($item['chat_status_label'] ?? ''));
        $viewedAt = RuDate::dateTime($item['resume_viewed_at'] ?? null);
        $canApply = array_key_exists('can_apply', $item) ? !empty($item['can_apply']) : !$isDup;
        $ownerResumeId = trim((string) ($item['owner_resume_id'] ?? ''));
        $ownerResumeTitle = trim((string) ($item['owner_resume_title'] ?? ''));
        $ownerReason = trim((string) ($item['owner_reason'] ?? ''));
        $companyRejected = !empty($item['company_rejected']);
        $companyRejectHint = trim((string) ($item['company_rejection_hint'] ?? ''));
        $companyExcluded = !empty($item['company_excluded']);
        $companyExclusionHint = trim((string) ($item['company_exclusion_hint'] ?? ''));
        $applyError = !empty($item['apply_error']);
        $applyErrorMessage = trim((string) ($item['apply_error_message'] ?? ''));
        $applyErrorAt = RuDate::dateTime($item['apply_error_at'] ?? null);
        $applyErrorQuestions = !empty($item['apply_error_questions'])
            || AppErrorStore::isQuestionsRequiredMessage($applyErrorMessage);
        $applyErrorLetter = !$applyErrorQuestions && (
            !empty($item['apply_error_letter'])
            || AppErrorStore::isLetterRequiredMessage($applyErrorMessage)
        );
        $applySkipped = !empty($item['apply_skipped']);
        $applySkippedMessage = trim((string) ($item['apply_skipped_message'] ?? ''));
        $applySkippedAt = RuDate::dateTime($item['apply_skipped_at'] ?? null);
        $actions = $this->detailActions(
            $url,
            $externalId,
            $resumeId,
            $coreId,
            $needsScore,
            $filter,
            $scope,
            $applied,
            $appliedResume,
            $chatStatus,
            $canRescore,
            $viewedAt,
            $isDup,
            $canApply,
            $ownerResumeId,
            $ownerResumeTitle,
            $ownerReason,
            $companyRejected,
            $companyRejectHint,
            $canManualSkip,
            $canManualConsider,
            $companyExcluded,
            $companyExclusionHint,
            $applyError,
            $applyErrorMessage,
            $applyErrorAt,
            $title,
            $company,
            $applyErrorLetter,
            $applyErrorQuestions,
            $applySkipped,
            $applySkippedMessage,
            $applySkippedAt,
        );
        $pollAttrs = '';
        $pollStatus = '';
        if ($coreId !== '' && ($needsScore || $watch)) {
            $pollAttrs = ' class="js-vacancy-verdict-poll"'
                . ' data-vacancy-id="' . $this->e($coreId) . '"'
                . ' data-pending="' . ($needsScore ? '1' : '0') . '"'
                . ' data-watch="' . ($watch || $needsScore ? '1' : '0') . '"';
            $pollStatus = '<p class="vacancy-verdict-status muted js-vacancy-verdict-status"'
                . ($watch || $needsScore ? '' : ' hidden')
                . '>Жду оценку DevAI…</p>';
        }

        return '<div' . ($pollAttrs !== '' ? $pollAttrs : '') . '>'
            . '<h2 class="vacancies-detail-title">' . $this->e($title !== '' ? $title : 'Без названия') . '</h2>'
            . $verdictHtml
            . $pollStatus
            . '<p class="vacancy-meta">' . $this->e(implode(' · ', $meta)) . '</p>'
            . $datesHtml
            . $actions
            . $skillsHtml
            . '<div class="vacancy-description">' . $this->e($this->formatDescription($desc)) . '</div>'
            . '</div>';
    }

    private function detailActions(
        string $url,
        string $externalId,
        string $resumeId,
        string $coreId,
        bool $needsScore,
        string $filter = 'all',
        string $scope = 'recent',
        bool $applied = false,
        string $appliedResumeTitle = '',
        string $chatStatusLabel = '',
        bool $canRescore = false,
        string $resumeViewedAt = '',
        bool $isDuplicate = false,
        bool $canApply = true,
        string $ownerResumeId = '',
        string $ownerResumeTitle = '',
        string $ownerReason = '',
        bool $companyRejected = false,
        string $companyRejectHint = '',
        bool $canManualSkip = false,
        bool $canManualConsider = false,
        bool $companyExcluded = false,
        string $companyExclusionHint = '',
        bool $applyError = false,
        string $applyErrorMessage = '',
        string $applyErrorAt = '',
        string $vacancyTitle = '',
        string $companyName = '',
        bool $applyErrorLetter = false,
        bool $applyErrorQuestions = false,
        bool $applySkipped = false,
        string $applySkippedMessage = '',
        string $applySkippedAt = '',
    ): string {
        $applyHref = '';
        if ($canApply && !$isDuplicate && !$companyExcluded && $externalId !== '' && $resumeId !== '') {
            $label = trim($vacancyTitle);
            if ($label !== '' && trim($companyName) !== '') {
                $label .= ' · ' . trim($companyName);
            }
            $applyHref = '/?busy=apply'
                . '&amp;resume=' . rawurlencode($resumeId)
                . '&amp;vacancy=' . rawurlencode($externalId)
                . ($label !== '' ? '&amp;title=' . rawurlencode($label) : '')
                . ($coreId !== '' ? '&amp;id=' . rawurlencode($coreId) : '')
                . ($filter !== 'all' ? '&amp;filter=' . rawurlencode($filter) : '')
                . ($scope === 'all' ? '&amp;scope=all' : '');
        }

        $parts = [];
        if ($applyError) {
            $kindLabel = $applyErrorQuestions
                ? 'Нужна анкета'
                : ($applyErrorLetter ? 'Нужно сопроводительное' : 'Ошибка отклика');
            $label = $kindLabel
                . ($applyErrorAt !== '' ? ' · ' . $applyErrorAt : '')
                . ($applyErrorMessage !== '' ? ' · ' . $applyErrorMessage : '');
            $icon = $applyErrorQuestions
                ? $this->materialIcon('quiz') . $this->materialIcon('error')
                : ($applyErrorLetter
                    ? $this->materialIcon('description') . $this->materialIcon('error')
                    : $this->materialIcon('error'));
            $parts[] = '<span class="vacancy-apply-error-badge'
                . ($applyErrorQuestions ? ' is-questions' : ($applyErrorLetter ? ' is-letter' : ''))
                . '" title="' . $this->e($label) . '">'
                . $icon . ' ' . $this->e($label) . '</span>';
            $parts[] = '<a class="btn btn-sm btn-ghost" href="/?page=errors">Журнал ошибок</a>';
        } elseif ($applySkipped) {
            $label = 'Скип TG'
                . ($applySkippedAt !== '' ? ' · ' . $applySkippedAt : '')
                . ($applySkippedMessage !== '' ? ' · ' . $applySkippedMessage : '');
            $parts[] = '<span class="vacancy-tg-skip-badge" title="' . $this->e($label) . '">'
                . $this->materialIcon('skip_next') . ' ' . $this->e($label) . '</span>';
            $parts[] = '<a class="btn btn-sm btn-ghost" href="/?page=errors&amp;filter=skipped">Журнал · Скип</a>';
        }
        if ($isDuplicate) {
            $label = 'Дубль · лучше к '
                . ($ownerResumeTitle !== '' ? $ownerResumeTitle : 'другому резюме');
            $parts[] = '<span class="vacancy-dup-badge" title="' . $this->e($ownerReason !== '' ? $ownerReason : $label) . '">'
                . $this->materialIcon('content_copy') . ' ' . $this->e($label) . '</span>';
            if ($ownerResumeId !== '') {
                $ownerHref = '/?page=vacancies&amp;resume=' . rawurlencode($ownerResumeId)
                    . ($coreId !== '' ? '&amp;id=' . rawurlencode($coreId) : (
                        $externalId !== '' ? '&amp;id=' . rawurlencode($externalId) : ''
                    ));
                $parts[] = '<a class="btn btn-sm btn-secondary" href="' . $ownerHref . '">Открыть у резюме-владельца</a>';
            }
        }
        if ($companyExcluded) {
            $label = 'Компания в исключениях'
                . ($companyExclusionHint !== '' ? ' · ' . $companyExclusionHint : '');
            $parts[] = '<span class="vacancy-company-exclude-badge" title="' . $this->e($label) . '">'
                . $this->materialIcon('block') . ' ' . $this->e($label) . '</span>';
        }
        if ($companyRejected) {
            $label = 'Компания уже отказывала'
                . ($companyRejectHint !== '' ? ' · ' . $companyRejectHint : '');
            $parts[] = '<span class="vacancy-company-reject-badge" title="' . $this->e($label) . '">'
                . $this->materialIcon('cancel') . ' ' . $this->e($label) . '</span>';
        }
        if (!$isDuplicate && $needsScore && $resumeId !== '' && $coreId !== '') {
            $parts[] = '<button type="button" class="btn btn-sm btn-secondary js-vacancy-score-one"'
                . ' data-resume-id="' . $this->e($resumeId) . '"'
                . ' data-vacancy-id="' . $this->e($coreId) . '"'
                . ' data-force="0">Оценить</button>';
        }
        if (!$isDuplicate && $canRescore) {
            $parts[] = '<button type="button" class="btn btn-sm btn-secondary js-vacancy-score-one"'
                . ' data-resume-id="' . $this->e($resumeId) . '"'
                . ' data-vacancy-id="' . $this->e($coreId) . '"'
                . ' data-force="1"'
                . ' title="Новая оценка DevAI">Переоценить</button>';
        }
        if ($resumeViewedAt !== '') {
            $parts[] = '<span class="vacancy-viewed-badge" title="Работодатель смотрел резюме">'
                . $this->materialIcon('visibility') . ' Смотрели · ' . $this->e($resumeViewedAt) . '</span>';
        }
        if ($applied) {
            $badge = 'Отклик'
                . ($appliedResumeTitle !== '' ? ' · ' . $appliedResumeTitle : '')
                . ($chatStatusLabel !== '' ? ' · ' . $chatStatusLabel : '');
            $parts[] = '<span class="vacancy-applied-badge" title="Есть чат/отклик на HH">'
                . $this->materialIcon('mark_email_read') . ' ' . $this->e($badge) . '</span>';
        } elseif ($applyHref !== '') {
            $parts[] = '<a class="btn btn-sm btn-primary" href="' . $applyHref . '">Откликнуться</a>';
        }
        if ($canManualSkip && $coreId !== '') {
            $parts[] = '<button type="button" class="btn btn-sm btn-secondary js-vacancy-set-verdict"'
                . ' data-vacancy-id="' . $this->e($coreId) . '"'
                . ' data-verdict="skip"'
                . ' title="Ручной skip">Skip</button>';
        }
        if ($canManualConsider && $coreId !== '') {
            $parts[] = '<button type="button" class="btn btn-sm btn-primary js-vacancy-set-verdict"'
                . ' data-vacancy-id="' . $this->e($coreId) . '"'
                . ' data-verdict="maybe"'
                . ' title="Вернуть в Смотрим (ручной maybe)">В Смотрим</button>';
        }
        if ($url !== '') {
            $parts[] = '<a class="btn btn-sm btn-secondary" href="' . $this->e($url)
                . '" target="_blank" rel="noopener noreferrer">Открыть на hh.ru</a>';
        }
        if ($parts === []) {
            return '';
        }

        return '<p class="vacancy-detail-actions">' . implode(' ', $parts) . '</p>';
    }

    private function flashBlock(string $flash, bool $ok): string
    {
        if ($flash === '') {
            return '';
        }

        $cls = $ok ? 'ok' : 'err';

        return '<p class="flash ' . $cls . ' js-flash" role="status">'
            . nl2br($this->e($flash), false) . '</p>';
    }

    private function tone(string $verdict): string
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

    /** HTML бейджа вердикта (уже escaped). */
    private function verdictLabel(string $verdict): string
    {
        return $this->verdictBadge($verdict);
    }

    /** HTML бейджа: apply / maybe / skip — без слова «Рассматриваем». */
    private function verdictBadge(string $verdict): string
    {
        $v = strtolower(trim($verdict));
        if ($v === 'skip' || $v === 'reject' || $v === 'no') {
            return '<span class="vacancy-verdict-badge is-skip">skip</span>';
        }
        if ($v === 'maybe' || $v === 'consider') {
            return '<span class="vacancy-verdict-badge is-maybe">maybe</span>';
        }
        if ($v === 'apply' || $v === 'ok' || $v === 'yes') {
            return '<span class="vacancy-verdict-badge is-apply">apply</span>';
        }
        if ($v !== '') {
            return '<span class="vacancy-verdict-badge is-pending">' . $this->e($v) . '</span>';
        }

        return '<span class="vacancy-verdict-badge is-pending">без оценки</span>';
    }

    private function formatDescription(string $raw): string
    {
        $text = str_replace("\u{00a0}", ' ', $raw);
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = trim($text);
        if (!str_contains($text, "\n")) {
            $text = preg_replace(
                '/\s+((?:Ваши задачи|Мы жд[её]м(?:\s+от\s+вас)?|Обязанности|Требования|Будет преимуществом|Условия|Ключевые навыки)\s*:)/ui',
                "\n\n$1",
                $text,
            ) ?? $text;
        }

        return preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** Google Material Symbols Outlined — https://fonts.google.com/icons */
    private function materialIcon(string $name, string $title = ''): string
    {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name)) ?? '';
        if ($name === '') {
            return '';
        }
        $attrs = '';
        if ($title !== '') {
            $attrs = ' title="' . $this->e($title) . '" aria-label="' . $this->e($title) . '"';
        } else {
            $attrs = ' aria-hidden="true"';
        }

        return '<span class="material-symbols-outlined"' . $attrs . '>' . $name . '</span>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
