<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * View: отдельная страница откликов (только представление).
 */
final class ApplicationsPageView implements View
{
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
        $bucket = $this->str($data['bucket'] ?? 'all') ?: 'all';
        /** @var array{waiting?: int, in_process?: int, completed?: int, unknown?: int} $counts */
        $counts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
        $total = (int) ($data['total'] ?? 0);
        /** @var list<array<string, mixed>> $items */
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        return '<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Отклики — job_search_2</title>
  <style>html,body{margin:0;background:#0f1115;color:#e8edf5}</style>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/style.css">
</head>
<body>
  ' . $this->topbar() . '
  <main class="wrap">
    <section class="panel">
      <div class="panel-head">
        ' . CountBadge::title('Отклики', $total, 'в Core') . '
      </div>
      ' . $this->filters($bucket, $counts) . '
      ' . ($error !== '' ? '<p class="err">' . $this->e($error) . '</p>' : '') . '
      ' . $this->table($items) . '
    </section>
  </main>
  <footer class="footer"><div class="footer-inner">Источник: Core applications + post-application-tracking</div></footer>
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
      <a class="top-nav-link is-active" href="/?page=applications">Отклики</a>
      <a class="top-nav-link" href="/?page=vacancies">Вакансии</a>
      <a class="top-nav-link" href="/?page=companies">Компании</a>
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

    /**
     * @param array{waiting?: int, in_process?: int, completed?: int, unknown?: int} $counts
     */
    private function filters(string $bucket, array $counts): string
    {
        $tabs = [
            'all' => [null, 'Все'],
            'waiting' => [(int) ($counts['waiting'] ?? 0), 'Ждём ответ'],
            'in_process' => [(int) ($counts['in_process'] ?? 0), 'В работе'],
            'completed' => [(int) ($counts['completed'] ?? 0), 'Завершено'],
        ];
        if ((int) ($counts['unknown'] ?? 0) > 0) {
            $tabs['unknown'] = [(int) $counts['unknown'], 'Без статуса'];
        }

        $html = '<div class="filter-tabs">';
        foreach ($tabs as $key => [$n, $label]) {
            $cls = $key === $bucket ? 'filter-tab is-active' : 'filter-tab';
            $href = '/?page=applications' . ($key === 'all' ? '' : '&amp;bucket=' . rawurlencode($key));
            $inner = $n === null ? $this->e($label) : CountBadge::tabLabel($label, $n);
            $html .= '<a class="' . $cls . '" href="' . $href . '">' . $inner . '</a>';
        }

        return $html . '</div>';
    }

    /** @param list<array<string, mixed>> $items */
    private function table(array $items): string
    {
        if ($items === []) {
            return '<p class="muted">Нет откликов в этой выборке.</p>';
        }

        $rows = '';
        foreach ($items as $item) {
            $title = $this->str($item['title'] ?? null);
            $company = $this->str($item['company'] ?? null);
            $url = $this->str($item['url'] ?? null);
            $resume = $this->str($item['resume_label'] ?? null);
            $bucketLabel = $this->str($item['bucket_label'] ?? null);
            $applied = RuDate::dateTime($item['applied_at'] ?? null);
            if ($applied === '') {
                $applied = '—';
            }
            $response = $this->str($item['response_type'] ?? null);

            $titleHtml = $url !== ''
                ? '<a href="' . $this->e($url) . '" target="_blank" rel="noopener noreferrer">' . $this->e($title) . '</a>'
                : $this->e($title);

            $meta = $company !== '' ? $this->e($company) : '<span class="muted">—</span>';
            if ($response !== '') {
                $meta .= ' · <span class="muted">' . $this->e($response) . '</span>';
            }

            $rows .= '<tr>
  <td class="num">' . $this->e($applied) . '</td>
  <td><div class="app-title">' . $titleHtml . '</div><div class="muted app-company">' . $meta . '</div></td>
  <td>' . $this->e($resume !== '' ? $resume : '—') . '</td>
  <td><span class="bucket-pill bucket-' . $this->e($this->str($item['bucket'] ?? 'unknown')) . '">'
                . $this->e($bucketLabel !== '' ? $bucketLabel : '—') . '</span></td>
</tr>';
        }

        return '<div class="table-scroll">
  <table class="resume-table apps-table">
    <thead>
      <tr>
        <th class="num">Дата</th>
        <th>Вакансия</th>
        <th>Резюме</th>
        <th>Статус</th>
      </tr>
    </thead>
    <tbody>' . $rows . '</tbody>
  </table>
</div>';
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
