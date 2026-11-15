<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * View: компании, куда нет смысла откликаться.
 */
final class ExcludedCompaniesPageView implements View
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
        $total = (int) ($data['total'] ?? 0);
        /** @var list<array<string, mixed>> $items */
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $viteTags = $this->vite !== null ? $this->vite->tags('companies') : '';

        return '<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Исключения — job_search_2</title>
  <style>html,body{margin:0;background:#0f1115;color:#e8edf5}</style>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,500,0,0&display=swap">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/style.css">
  ' . $viteTags . '
</head>
<body>
  ' . $this->topbar() . '
  <main class="wrap">
    <section class="panel">
      <div class="panel-head">
        ' . CountBadge::title('Компании-исключения', $total) . '
        <a class="btn btn-sm btn-primary" href="/?page=exclusions&amp;seed=sber">Подтянуть Сберы</a>
        ' . ($total > 0
            ? '<a class="btn btn-sm btn-secondary" href="/?page=exclusions&amp;clear=1" onclick="return confirm(\'Очистить список исключений?\');">Очистить</a>'
            : '') . '
      </div>
      <p class="muted">Сюда — работодатели, на кого не откликаемся (Сбер и т.п.). Они не попадают в «Смотрим».</p>
      ' . ($flash !== ''
            ? '<p class="flash ' . ($flashOk ? 'ok' : 'err') . ' js-flash">' . nl2br($this->e($flash), false) . '</p>'
            : '') . '
      ' . ($error !== '' ? '<p class="err">' . $this->e($error) . '</p>' : '') . '
      ' . $this->table($items) . '
    </section>
  </main>
  <footer class="footer"><div class="footer-inner">FileCache company_exclusions</div></footer>
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
      <a class="top-nav-link" href="/?page=companies">Компании</a>
      <a class="top-nav-link is-active" href="/?page=exclusions">Исключения</a>
      <a class="top-nav-link" href="/?page=rejections">Отказы</a>
    </nav>
    <div class="topbar-right">
      <a class="top-nav-link" href="/?page=errors">Ошибки</a>
      <a class="top-nav-link" href="/?page=chats">Чат</a>
    </div>
  </div>
</header>';
    }

    /** @param list<array<string, mixed>> $items */
    private function table(array $items): string
    {
        if ($items === []) {
            return '<p class="muted">Пока пусто. Нажми «Подтянуть Сберы» — подтянем из справочника компаний.</p>';
        }

        $rows = '';
        foreach ($items as $item) {
            $name = $this->str($item['company_name'] ?? null);
            $ext = $this->str($item['company_external_id'] ?? null);
            $reason = $this->str($item['reason'] ?? null);
            $source = $this->str($item['source'] ?? null);
            $added = RuDate::dateTime($item['added_at'] ?? null);
            $meta = [];
            if ($ext !== '') {
                $meta[] = 'id ' . $ext;
            }
            if ($source !== '') {
                $meta[] = $source;
            }
            if ($added !== '') {
                $meta[] = $added;
            }
            $rows .= '<tr>'
                . '<td><div class="companies-name">' . $this->e($name !== '' ? $name : '—') . '</div>'
                . ($meta !== [] ? '<div class="muted">' . $this->e(implode(' · ', $meta)) . '</div>' : '')
                . '</td>'
                . '<td class="muted">' . $this->e($reason !== '' ? $reason : 'исключение') . '</td>'
                . '</tr>';
        }

        return '<div class="table-wrap"><table class="companies-table">'
            . '<thead><tr><th>Компания</th><th>Почему</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
