<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * View: HTML-страница в духе hh.ru (шапка, аккаунт, резюме, модалка города).
 * Только представление — без вызовов API и чтения .env.
 */
final class HtmlPageView implements View
{
    private const ACCOUNT_URL = 'https://hh.ru/applicant/profile';
    private const RESUME_URL = 'https://hh.ru/resume/';
    /** SERP подходящих вакансий по резюме. */
    private const VACANCY_SERP_URL = 'https://hh.ru/search/vacancy?resume=';

    public function __construct(
        private readonly ViteAssets $vite,
    ) {}

    /** @param array<string, mixed> $data */
    public function render(array $data): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->page($data);
    }

    /**
     * Экран ожидания: inline CSS (чтобы сразу рисовать), логика — Vite entry `busy`.
     */
    public function renderBusy(string $job): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $job = in_array(
            $job,
            ['load', 'refresh', 'set_city', 'vacancies', 'apply', 'apply_consider', 'apply_errors', 'rehydrate_bot_wall', 'chats', 'leave_rejections', 'leave_old', 'resume_views'],
            true,
        ) ? $job : 'load';
        $title = match ($job) {
            'refresh' => 'Обновляем данные',
            'set_city' => 'Переключаем город',
            'vacancies' => 'Скачиваем вакансии по всем резюме',
            'apply' => 'Откликаюсь на HH…',
            'apply_consider' => 'Откликаюсь на «Смотрим»…',
            'apply_errors' => 'Повторяю отклики из ошибок…',
            'rehydrate_bot_wall' => 'Перекачиваем bot-wall…',
            'chats' => 'Скачиваем чаты HH…',
            'leave_rejections' => 'Покидаю чаты с отказами…',
            'leave_old' => 'Покидаю чаты старше месяца…',
            'resume_views' => 'Скачиваем просмотры резюме…',
            default => 'Загружаем город и резюме',
        };

        echo '<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . $this->e($title) . '</title>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <style>
    html,body{margin:0;min-height:100%;background:#0b1220;color:#f4f7fb;font:14px/1.45 system-ui,sans-serif}
    .wrap{max-width:36rem;margin:0 auto;padding:2.5rem 1.25rem}
    .card{border:2px solid #3d6fb5;border-radius:14px;padding:1.5rem;background:#152238}
    h1{margin:0 0 .75rem;font-size:1.35rem;color:#fff}
    .hint{color:#c5d4ef;margin:0 0 1rem}
    #busy-status{color:#9fe3c2;font-weight:600;min-height:1.5em;margin:0}
    #busy-elapsed{color:#c5d4ef;font-variant-numeric:tabular-nums;margin:.35rem 0 0;font-size:.95rem}
    #busy-error{color:#ffb4b4;font-weight:600}
    .btn{display:none;margin-top:1rem;padding:.55rem 1rem;border-radius:8px;background:#6aa8ff;color:#0b1220;text-decoration:none;font-weight:700}
    .btn.show{display:inline-block}
    .pulse{display:inline-block;width:.55em;height:.55em;border-radius:50%;background:#6aa8ff;margin-right:.4em;animation:p 1s infinite}
    @keyframes p{0%,100%{opacity:1}50%{opacity:.35}}
  </style>
</head>
<body data-busy-job="' . $this->e($job) . '">
  <main class="wrap">
    <section class="card">
      <h1><span class="pulse" aria-hidden="true"></span>' . $this->e($title) . '</h1>
      <p class="hint">Playwright к hh.ru через прокси — обычно 10–40 с. Ниже идут секунды и шаг.</p>
      <p id="busy-status">Задание запущено на сервере…</p>
      <p id="busy-elapsed">0 с</p>
      <p id="busy-error" style="display:none"></p>
      <a class="btn" href="/" id="busy-home">На главную</a>
    </section>
  </main>
  <script>
    /* Счётчик + watchdog: уход с экрана даже если старый/битый busy.js завис с кнопкой. */
    (function () {
      var el = document.getElementById("busy-elapsed");
      var err = document.getElementById("busy-error");
      var home = document.getElementById("busy-home");
      var t0 = Date.now();
      var job = document.body.getAttribute("data-busy-job") || "load";
      var longJob = /^(vacancies|resume_views|apply|apply_consider|apply_errors|rehydrate_bot_wall|leave_rejections|leave_old)$/.test(job);
      var maxMs = longJob ? 1200000 : 180000;
      var gone = false;
      var errorRedirectArmed = false;
      var stoppedAt = 0;

      function goHome(msg, ok) {
        if (gone) return;
        gone = true;
        document.body.setAttribute("data-busy-stopped", "1");
        var q = new URLSearchParams();
        if (msg) q.set("flash", msg);
        q.set("ok", ok ? "1" : "0");
        var cur = new URLSearchParams(window.location.search);
        if (job === "apply_errors") {
          q.set("page", "errors");
        } else if (job === "rehydrate_bot_wall") {
          q.set("page", "rehydrate");
        } else if (job === "chats" || job === "leave_rejections" || job === "leave_old") {
          q.set("page", "chats");
        } else if (job === "apply" || job === "apply_consider" || job === "resume_views") {
          if ((cur.get("return") || "") === "errors") {
            q.set("page", "errors");
          } else {
            q.set("page", "vacancies");
            var resume = cur.get("resume") || "";
            var filter = job === "apply_consider" ? "consider" : (cur.get("filter") || "");
            var scope = cur.get("scope") || "";
            if (resume) q.set("resume", resume);
            if (filter && filter !== "all") q.set("filter", filter);
            if (scope === "all") q.set("scope", "all");
          }
        }
        window.location.replace("/?" + q.toString());
      }

      var tick = function () {
        if (document.body.getAttribute("data-busy-stopped") === "1") {
          if (!stoppedAt) stoppedAt = Date.now();
          /* Бандл пометил стоп, но не ушёл — через 4 с форсим. */
          if (!gone && Date.now() - stoppedAt > 4000) {
            var left = err && err.textContent ? err.textContent : "Ожидание прервано.";
            goHome(left, false);
          }
          return;
        }
        if (el) {
          el.textContent = Math.floor((Date.now() - t0) / 1000) + " с";
        }
        if (Date.now() - t0 > maxMs) {
          var mins = Math.max(1, Math.round(maxMs / 60000));
          goHome("Таймаут " + mins + " мин на экране ожидания.", false);
          return;
        }
        if (err && err.style.display !== "none" && (err.textContent || "").trim() !== "") {
          if (home) home.classList.add("show");
          if (!errorRedirectArmed) {
            errorRedirectArmed = true;
            window.setTimeout(function () {
              goHome((err.textContent || "").trim() || "Ошибка задания.", false);
            }, 1200);
          }
        }
      };
      tick();
      setInterval(tick, 250);
    })();
  </script>
  ' . $this->vite->tags('busy') . '
</body>
</html>
';
    }

    /** @param array<string, mixed> $data */
    private function page(array $data): string
    {
        $flash = $this->str($data['flash'] ?? null);
        $flashOk = (bool) ($data['flash_ok'] ?? true);
        $sessionExists = (bool) ($data['session_exists'] ?? false);
        $hasBrowserData = (bool) ($data['has_browser_data'] ?? false);
        $cityLabel = $this->cityLabel($data);
        $vacanciesHref = $this->vacanciesNavHref($data);

        return '<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>job_search_2</title>
  <style>html,body{margin:0;background:#0f1115;color:#e8edf5}</style>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/style.css">
</head>
<body>
  ' . $this->topbar($cityLabel, $sessionExists, $vacanciesHref) . '
  <main class="wrap">
'
            . $this->flashBlock($flash, $flashOk)
            . $this->accountBlock($data, $sessionExists, $hasBrowserData)
            . $this->resumesBlock($data, $sessionExists, $hasBrowserData)
            . '
  </main>
  ' . $this->footer($sessionExists, $hasBrowserData) . '
  ' . $this->cityModal($cityLabel) . '
  ' . $this->resumeTextModal() . '
  ' . $this->vacanciesModal() . '
  ' . $this->resumeTextsJson($data) . '
  ' . $this->vite->tags('ui') . '
</body>
</html>
';
    }

    private function topbar(string $cityLabel, bool $sessionExists, string $vacanciesHref): string
    {
        $cityText = $cityLabel !== '' ? $cityLabel : 'Город';
        $cityBtn = $sessionExists
            ? '<button type="button" class="city-chip js-open-city" title="Сменить город">'
                . '<span class="city-chip-ico" aria-hidden="true">⌖</span>'
                . '<span>' . $this->e($cityText) . '</span>'
                . '</button>'
            : '<span class="city-chip muted">' . $this->e($cityText) . '</span>';

        return '<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/">job_search_2</a>
    <nav class="top-nav">
      <a class="top-nav-link is-active" href="/">Главная</a>
      <a class="top-nav-link" href="http://127.0.0.1:3000/d/job-search-2/poisk-raboty?orgId=1&amp;refresh=30s" target="_blank" rel="noopener">Статистика</a>
      <a class="top-nav-link" href="/?page=applications">Отклики</a>
      <a class="top-nav-link" href="' . $this->e($vacanciesHref) . '">Вакансии</a>
      <a class="top-nav-link" href="/?page=companies">Компании</a>
      <a class="top-nav-link" href="/?page=exclusions">Исключения</a>
      <a class="top-nav-link" href="/?page=rejections">Отказы</a>
    </nav>
    <div class="topbar-right">
      <a class="top-nav-link" href="/?page=rehydrate">Перекачка</a>
      <a class="top-nav-link" href="/?page=errors">Ошибки</a>
      <a class="top-nav-link" href="/?page=chats">Чат</a>
      ' . $cityBtn . '
    </div>
  </div>
</header>';
    }

    /** Ссылка в шапке: первое резюме с пачкой, иначе пустая страница вакансий. */
    private function vacanciesNavHref(array $data): string
    {
        $vacanciesByResume = is_array($data['vacancies_by_resume'] ?? null)
            ? $data['vacancies_by_resume']
            : [];
        foreach ($vacanciesByResume as $resumeId => $meta) {
            if (!is_string($resumeId) || trim($resumeId) === '' || !is_array($meta)) {
                continue;
            }
            if ((int) ($meta['count'] ?? 0) <= 0) {
                continue;
            }
            $href = '/?page=vacancies&resume=' . rawurlencode($resumeId);
            $unscored = (int) ($meta['unscored_count'] ?? 0);
            $consider = (int) ($meta['consider_count'] ?? 0);
            if ($unscored === 0 && $consider > 0) {
                $href .= '&filter=consider';
            }

            return $href;
        }

        return '/?page=vacancies';
    }

    private function footer(bool $sessionExists, bool $hasBrowserData): string
    {
        $session = $sessionExists ? 'browser-сессия есть' : 'нет browser-сессии';
        $cache = $hasBrowserData ? 'данные из кеша' : 'кеш города/резюме пуст';

        return '<footer class="footer">
  <div class="footer-inner">Статус HH: OAuth · ' . $this->e($session) . ' · ' . $this->e($cache) . '</div>
</footer>';
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

    /** @param array<string, mixed> $data */
    private function accountBlock(array $data, bool $sessionExists, bool $hasBrowserData): string
    {
        $error = $this->str($data['account_error'] ?? null);
        /** @var array<string, mixed>|null $account */
        $account = is_array($data['account'] ?? null) ? $data['account'] : null;

        $refresh = $sessionExists
            ? '<a class="icon-btn" href="/?busy=refresh" title="Обновить данные" aria-label="Обновить данные">'
                . '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">'
                . '<path fill="currentColor" d="M12 6V3L8 7l4 4V8c2.76 0 5 2.24 5 5a5 5 0 0 1-8.66 3.46l-1.42 1.42A7 7 0 0 0 19 13c0-3.87-3.13-7-7-7zm0 12v3l4-4-4-4v3a5 5 0 0 1-5-5c0-1.1.36-2.12.97-2.95l-1.42-1.42A7 7 0 0 0 5 13c0 3.87 3.13 7 7 7z"/>'
                . '</svg></a>'
            : '';

        if (!$sessionExists) {
            $refresh = '';
        }

        if (!$hasBrowserData && $sessionExists) {
            $refresh = '<a class="icon-btn" href="/?busy=load" title="Загрузить данные" aria-label="Загрузить данные">'
                . '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">'
                . '<path fill="currentColor" d="M12 6V3L8 7l4 4V8c2.76 0 5 2.24 5 5a5 5 0 0 1-8.66 3.46l-1.42 1.42A7 7 0 0 0 19 13c0-3.87-3.13-7-7-7zm0 12v3l4-4-4-4v3a5 5 0 0 1-5-5c0-1.1.36-2.12.97-2.95l-1.42-1.42A7 7 0 0 0 5 13c0 3.87 3.13 7 7 7z"/>'
                . '</svg></a>';
        }

        if ($error !== '') {
            return '<section class="panel account-panel">
  <div class="account-head">
    <h2 title="">Аккаунт</h2>
    ' . $refresh . '
  </div>
  <p class="err">' . $this->e($error) . '</p>
</section>';
        }

        if ($account === null) {
            return '<section class="panel account-panel">
  <div class="account-head">
    <h2>Аккаунт</h2>
    ' . $refresh . '
  </div>
  <p class="muted">Нет данных аккаунта.</p>
</section>';
        }

        $http = (int) ($account['http'] ?? 0);
        if ($http > 0 && ($http < 200 || $http >= 300)) {
            return '<section class="panel account-panel">
  <div class="account-head">
    <h2>Аккаунт</h2>
    ' . $refresh . '
  </div>
  <p class="err">Не удалось получить профиль (HTTP ' . $this->e((string) $http) . ').</p>
</section>';
        }

        $name = trim(
            $this->str($account['first_name'] ?? null)
            . ' '
            . $this->str($account['last_name'] ?? null),
        );
        if ($name === '') {
            $name = 'Без имени';
        }
        $email = $this->str($account['email'] ?? null);
        $id = $this->str($account['id'] ?? null);
        $titleAttr = $id !== '' ? ' title="ID: ' . $this->e($id) . '"' : '';

        $emailHtml = $email !== ''
            ? '<p class="account-email">' . $this->e($email) . '</p>'
            : '';

        return '<section class="panel account-panel">
  <div class="account-head">
    <h2' . $titleAttr . '>Аккаунт</h2>
    ' . $refresh . '
  </div>
  <p class="account-name">
    <a href="' . $this->e(self::ACCOUNT_URL) . '" target="_blank" rel="noopener noreferrer">'
            . $this->e($name) . '</a>
  </p>
  ' . $emailHtml . '
</section>';
    }

    /** @param array<string, mixed> $data */
    private function resumesBlock(array $data, bool $sessionExists, bool $hasBrowserData): string
    {
        if (!$sessionExists) {
            return '<section class="panel">
  <h2>Резюме</h2>
  <p class="warn">Нет browser-сессии. Сначала: <code>npm run hh:browser:login</code></p>
</section>';
        }

        if (!$hasBrowserData) {
            return '<section class="panel">
  <h2>Резюме</h2>
  <p class="muted">Данные ещё не загружены.</p>
  <p><a class="btn" href="/?busy=load">Загрузить</a></p>
</section>';
        }

        $error = $this->str($data['resumes_error'] ?? null);
        /** @var array<string, mixed>|null $resumes */
        $resumes = is_array($data['resumes'] ?? null) ? $data['resumes'] : null;

        if ($error !== '') {
            return '<section class="panel">
  <h2>Резюме</h2>
  <p class="err">' . $this->e($error) . '</p>
</section>';
        }

        if ($resumes === null) {
            return '<section class="panel">
  <h2>Резюме</h2>
  <p class="muted">Нет данных.</p>
</section>';
        }

        $kind = $this->str($resumes['kind'] ?? null);
        if ($kind !== '' && $kind !== 'ok') {
            $hint = match ($kind) {
                'login_required' => 'Нужен login: npm run hh:browser:login',
                'captcha_or_action_required' => 'HH просит captcha / доп. действие в браузере.',
                'permission_blocked' => 'Доступ к резюме заблокирован.',
                default => 'Не удалось прочитать резюме.',
            };

            return '<section class="panel">
  <h2>Резюме</h2>
  <p class="warn">' . $this->e($hint) . '</p>
</section>';
        }

        /** @var list<array<string, mixed>> $items */
        $items = is_array($resumes['items'] ?? null) ? $resumes['items'] : [];
        /** @var array<string, array{search_run_id?: string, count?: int, unscored_count?: int, skip_count?: int, consider_count?: int}> $vacanciesByResume */
        $vacanciesByResume = is_array($data['vacancies_by_resume'] ?? null)
            ? $data['vacancies_by_resume']
            : [];
        /** @var array{day?: string, goal?: int, by_resume?: array<string, int>, total_today?: int} $daily */
        $daily = is_array($data['daily_progress'] ?? null) ? $data['daily_progress'] : [];
        $goal = max(1, (int) ($daily['goal'] ?? 10));
        $dayRaw = (string) ($daily['day'] ?? '');
        $dayLabel = RuDate::day($dayRaw);
        if ($dayLabel === '' && $dayRaw !== '') {
            $dayLabel = $dayRaw;
        }
        /** @var array<string, int> $appliedByResume */
        $appliedByResume = is_array($daily['by_resume'] ?? null) ? $daily['by_resume'] : [];
        $totalToday = (int) ($daily['total_today'] ?? 0);
        $resumeCount = count($items);
        $normAll = $goal * max(1, $resumeCount);
        /** @var list<string> $scoringWatchIds */
        $scoringWatchIds = [];
        if (is_array($data['scoring_watch_ids'] ?? null)) {
            foreach ($data['scoring_watch_ids'] as $wid) {
                if (is_string($wid) && $wid !== '') {
                    $scoringWatchIds[] = $wid;
                }
            }
        }
        $scoringWatchSet = array_fill_keys($scoringWatchIds, true);

        $rows = '';
        $sumSkip = 0;
        $sumConsider = 0;
        $sumUnscored = 0;
        foreach ($items as $item) {
            $title = $this->str($item['title'] ?? null);
            $id = $this->str($item['external_id'] ?? null);
            $count = $item['vacancies_count'] ?? null;
            $titleText = $title !== '' ? $title : 'Без названия';
            $titleHtml = $id !== ''
                ? '<a class="resume-title" href="' . $this->e(self::RESUME_URL . $id) . '" target="_blank" rel="noopener noreferrer">'
                    . $this->e($titleText) . '</a>'
                : $this->e($titleText);

            if (is_int($count) && $id !== '') {
                $countHtml = '<a class="resume-count" href="' . $this->e(self::VACANCY_SERP_URL . rawurlencode($id))
                    . '" target="_blank" rel="noopener noreferrer">' . $this->e((string) $count) . '</a>';
            } elseif (is_int($count)) {
                $countHtml = $this->e((string) $count);
            } else {
                $countHtml = '—';
            }

            $sheet = $this->sheetIcon();
            $sheetHtml = $id !== ''
                ? '<button type="button" class="resume-sheet js-resume-text" data-resume-id="'
                    . $this->e($id) . '" title="Текст резюме" aria-label="Показать текст резюме">'
                    . $sheet . '</button>'
                : '<span class="resume-sheet is-muted" aria-hidden="true">' . $sheet . '</span>';

            $downloadedCount = 0;
            $unscoredCount = 0;
            $skipCount = 0;
            $considerCount = 0;
            if ($id !== '' && isset($vacanciesByResume[$id]) && is_array($vacanciesByResume[$id])) {
                $downloadedCount = (int) ($vacanciesByResume[$id]['count'] ?? 0);
                $unscoredCount = (int) ($vacanciesByResume[$id]['unscored_count'] ?? 0);
                $skipCount = (int) ($vacanciesByResume[$id]['skip_count'] ?? 0);
                $considerCount = (int) ($vacanciesByResume[$id]['consider_count'] ?? 0);
            }
            $sumSkip += $skipCount;
            $sumConsider += $considerCount;
            $sumUnscored += $unscoredCount;

            $appliedToday = $id !== '' ? (int) ($appliedByResume[$id] ?? 0) : 0;
            $pct = (int) min(100, round(100 * $appliedToday / $goal));
            $goalDone = $appliedToday >= $goal;
            $scoredDone = $skipCount + $considerCount;
            $scoreTotal = $scoredDone + $unscoredCount;
            if ($scoreTotal <= 0 && $downloadedCount > 0) {
                $scoreTotal = $downloadedCount;
            }
            $scorePct = $scoreTotal > 0 ? (int) min(100, round(100 * $scoredDone / $scoreTotal)) : 0;
            $scoreActive = $unscoredCount > 0;
            $justQueued = $id !== '' && isset($scoringWatchSet[$id]);
            $scoreLabel = $scoreActive
                ? ($justQueued
                    ? 'Только что в очереди DevAI — оценивает: ' . $scoredDone . '/' . $scoreTotal
                        . ', ждём ' . $unscoredCount . '…'
                    : 'DevAI оценивает: ' . $scoredDone . '/' . $scoreTotal . ', ждём ' . $unscoredCount . '…')
                : ($scoreTotal > 0
                    ? 'По этому резюме пачка оценена: ' . $scoredDone . '/' . $scoreTotal
                        . ' (skip ' . $skipCount . ', смотрим ' . $considerCount . ').'
                    : 'Нет пачки для оценки.');
            $watchOnLoad = $scoreActive || $justQueued;
            $scoringBar = $downloadedCount > 0 && $id !== ''
                ? '<div class="scoring-progress js-resume-scoring'
                    . ($scoreActive || $justQueued ? ' is-active' : ($scoreTotal > 0 ? ' is-done' : ''))
                    . '" data-resume-id="' . $this->e($id) . '"'
                    . ($watchOnLoad ? ' data-watch-on-load="1"' : '')
                    . ($scoreActive || $scoreTotal > 0 || $justQueued ? '' : ' hidden')
                    . '>'
                    . '<p class="scoring-progress-label">' . $this->e($scoreLabel) . '</p>'
                    . '<div class="scoring-progress-track" aria-hidden="true">'
                    . '<span class="scoring-progress-fill" style="width:' . $scorePct . '%"></span>'
                    . '</div>'
                    . '</div>'
                : '';
            $progressHtml = '<div class="resume-progress">'
                . '<div class="resume-progress-goal' . ($goalDone ? ' is-done' : '') . '">'
                . 'Сегодня <strong>' . $this->e((string) $appliedToday) . '/' . $this->e((string) $goal) . '</strong>'
                . '</div>'
                . '<div class="resume-progress-bar" aria-hidden="true"><span style="width:' . $pct . '%"></span></div>'
                . '<div class="resume-progress-batch">'
                . ($downloadedCount > 0
                    ? 'Пачка: <span class="is-skip">' . $this->e((string) $skipCount) . ' skip</span>'
                        . ' · <span class="is-consider">' . $this->e((string) $considerCount) . ' смотрим</span>'
                        . ' · <span class="is-wait">' . $this->e((string) $unscoredCount) . ' без оценки</span>'
                    : 'Пачки ещё нет — нажми «Качать suitable»')
                . '</div>'
                . $scoringBar
                . '</div>';

            $fetchOne = $id !== ''
                ? '<a class="btn btn-sm btn-secondary" href="/?busy=vacancies&amp;resume='
                    . $this->e(rawurlencode($id))
                    . '" title="Качать новые suitable и сразу оценивать DevAI">'
                    . 'Качать suitable</a>'
                : '';
            $scoreOne = $unscoredCount > 0 && $id !== ''
                ? '<button type="button" class="btn btn-sm btn-secondary js-score-ten" data-resume-id="'
                    . $this->e($id) . '" title="Догон DevAI: до '
                    . $this->e((string) min(40, $unscoredCount))
                    . ' без оценки (все suitable-run + owner, не только последний прогон)">'
                    . 'Оценить ' . $this->e((string) min(40, $unscoredCount)) . '</button>'
                : '';
            $viewHtml = $downloadedCount > 0 && $id !== ''
                ? '<a class="btn btn-sm btn-secondary" href="/?page=vacancies&amp;resume='
                    . $this->e(rawurlencode($id))
                    . ($unscoredCount === 0 && $considerCount > 0 ? '&amp;filter=consider' : '')
                    . '">Смотреть ' . $this->e((string) $downloadedCount) . '</a>'
                : '';
            $actions = $fetchOne . $scoreOne . $viewHtml;
            if ($actions === '') {
                $actions = '<span class="muted">—</span>';
            }

            $rows .= '<tr>
  <td>' . $titleHtml . $progressHtml . '</td>
  <td class="vac-meta"><div class="vac-meta-inner">' . $countHtml . $sheetHtml . '</div></td>
  <td class="actions"><div class="action-stack">' . $actions . '</div></td>
</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">Резюме не найдены.</td></tr>';
        }

        $count = (int) ($resumes['count'] ?? count($items));
        $panelActions = $items !== []
            ? '<div class="panel-actions">'
                . '<a class="btn btn-sm btn-secondary" href="/?busy=vacancies"'
                . ' title="На каждое резюме: качать новые suitable и сразу оценивать DevAI">'
                . 'Качать suitable всем</a>'
                . '<button type="button" class="btn btn-sm '
                . ($sumUnscored > 0 ? 'btn-primary' : 'btn-secondary')
                . ' js-score-ten"'
                . ($sumUnscored === 0 ? ' disabled' : '')
                . ' title="Догон DevAI: все резюме, неоценённые за 7 дней (suitable-run + owner)">'
                . 'Оценить оставшиеся'
                . ($sumUnscored > 0 ? ' ' . $this->e((string) $sumUnscored) : '')
                . '</button>'
                . '</div>'
            : '';
        /** @var array{running?: bool, tasks?: list<array<string, mixed>>} $schedule */
        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $vacSchedule = ScheduleNote::vacancies($schedule);
        $vacScheduleHtml = $vacSchedule !== ''
            ? '<p class="muted vacancies-auto-note js-schedule-status" data-schedule-task="vacancies">'
                . $this->e($vacSchedule)
                . ' · новые ≤7 дней, сразу DevAI (не стоп по apply/maybe)'
                . '</p>'
            : '';

        $summary = '<div class="progress-summary">'
            . '<p class="panel-goal">Цель: <strong>' . $this->e((string) $goal)
            . '</strong> откликов на каждое резюме в день (не skip).'
            . ($dayLabel !== '' ? ' День: ' . $this->e($dayLabel) . ' (МСК).' : '')
            . '</p>'
            . '<p class="progress-summary-line">'
            . 'Сегодня откликов: <strong>' . $this->e((string) $totalToday) . '</strong>'
            . ' из нормы <strong>' . $this->e((string) $normAll) . '</strong>'
            . ' (' . $this->e((string) $resumeCount) . '×' . $this->e((string) $goal) . ').'
            . '</p>'
            . '<p class="progress-summary-line">'
            . 'Последние пачки: '
            . '<span class="is-skip">' . $this->e((string) $sumSkip) . ' не подходят</span> · '
            . '<span class="is-consider">' . $this->e((string) $sumConsider) . ' рассматриваем</span> · '
            . '<span class="is-wait">' . $this->e((string) $sumUnscored) . ' без оценки</span>.'
            . ($sumUnscored > 0
                ? ' Догон: «Оценить оставшиеся» выше или «Оценить N» у резюме.'
                : ' Догон не нужен — «Оценить оставшиеся» сверху неактивна. Зелёные на вакансиях — «Откликнуться».')
            . '</p>'
            . '</div>';

        return '<section class="panel">
  <div class="panel-head">
    <h2 class="page-title-row">Резюме ' . CountBadge::html($count) . '</h2>
    ' . $panelActions . '
  </div>
  ' . $vacScheduleHtml . '
  ' . $summary . '
  <div class="table-scroll">
    <table class="resume-table">
      <thead>
        <tr><th>Название / прогресс</th><th class="vac-meta">Вакансий</th><th class="actions">Действия</th></tr>
      </thead>
      <tbody>
        ' . $rows . '
      </tbody>
    </table>
  </div>
</section>';
    }

    /** SVG листа бумаги для колонки текста резюме. */
    private function sheetIcon(): string
    {
        return '<svg class="sheet-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" '
            . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>'
            . '<polyline points="14 2 14 8 20 8"/>'
            . '<line x1="8" x2="16" y1="13" y2="13"/>'
            . '<line x1="8" x2="16" y1="17" y2="17"/>'
            . '<line x1="8" x2="12" y1="9" y2="9"/>'
            . '</svg>';
    }

    /** Модалка с текстом резюме (показ по наведению на лист). */
    private function resumeTextModal(): string
    {
        return '<div class="modal resume-text-modal" id="resume-text-modal" hidden>
  <div class="modal-backdrop js-close-resume-text"></div>
  <div class="modal-card resume-text-card" role="dialog" aria-modal="true" aria-labelledby="resume-text-title">
    <div class="modal-head">
      <h2 id="resume-text-title">Текст резюме</h2>
      <button type="button" class="modal-close js-close-resume-text" aria-label="Закрыть">×</button>
    </div>
    <pre class="resume-text-body" id="resume-text-body"></pre>
    <p class="resume-text-empty muted" id="resume-text-empty" hidden>Текст ещё не скачан. Нажмите «Обновить данные».</p>
  </div>
</div>';
    }

    private function vacanciesModal(): string
    {
        return '<div class="modal vacancies-modal" id="vacancies-modal" hidden>
  <div class="modal-backdrop js-close-vacancies"></div>
  <div class="modal-card vacancies-card" role="dialog" aria-modal="true" aria-labelledby="vacancies-title">
    <div class="modal-head">
      <h2 id="vacancies-title">Скачанные вакансии</h2>
      <button type="button" class="modal-close js-close-vacancies" aria-label="Закрыть">×</button>
    </div>
    <div class="vacancies-layout">
      <ul class="vacancies-list" id="vacancies-list"></ul>
      <div class="vacancy-detail" id="vacancy-detail">
        <p class="muted" id="vacancy-detail-empty">Выберите вакансию слева.</p>
        <div id="vacancy-detail-body" hidden>
          <h3 id="vacancy-detail-title"></h3>
          <p class="vacancy-verdict" id="vacancy-detail-verdict" hidden></p>
          <p class="vacancy-meta" id="vacancy-detail-meta"></p>
          <p><a id="vacancy-detail-link" href="#" target="_blank" rel="noopener noreferrer">Открыть на hh.ru</a></p>
          <div class="vacancy-skills" id="vacancy-detail-skills" hidden></div>
          <div class="vacancy-description" id="vacancy-detail-description"></div>
        </div>
      </div>
    </div>
    <p class="vacancies-empty muted" id="vacancies-empty" hidden>Для этого резюме ещё нет скачанных вакансий в Core. Нажмите «Качать suitable».</p>
  </div>
</div>';
    }

    /** JSON кеша текстов для hover-модалки (без повторного Playwright). */
    private function resumeTextsJson(array $data): string
    {
        $map = [];
        $payload = is_array($data['resume_texts'] ?? null) ? $data['resume_texts'] : null;
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $this->str($row['external_id'] ?? null);
            $text = isset($row['text']) && is_string($row['text']) ? $row['text'] : '';
            $title = $this->str($row['title'] ?? null);
            if ($id === '' || $text === '') {
                continue;
            }
            $map[$id] = ['title' => $title, 'text' => $text];
        }

        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
        if ($json === false) {
            $json = '{}';
        }

        return '<script type="application/json" id="resume-texts-data">' . $json . '</script>';
    }

    private function cityModal(string $currentCity): string
    {
        $cities = ['Москва', 'Самара'];
        $chips = '';
        foreach ($cities as $city) {
            $active = mb_strtolower($currentCity) === mb_strtolower($city) ? ' is-active' : '';
            $chips .= '<button type="button" class="city-suggest' . $active . '" data-city="' . $this->e($city) . '">'
                . $this->e($city) . '</button>';
        }

        return '<div class="modal" id="city-modal" hidden>
  <div class="modal-backdrop js-close-city"></div>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="city-modal-title">
    <div class="modal-head">
      <h2 id="city-modal-title">Город</h2>
      <button type="button" class="modal-close js-close-city" aria-label="Закрыть">×</button>
    </div>
    <form class="city-form js-set-city" method="post" action="/?busy=set_city">
      <input type="hidden" name="action" value="set_city">
      <input id="city-input" type="hidden" name="city" value="' . $this->e($currentCity) . '" required>
      <div class="city-popular city-popular-only">' . $chips . '</div>
      <button type="submit" class="btn btn-primary">Выбрать</button>
    </form>
  </div>
</div>';
    }

    /** @param array<string, mixed> $data */
    private function cityLabel(array $data): string
    {
        /** @var array<string, mixed>|null $city */
        $city = is_array($data['city'] ?? null) ? $data['city'] : null;
        if ($city === null) {
            return '';
        }

        return $this->str($city['region_label'] ?? null);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
