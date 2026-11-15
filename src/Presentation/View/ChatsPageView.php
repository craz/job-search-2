<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * View: страница чатов HH (шаг 1 — карточки списка).
 */
final class ChatsPageView implements View
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
        $shown = (int) ($data['shown'] ?? count(is_array($data['items'] ?? null) ? $data['items'] : []));
        $rejectionCount = (int) ($data['rejection_count'] ?? 0);
        $oldCount = (int) ($data['old_count'] ?? 0);
        $archivedCount = (int) ($data['archived_count'] ?? 0);
        $liveCount = (int) ($data['live_count'] ?? $total);
        $filter = $this->str($data['filter'] ?? 'all') ?: 'all';
        $query = $this->str($data['query'] ?? null);
        $vacancyId = $this->str($data['vacancy_id'] ?? null);
        $syncedAt = RuDate::dateTime($data['synced_at'] ?? null);
        /** @var array<string, mixed> $schedule */
        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        /** @var list<array<string, mixed>> $items */
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $viteTags = $this->vite !== null ? $this->vite->tags('chats') : '';
        if ($query !== '' || $filter !== 'all') {
            $titleBadge = CountBadge::title('Чат', $shown, '/ ' . $total);
        } else {
            $note = 'HH ' . $liveCount . ($archivedCount > 0 ? ' + архив ' . $archivedCount : '');
            $titleBadge = CountBadge::title('Чат', $total, $note);
        }
        $leaveBtn = $rejectionCount > 0
            ? '<a class="btn btn-sm btn-secondary" href="/?busy=leave_rejections"'
                . ' onclick="return confirm(\'Покинуть на hh.ru все ' . $this->e((string) $rejectionCount)
                . ' чатов с отказом? Карточки останутся в приложении (архив отказов).\');">'
                . 'Покинуть все отказы ' . CountBadge::html($rejectionCount) . '</a>'
            : '';
        $leaveOldBtn = $oldCount > 0
            ? '<a class="btn btn-sm btn-secondary" href="/?busy=leave_old"'
                . ' onclick="return confirm(\'Покинуть на hh.ru все ' . $this->e((string) $oldCount)
                . ' чатов старше 1 месяца? В приложении останутся в архиве.\');">'
                . 'Покинуть старше месяца ' . CountBadge::html($oldCount) . '</a>'
            : '';

        return '<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Чат — job_search_2</title>
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
        ' . $leaveBtn . '
        ' . $leaveOldBtn . '
      </div>
      <p class="muted chats-page-sub">Шаг 1: карточки списка (вакансия, компания, статус, время). Сообщения — позже.
        ' . ($syncedAt !== '' ? ' Синк: ' . $this->e($syncedAt) . '.' : '') . '
        Старые после leave — во вкладке «Архив».
      </p>
      ' . $this->scheduleNote($schedule) . '
      ' . $this->filters($filter, $total, $liveCount, $archivedCount, $query) . '
      ' . $this->searchForm($query, $vacancyId, $filter) . '
      ' . ($flash !== '' ? '<p class="flash ' . ($flashOk ? 'ok' : 'err') . '">' . $this->e($flash) . '</p>' : '') . '
      ' . ($error !== '' ? '<p class="err">' . $this->e($error) . '</p>' : '') . '
      ' . $this->list($items, $query !== '') . '
    </section>
  </main>
  <footer class="footer"><div class="footer-inner">HH chatik → Core Postgres hh_chats</div></footer>
</body>
</html>
';
    }

    /** @param array<string, mixed> $schedule */
    private function scheduleNote(array $schedule): string
    {
        $note = ScheduleNote::chats($schedule);
        // Всегда рендерим слот: JS подтянет актуальный статус при загрузке и в live.
        return '<p class="muted chats-schedule-note js-schedule-status" data-schedule-task="chats">'
            . $this->e($note !== '' ? $note : 'Авто: загружаю статус…')
            . '</p>';
    }

    private function filters(
        string $filter,
        int $total,
        int $liveCount,
        int $archivedCount,
        string $query,
    ): string {
        $tabs = [
            'all' => [$total, 'Все'],
            'live' => [$liveCount, 'На HH'],
            'archive' => [$archivedCount, 'Архив'],
        ];
        $html = '<div class="filter-tabs">';
        foreach ($tabs as $key => [$n, $label]) {
            $cls = $key === $filter ? 'filter-tab is-active' : 'filter-tab';
            $href = '/?page=chats'
                . ($key === 'all' ? '' : '&amp;filter=' . rawurlencode($key))
                . ($query !== '' ? '&amp;q=' . rawurlencode($query) : '');
            $html .= '<a class="' . $cls . '" href="' . $href . '">'
                . CountBadge::tabLabel($label, $n) . '</a>';
        }

        return $html . '</div>';
    }

    private function searchForm(string $query, string $vacancyId, string $filter = 'all'): string
    {
        $hint = $vacancyId !== ''
            ? 'Ищу vacancy_id <code>' . $this->e($vacancyId) . '</code>.'
            : 'ID вакансии, ссылка hh.ru/vacancy/… или текст названия/компании.';
        $reset = '/?page=chats' . ($filter !== 'all' ? '&amp;filter=' . rawurlencode($filter) : '');

        return '<form class="chats-search" method="get" action="/">'
            . '<input type="hidden" name="page" value="chats">'
            . ($filter !== 'all' ? '<input type="hidden" name="filter" value="' . $this->e($filter) . '">' : '')
            . '<label class="chats-search-label" for="chats-q">Поиск</label>'
            . '<input id="chats-q" class="chats-search-input" type="search" name="q" value="'
            . $this->e($query) . '" placeholder="139203618 или https://hh.ru/vacancy/139203618" autocomplete="off">'
            . '<button class="btn btn-sm btn-secondary" type="submit">Найти</button>'
            . ($query !== ''
                ? '<a class="btn btn-sm btn-ghost" href="' . $reset . '">Сброс</a>'
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
      <a class="top-nav-link" href="/?page=rejections">Отказы</a>
    </nav>
    <div class="topbar-right">
      <a class="top-nav-link" href="/?page=errors">Ошибки</a>
      <a class="top-nav-link is-active" href="/?page=chats">Чат</a>
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
                : '<p class="muted">Пока пусто. Нажми «Скачать с HH».</p>';
        }

        $rows = '';
        foreach ($items as $item) {
            $title = $this->str($item['vacancy_title'] ?? null);
            $company = $this->str($item['company_name'] ?? null);
            $status = $this->str($item['status_label'] ?? null);
            $state = strtoupper($this->str($item['applicant_state'] ?? null));
            $url = $this->str($item['url'] ?? null);
            $vacancyId = $this->str($item['vacancy_external_id'] ?? null);
            // Как на HH: время в карточке = last message; fallback — last activity.
            $activity = RuDate::dateTime($item['last_message_at'] ?? null);
            if ($activity === '') {
                $activity = RuDate::dateTime($item['last_activity_at'] ?? null);
            }
            $preview = $this->str($item['last_message_preview'] ?? null);
            $unread = (int) ($item['unread_count'] ?? 0);

            $titleHtml = $title !== '' ? $this->e($title) : '<span class="muted">Без названия</span>';
            if ($vacancyId !== '') {
                $titleHtml = '<a href="https://hh.ru/vacancy/' . $this->e(rawurlencode($vacancyId))
                    . '" target="_blank" rel="noopener noreferrer">' . $titleHtml . '</a>';
            }

            $resumeTitle = $this->str($item['resume_title'] ?? null);
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
            if ($unread > 0) {
                $meta[] = 'непрочитанных: ' . $this->e((string) $unread);
            }

            $open = $url !== ''
                ? '<a class="btn btn-sm btn-secondary" href="' . $this->e($url)
                    . '" target="_blank" rel="noopener noreferrer">Открыть на HH</a>'
                : '';

            $origin = $this->str($item['origin'] ?? null);
            $originBadge = $origin === 'archive'
                ? '<span class="chat-status-badge is-archive" title="Только в приложении">'
                    . $this->materialIcon('inventory_2') . ' архив</span>'
                : '';
            $closed = !empty($item['vacancy_closed']) || mb_strtolower($status, 'UTF-8') === 'закрыта';
            $badge = $this->statusBadge($state, $status, $closed);
            $stateClass = $closed ? 'is-closed' : $this->stateClass($state);
            $side = '';
            if ($open !== '' || $badge !== '' || $originBadge !== '') {
                $side = '<div class="chat-card-side">'
                    . ($open !== '' ? '<div class="chat-card-actions">' . $open . '</div>' : '')
                    . $originBadge
                    . $badge
                    . '</div>';
            }
            $rows .= '<article class="chat-card' . ($unread > 0 ? ' is-unread' : '')
                . ($origin === 'archive' ? ' is-archive' : '')
                . ($stateClass !== '' ? ' ' . $stateClass : '') . '">'
                . '<div class="chat-card-main">'
                . '<div class="chat-card-title">' . $titleHtml . '</div>'
                . ($meta !== [] ? '<div class="chat-card-meta">' . implode(' · ', $meta) . '</div>' : '')
                . ($preview !== '' ? '<div class="chat-card-preview muted">' . $this->e($preview) . '</div>' : '')
                . '</div>'
                . $side
                . '</article>';
        }

        return '<div class="chats-list">' . $rows . '</div>';
    }

    private function stateClass(string $state): string
    {
        return match ($state) {
            'RESPONSE' => 'is-response',
            'DISCARD', 'DISCARDED' => 'is-discard',
            'INVITATION' => 'is-invitation',
            'INTERVIEW', 'PHONE_INTERVIEW' => 'is-interview',
            'CONSIDER' => 'is-consider',
            default => '',
        };
    }

    private function statusBadge(string $state, string $label, bool $closed = false): string
    {
        if ($closed) {
            return '<div class="chat-status-badge is-closed">'
                . $this->materialIcon('domain_disabled') . ' Закрыта</div>';
        }
        if ($label === '' && $state === '') {
            return '';
        }
        $text = $label !== '' ? $label : $state;
        [$icon, $mod] = match ($state) {
            'RESPONSE' => ['mark_email_unread', 'is-response'],
            'DISCARD', 'DISCARDED' => ['cancel', 'is-discard'],
            'INVITATION' => ['waving_hand', 'is-invitation'],
            'INTERVIEW' => ['event', 'is-interview'],
            'PHONE_INTERVIEW' => ['call', 'is-interview'],
            'CONSIDER' => ['hourglass_top', 'is-consider'],
            default => ['chat', ''],
        };

        return '<div class="chat-status-badge' . ($mod !== '' ? ' ' . $mod : '') . '">'
            . $this->materialIcon($icon) . ' ' . $this->e($text) . '</div>';
    }

    private function materialIcon(string $name): string
    {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name)) ?? '';
        if ($name === '') {
            return '';
        }

        return '<span class="material-symbols-outlined" aria-hidden="true">' . $name . '</span>';
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
