<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

use JobSearch\Application\Error\AppErrorStore;

/**
 * View: журнал ошибок (отклики / jobs) — локальный FileCache.
 */
final class ErrorsPageView implements View
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
        $filtered = (int) ($data['filtered'] ?? count(is_array($data['items'] ?? null) ? $data['items'] : []));
        $retryable = (int) ($data['retryable'] ?? 0);
        $filter = $this->str($data['filter'] ?? 'open') ?: 'open';
        /** @var array<string, int> $counts */
        $counts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
        /** @var list<array<string, mixed>> $items */
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $viteTags = $this->vite !== null ? $this->vite->tags('errors') : '';
        $headActions = '';
        if ($retryable > 0) {
            $headActions .= '<a class="btn btn-sm" href="/?busy=apply_errors&amp;return=errors"'
                . ' onclick="return confirm(\'Повторить отклик по ' . $retryable . ' открытым ошибкам?\');">'
                . 'Повторить все (' . $retryable . ')</a>';
        }
        if ($total > 0) {
            $headActions .= '<a class="btn btn-sm btn-secondary" href="/?page=errors&amp;clear=1"'
                . ' onclick="return confirm(\'Очистить журнал ошибок?\');">Очистить</a>';
        }

        return '<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ошибки — job_search_2</title>
  <style>html,body{margin:0;background:#0f1115;color:#e8edf5}</style>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,500,0,0&display=swap">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/style.css">
  ' . $viteTags . '
</head>
<body>
  ' . $this->topbar($total) . '
  <main class="wrap">
    <section class="panel">
      <div class="panel-head">
        ' . CountBadge::title('Ошибки', $filtered) . '
        ' . $headActions . '
      </div>
      <p class="muted">Сбои apply* → открытые. Кнопка «Скип» на карточке или skip анкеты/письма в TG → вкладка «Скип» (не DevAI skip). Успешный отклик → «Исправленные».</p>
      ' . ($flash !== ''
            ? '<p class="flash ' . ($flashOk ? 'ok' : 'err') . ' js-flash">' . nl2br($this->e($flash), false) . '</p>'
            : '') . '
      ' . ($error !== '' ? '<p class="err">' . $this->e($error) . '</p>' : '') . '
      ' . $this->filters($filter, $counts) . '
      ' . $this->list($items, $filter) . '
    </section>
  </main>
</body>
</html>
';
    }

    private function topbar(int $total): string
    {
        $errorsNav = 'Ошибки' . ($total > 0 ? CountBadge::html($total) : '');

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
      <a class="top-nav-link" href="/?page=rehydrate">Перекачка</a>
      <a class="top-nav-link is-active" href="/?page=errors">' . $errorsNav . '</a>
      <a class="top-nav-link" href="/?page=chats">Чат</a>
    </div>
  </div>
</header>';
    }

    /** @param array<string, int> $counts */
    private function filters(string $filter, array $counts): string
    {
        $tabs = [
            'open' => 'Открытые',
            'unavailable' => 'Недоступны',
            'skipped' => 'Скип',
            'resolved' => 'Исправленные',
            'all' => 'Все',
        ];
        $html = '<div class="filter-tabs">';
        foreach ($tabs as $key => $label) {
            $n = (int) ($counts[$key] ?? 0);
            $cls = $key === $filter ? 'filter-tab is-active' : 'filter-tab';
            $href = '/?page=errors' . ($key === 'open' ? '' : '&amp;filter=' . rawurlencode($key));
            $html .= '<a class="' . $cls . '" href="' . $href . '">'
                . CountBadge::tabLabel($label, $n) . '</a>';
        }

        return $html . '</div>';
    }

    /** @param list<array<string, mixed>> $items */
    private function list(array $items, string $filter): string
    {
        if ($items === []) {
            $hint = match ($filter) {
                'resolved' => 'Нет исправленных записей.',
                'unavailable' => 'Нет ошибок «вакансия недоступна».',
                'skipped' => 'Нет пропусков (skip анкеты/письма в TG).',
                'all' => 'Пока пусто — ошибки появятся после сбоев отклика.',
                default => 'Нет открытых ошибок.',
            };

            return '<p class="muted">' . $this->e($hint) . '</p>';
        }

        $rows = '';
        $filterQ = $filter !== 'open' ? '&amp;filter=' . rawurlencode($filter) : '';
        foreach ($items as $item) {
            $at = RuDate::dateTime($item['at'] ?? null);
            $source = $this->str($item['source'] ?? null);
            $resume = $this->str($item['resume_title'] ?? null);
            if ($resume === '') {
                $resume = $this->str($item['resume_id'] ?? null);
            }
            $vacancyTitle = $this->str($item['vacancy_title'] ?? null);
            $vacancyId = $this->str($item['vacancy_id'] ?? null);
            $message = $this->str($item['message'] ?? null);
            $meta = [];
            if ($at !== '') {
                $meta[] = $at;
            }
            if ($resume !== '') {
                $meta[] = 'резюме: ' . $resume;
            }
            $title = $this->vacancyHeading($vacancyTitle, $vacancyId);
            $hhHref = $vacancyId !== ''
                ? 'https://hh.ru/vacancy/' . rawurlencode($vacancyId)
                : '';
            $titleHtml = $hhHref !== ''
                ? '<a class="errors-item-hh" href="' . $this->e($hhHref)
                    . '" target="_blank" rel="noopener noreferrer">' . $this->e($title) . '</a>'
                : $this->e($title);
            $sourceBadge = $this->sourceBadge($source);
            $resumeId = $this->str($item['resume_id'] ?? null);
            $resolvedAt = RuDate::dateTime($item['resolved_at'] ?? null);
            $skippedAt = RuDate::dateTime($item['skipped_at'] ?? null);
            $skipped = $skippedAt !== ''
                || AppErrorStore::isSkipMessage($message);
            $resolved = $resolvedAt !== '';
            if ($skipped) {
                $meta[] = 'скип' . ($skippedAt !== '' ? ' ' . $skippedAt : ($resolvedAt !== '' ? ' ' . $resolvedAt : ''));
            } elseif ($resolved) {
                $meta[] = 'исправлено ' . $resolvedAt;
            }
            $actions = [];
            if ($hhHref !== '') {
                $actions[] = '<a class="btn btn-sm btn-secondary" href="' . $this->e($hhHref)
                    . '" target="_blank" rel="noopener noreferrer">Открыть на hh.ru</a>';
            }
            $errorId = $this->str($item['id'] ?? null);
            if (!$resolved && !$skipped && $resumeId !== '' && $vacancyId !== '') {
                $retryHref = '/?busy=apply'
                    . '&amp;resume=' . rawurlencode($resumeId)
                    . '&amp;vacancy=' . rawurlencode($vacancyId)
                    . ($vacancyTitle !== '' && $vacancyTitle !== $vacancyId
                        ? '&amp;title=' . rawurlencode($vacancyTitle)
                        : '')
                    . '&amp;return=errors'
                    . $filterQ;
                $actions[] = '<a class="btn btn-sm" href="' . $retryHref . '">Повторить</a>';
                if ($errorId !== '') {
                    $actions[] = '<a class="btn btn-sm btn-secondary" href="/?page=errors&amp;skip='
                        . rawurlencode($errorId) . $filterQ
                        . '" onclick="return confirm(\'Скипнуть эту ошибку? Повторно откликаться не будем из журнала.\');">Скип</a>';
                }
            } elseif (!$resolved && !$skipped && $vacancyId === '') {
                // Batch-сбой без id вакансии — повторить некуда.
                $meta[] = 'без id вакансии — «Повторить» недоступен';
                if ($errorId !== '') {
                    $actions[] = '<a class="btn btn-sm btn-secondary" href="/?page=errors&amp;resolve='
                        . rawurlencode($errorId) . $filterQ
                        . '" onclick="return confirm(\'Закрыть эту ошибку?\');">Закрыть</a>';
                }
            }
            $actionsHtml = $actions !== []
                ? '<div class="errors-item-actions">' . implode('', $actions) . '</div>'
                : '';
            $itemClass = 'errors-item'
                . ($skipped ? ' is-skipped' : ($resolved ? ' is-resolved' : ''));
            $rows .= '<li class="' . $itemClass . '">'
                . '<div class="errors-item-title">'
                . $sourceBadge
                . $titleHtml
                . '</div>'
                . ($meta !== [] ? '<div class="errors-item-meta muted">' . $this->e(implode(' · ', $meta)) . '</div>' : '')
                . '<div class="errors-item-msg">' . $this->e($message) . '</div>'
                . $actionsHtml
                . '</li>';
        }

        return '<ul class="errors-list">' . $rows . '</ul>';
    }

    /** Название и номер вакансии вместе: «Title · 139279409». */
    private function vacancyHeading(string $title, string $vacancyId): string
    {
        $title = trim($title);
        $vacancyId = trim($vacancyId);
        if ($title !== '' && $vacancyId !== '' && $title !== $vacancyId) {
            // Если title уже содержит номер — не дублируем.
            if (!str_contains($title, $vacancyId)) {
                return $title . ' · ' . $vacancyId;
            }

            return $title;
        }
        if ($title !== '') {
            return $title;
        }
        if ($vacancyId !== '') {
            return $vacancyId;
        }

        return 'Ошибка';
    }

    /** Иконка источника: одиночный apply / пакет apply_consider. */
    private function sourceBadge(string $source): string
    {
        if ($source === 'apply_consider') {
            return '<span class="errors-source-badge" title="Пакет «Смотрим» (apply-consider)">'
                . $this->materialIcon('playlist_add_check') . '</span>';
        }
        if ($source === 'apply') {
            return '<span class="errors-source-badge" title="Одиночный отклик (apply)">'
                . $this->materialIcon('send') . '</span>';
        }
        if ($source === '') {
            return '';
        }

        return '<span class="errors-source-badge" title="' . $this->e($source) . '">'
            . $this->materialIcon('info') . '</span>';
    }

    private function materialIcon(string $name): string
    {
        return '<span class="material-symbols-outlined" aria-hidden="true">' . $name . '</span>';
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
