<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * View: очередь bot-wall («не робот») и кнопка прямой перекачки detail.
 */
final class BotWallRehydratePageView implements View
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
        $count = (int) ($data['count'] ?? 0);
        /** @var list<array<string, mixed>> $items */
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $flash = $this->str($data['flash'] ?? null);
        $flashOk = !empty($data['flash_ok']);
        $error = $this->str($data['error'] ?? null);
        $viteTags = $this->vite !== null ? $this->vite->tags('ui') : '';

        $flashHtml = $flash !== ''
            ? '<p class="' . ($flashOk ? 'ok' : 'err') . '">' . $this->e($flash) . '</p>'
            : '';
        if ($error !== '') {
            $flashHtml .= '<p class="err">' . $this->e($error) . '</p>';
        }

        $cta = $count > 0
            ? '<p><a class="btn" href="/?busy=rehydrate_bot_wall&amp;return=rehydrate">'
                . 'Перекачать ' . $this->e((string) $count) . '</a></p>'
                . '<p class="muted">Playwright откроет каждую по id → нормальный текст в Core → DevAI. '
                . 'Капча в TG не шлётся; при 5 bot-wall подряд — стоп, очередь сохраняется.</p>'
            : '<p class="muted">Очередь пуста — битых «не робот» на перекачку нет.</p>';

        $body = '';
        if ($items === []) {
            $table = '<p class="muted">Нечего показывать.</p>';
        } else {
            foreach ($items as $row) {
                $still = !empty($row['still_bot']);
                $badge = $still
                    ? '<span class="is-skip">ещё капча в Core</span>'
                    : '<span class="is-consider">текст уже есть?</span>';
                $body .= '<tr>'
                    . '<td><a href="' . $this->e($this->str($row['url'] ?? null)) . '" target="_blank" rel="noopener">'
                    . $this->e($this->str($row['external_id'] ?? null)) . '</a></td>'
                    . '<td>' . $this->e($this->str($row['title'] ?? null)) . '<div class="muted">'
                    . $badge . '</div></td>'
                    . '<td>' . $this->e($this->str($row['company'] ?? null)) . '</td>'
                    . '<td>' . $this->e($this->str($row['resume_title'] ?? null)) . '</td>'
                    . '</tr>';
            }
            $shown = count($items);
            $table = '<p class="muted">Показаны первые ' . $this->e((string) $shown)
                . ' из ' . $this->e((string) $count) . '.</p>'
                . '<div class="table-wrap"><table class="data-table">'
                . '<thead><tr><th>HH id</th><th>Title в Core</th><th>Компания</th><th>Owner</th></tr></thead>'
                . '<tbody>' . $body . '</tbody></table></div>';
        }

        return '<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Перекачка bot-wall — job_search_2</title>
  <style>html,body{margin:0;background:#0f1115;color:#e8edf5}</style>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/style.css">
  ' . $viteTags . '
</head>
<body>
  ' . $this->topbar($count) . '
  <main class="wrap wrap-wide">
    <section class="panel">
      <div class="panel-head">'
            . CountBadge::title('Перекачка «не робот»', $count, 'в очереди') . '
      </div>
      <p class="muted">Вакансии, у которых при скачивании HH отдал капчу вместо текста.
        Они уже в базе как мусор / skip — эта страница качает <strong>нормальный detail по id</strong>,
        без ожидания, пока они снова попадут в SERP.</p>
      ' . $flashHtml . '
      ' . $cta . '
      ' . $table . '
    </section>
  </main>
  <footer class="footer"><div class="footer-inner">bot_wall_rehydrate · FetchSuitable / RehydrateBotWallVacancies</div></footer>
</body>
</html>';
    }

    private function topbar(int $count): string
    {
        $label = 'Перекачка' . ($count > 0 ? CountBadge::html($count) : '');

        return '<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/">job_search_2</a>
    <nav class="top-nav">
      <a class="top-nav-link" href="/">Главная</a>
      <a class="top-nav-link" href="http://127.0.0.1:3000/d/job-search-2/poisk-raboty?orgId=1&amp;refresh=30s" target="_blank" rel="noopener">Статистика</a>
      <a class="top-nav-link" href="/?page=applications">Отклики</a>
      <a class="top-nav-link" href="/?page=vacancies">Вакансии</a>
      <a class="top-nav-link" href="/?page=companies">Компании</a>
      <a class="top-nav-link" href="/?page=exclusions">Исключения</a>
      <a class="top-nav-link" href="/?page=rejections">Отказы</a>
    </nav>
    <div class="topbar-right">
      <a class="top-nav-link is-active" href="/?page=rehydrate">' . $label . '</a>
      <a class="top-nav-link" href="/?page=errors">Ошибки</a>
      <a class="top-nav-link" href="/?page=chats">Чат</a>
    </div>
  </div>
</header>';
    }

    private function str(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value)
            ? trim((string) $value)
            : '';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
