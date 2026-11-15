<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * View: компании из скачанных вакансий + отказы из чатов HH.
 */
final class CompaniesPageView implements View
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
        $filter = $this->str($data['filter'] ?? 'all') ?: 'all';
        $query = $this->str($data['query'] ?? null);
        $total = (int) ($data['total'] ?? 0);
        $rejectedCount = (int) ($data['rejected_count'] ?? 0);
        $shown = (int) ($data['shown'] ?? 0);
        /** @var list<array<string, mixed>> $items */
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $viteTags = $this->vite !== null ? $this->vite->tags('companies') : '';

        return '<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Компании — job_search_2</title>
  <style>html,body{margin:0;background:#0f1115;color:#e8edf5}</style>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,500,0,0&display=swap">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/style.css">
  ' . $viteTags . '
</head>
<body>
  ' . $this->topbar() . '
  <main class="wrap wrap-wide">
    <section class="panel">
      <div class="panel-head">
        ' . CountBadge::title(
            'Компании',
            $shown,
            ($query !== '' || $filter === 'rejected') ? '/ ' . $total : '',
        ) . '
      </div>
      <p class="muted companies-page-sub">Все работодатели из скачанных suitable-вакансий.
        Отказы — из чатов HH (статус «Отказ»). Сначала компании с отказом.</p>
      ' . $this->filters($filter, $total, $rejectedCount, $query) . '
      ' . $this->searchForm($filter, $query) . '
      ' . ($error !== '' ? '<p class="err">' . $this->e($error) . '</p>' : '') . '
      ' . $this->table($items, $query !== '' || $filter === 'rejected') . '
    </section>
  </main>
  <footer class="footer"><div class="footer-inner">SearchRun serp + hh_chats DISCARD</div></footer>
</body>
</html>
';
    }

    private function topbar(): string
    {
        return '<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/">job_search_2</a>
    <nav class="top-nav">
      <a class="top-nav-link" href="/">Главная</a>
      <a class="top-nav-link" href="http://127.0.0.1:3000/d/job-search-2/poisk-raboty?orgId=1&amp;refresh=30s" target="_blank" rel="noopener">Статистика</a>
      <a class="top-nav-link" href="/?page=applications">Отклики</a>
      <a class="top-nav-link" href="/?page=vacancies">Вакансии</a>
      <a class="top-nav-link is-active" href="/?page=companies">Компании</a>
      <a class="top-nav-link" href="/?page=exclusions">Исключения</a>
      <a class="top-nav-link" href="/?page=rejections">Отказы</a>
    </nav>
    <div class="topbar-right">
      <a class="top-nav-link" href="/?page=errors">Ошибки</a>
      <a class="top-nav-link" href="/?page=chats">Чат</a>
    </div>
  </div>
</header>';
    }

    private function filters(string $filter, int $total, int $rejectedCount, string $query): string
    {
        $q = $query !== '' ? '&amp;q=' . rawurlencode($query) : '';
        $tabs = [
            'all' => [$total, 'Все'],
            'rejected' => [$rejectedCount, 'Отказы'],
        ];
        $html = '<div class="filter-tabs">';
        foreach ($tabs as $key => [$n, $label]) {
            $cls = $key === $filter ? 'filter-tab is-active' : 'filter-tab';
            $href = '/?page=companies'
                . ($key === 'all' ? '' : '&amp;filter=' . rawurlencode($key))
                . $q;
            $html .= '<a class="' . $cls . '" href="' . $href . '">'
                . CountBadge::tabLabel($label, $n) . '</a>';
        }

        return $html . '</div>';
    }

    private function searchForm(string $filter, string $query): string
    {
        return '<form class="chats-search" method="get" action="/">'
            . '<input type="hidden" name="page" value="companies">'
            . ($filter === 'rejected' ? '<input type="hidden" name="filter" value="rejected">' : '')
            . '<label class="chats-search-label" for="companies-q">Поиск</label>'
            . '<input id="companies-q" class="chats-search-input" type="search" name="q" value="'
            . $this->e($query) . '" placeholder="Название компании или вакансии" autocomplete="off">'
            . '<button class="btn btn-sm btn-secondary" type="submit">Найти</button>'
            . ($query !== ''
                ? '<a class="btn btn-sm btn-ghost" href="/?page=companies'
                    . ($filter === 'rejected' ? '&amp;filter=rejected' : '')
                    . '">Сброс</a>'
                : '')
            . '</form>';
    }

    /** @param list<array<string, mixed>> $items */
    private function table(array $items, bool $filtered): string
    {
        if ($items === []) {
            return $filtered
                ? '<p class="muted">Ничего не найдено.</p>'
                : '<p class="muted">Пока нет компаний. Скачай вакансии на главной.</p>';
        }

        $rows = '';
        foreach ($items as $item) {
            $name = $this->str($item['company_name'] ?? null);
            $ext = $this->str($item['company_external_id'] ?? null);
            $vacCount = (int) ($item['vacancy_count'] ?? 0);
            $rejected = !empty($item['rejected']);
            /** @var list<array<string, mixed>> $rejections */
            $rejections = is_array($item['rejections'] ?? null) ? $item['rejections'] : [];
            $cls = $rejected ? ' class="is-discard"' : '';
            $employerHref = $ext !== '' && ctype_digit($ext)
                ? 'https://hh.ru/employer/' . rawurlencode($ext)
                : '';
            $nameHtml = $employerHref !== ''
                ? '<a href="' . $this->e($employerHref) . '" target="_blank" rel="noopener noreferrer">'
                    . $this->e($name !== '' ? $name : 'Без названия') . '</a>'
                : $this->e($name !== '' ? $name : 'Без названия');

            $rejHtml = '';
            if ($rejections !== []) {
                $rejHtml = '<ul class="companies-rejections">';
                foreach ($rejections as $rej) {
                    $vid = $this->str($rej['vacancy_external_id'] ?? null);
                    $vtitle = $this->str($rej['vacancy_title'] ?? null);
                    $at = RuDate::dateTime($rej['rejected_at'] ?? null);
                    $resumeTitle = $this->str($rej['resume_title'] ?? null);
                    $vHref = $vid !== '' ? 'https://hh.ru/vacancy/' . rawurlencode($vid) : '';
                    $titleHtml = $vHref !== ''
                        ? '<a href="' . $this->e($vHref) . '" target="_blank" rel="noopener noreferrer">'
                            . $this->e($vtitle !== '' ? $vtitle : $vid) . '</a>'
                        : $this->e($vtitle !== '' ? $vtitle : 'Вакансия');
                    $metaBits = [];
                    if ($resumeTitle !== '') {
                        $metaBits[] = 'резюме: ' . $resumeTitle;
                    }
                    if ($at !== '') {
                        $metaBits[] = $at;
                    }
                    $rejHtml .= '<li>'
                        . '<span class="companies-rej-title">' . $titleHtml . '</span>'
                        . ($metaBits !== []
                            ? '<span class="companies-rej-date muted">'
                                . $this->e(implode(' · ', $metaBits))
                                . '</span>'
                            : '')
                        . '</li>';
                }
                $rejHtml .= '</ul>';
            } else {
                $rejHtml = '<span class="muted">—</span>';
            }

            $rows .= '<tr' . $cls . '>'
                . '<td class="companies-name">' . $nameHtml . '</td>'
                . '<td class="num companies-vac-count">' . $this->e((string) $vacCount) . '</td>'
                . '<td class="companies-rej-cell">' . $rejHtml . '</td>'
                . '</tr>';
        }

        return '<div class="table-wrap"><table class="apps-table companies-table">'
            . '<thead><tr>'
            . '<th>Компания</th><th class="num">Вакансий</th><th>Вакансии с отказом</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function str(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
