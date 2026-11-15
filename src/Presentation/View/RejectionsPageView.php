<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * View: журнал отказов из hh_chats (плоский список для сверки).
 */
final class RejectionsPageView implements View
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
        $totalChats = (int) ($data['total_chats'] ?? 0);
        $shown = (int) ($data['shown'] ?? count(is_array($data['items'] ?? null) ? $data['items'] : []));
        $query = $this->str($data['query'] ?? null);
        $vacancyId = $this->str($data['vacancy_id'] ?? null);
        $syncedAt = RuDate::dateTime($data['synced_at'] ?? null);
        /** @var list<array<string, mixed>> $items */
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $viteTags = $this->vite !== null ? $this->vite->tags('chats') : '';
        $titleBadge = $query !== ''
            ? CountBadge::title('Отказы', $shown, '/ ' . $total)
            : CountBadge::title('Отказы', $total, 'отказов');

        return '<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Отказы — job_search_2</title>
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
        ' . $titleBadge . '
        <a class="btn btn-sm btn-primary" href="/?busy=chats">Скачать с HH</a>
      </div>
      <p class="muted chats-page-sub">Журнал из чатов HH (DISCARD / «Отказ»). Всего чатов в БД: '
            . $this->e((string) $totalChats) . '.'
            . ($syncedAt !== '' ? ' Синк: ' . $this->e($syncedAt) . '.' : '')
            . ' Покинуть отказы — на странице «Чат». Не путать с «Исключениями».</p>
      ' . $this->searchForm($query, $vacancyId) . '
      ' . ($flash !== ''
            ? '<p class="flash ' . ($flashOk ? 'ok' : 'err') . ' js-flash">' . nl2br($this->e($flash), false) . '</p>'
            : '') . '
      ' . ($error !== '' ? '<p class="err">' . $this->e($error) . '</p>' : '') . '
      ' . $this->list($items, $query !== '') . '
    </section>
  </main>
  <footer class="footer"><div class="footer-inner">hh_chats DISCARD → аудит отказов</div></footer>
</body>
</html>
';
    }

    private function searchForm(string $query, string $vacancyId): string
    {
        $hint = $vacancyId !== ''
            ? 'Ищу vacancy_id <code>' . $this->e($vacancyId) . '</code>.'
            : 'ID вакансии, ссылка hh.ru/vacancy/… или текст названия/компании.';

        return '<form class="chats-search" method="get" action="/">'
            . '<input type="hidden" name="page" value="rejections">'
            . '<label class="chats-search-label" for="rejections-q">Поиск</label>'
            . '<input id="rejections-q" class="chats-search-input" type="search" name="q" value="'
            . $this->e($query) . '" placeholder="139203618 или компания" autocomplete="off">'
            . '<button class="btn btn-sm btn-secondary" type="submit">Найти</button>'
            . ($query !== ''
                ? '<a class="btn btn-sm btn-ghost" href="/?page=rejections">Сброс</a>'
                : '')
            . '<span class="chats-search-hint muted">' . $hint . '</span>'
            . '</form>';
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
      <a class="top-nav-link" href="/?page=exclusions">Исключения</a>
      <a class="top-nav-link is-active" href="/?page=rejections">Отказы</a>
    </nav>
    <div class="topbar-right">
      <a class="top-nav-link" href="/?page=errors">Ошибки</a>
      <a class="top-nav-link" href="/?page=chats">Чат</a>
    </div>
  </div>
</header>';
    }

    /** @param list<array<string, mixed>> $items */
    private function list(array $items, bool $filtered = false): string
    {
        if ($items === []) {
            return $filtered
                ? '<p class="muted">Ничего не найдено по запросу.</p>'
                : '<p class="muted">Отказов в БД нет. Нажми «Скачать с HH».</p>';
        }

        $rows = '';
        foreach ($items as $item) {
            $title = $this->str($item['vacancy_title'] ?? null);
            $company = $this->str($item['company_name'] ?? null);
            $status = $this->str($item['status_label'] ?? null);
            $url = $this->str($item['url'] ?? null);
            $vacancyId = $this->str($item['vacancy_external_id'] ?? null);
            $activity = RuDate::dateTime($item['last_message_at'] ?? null);
            if ($activity === '') {
                $activity = RuDate::dateTime($item['last_activity_at'] ?? null);
            }
            $preview = $this->str($item['last_message_preview'] ?? null);
            $resumeTitle = $this->str($item['resume_title'] ?? null);

            $titleHtml = $title !== '' ? $this->e($title) : '<span class="muted">Без названия</span>';
            if ($vacancyId !== '') {
                $titleHtml = '<a href="https://hh.ru/vacancy/' . $this->e(rawurlencode($vacancyId))
                    . '" target="_blank" rel="noopener noreferrer">' . $titleHtml . '</a>';
            }

            $meta = [];
            if ($company !== '') {
                $meta[] = $this->e($company);
            }
            if ($resumeTitle !== '') {
                $meta[] = 'резюме: ' . $this->e($resumeTitle);
            }
            if ($activity !== '') {
                $meta[] = $this->e($activity);
            }
            if ($vacancyId !== '') {
                $meta[] = 'id ' . $this->e($vacancyId);
            }

            $open = $url !== ''
                ? '<a class="btn btn-sm btn-secondary" href="' . $this->e($url)
                    . '" target="_blank" rel="noopener noreferrer">Открыть на HH</a>'
                : '';
            $closed = !empty($item['vacancy_closed']) || mb_strtolower($status, 'UTF-8') === 'закрыта';
            $badge = $closed
                ? '<span class="chat-status-badge is-closed">'
                    . '<span class="material-symbols-outlined" aria-hidden="true">domain_disabled</span> Закрыта</span>'
                : '<span class="chat-status-badge is-discard">'
                    . '<span class="material-symbols-outlined" aria-hidden="true">cancel</span> '
                    . $this->e($status !== '' ? $status : 'Отказ')
                    . '</span>';

            $rows .= '<article class="chat-card ' . ($closed ? 'is-closed' : 'is-discard') . '">'
                . '<div class="chat-card-main">'
                . '<div class="chat-card-title">' . $titleHtml . '</div>'
                . ($meta !== [] ? '<div class="chat-card-meta">' . implode(' · ', $meta) . '</div>' : '')
                . ($preview !== '' ? '<div class="chat-card-preview muted">' . $this->e($preview) . '</div>' : '')
                . '</div>'
                . '<div class="chat-card-side">'
                . ($open !== '' ? '<div class="chat-card-actions">' . $open . '</div>' : '')
                . $badge
                . '</div>'
                . '</article>';
        }

        return '<div class="chats-list">' . $rows . '</div>';
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
