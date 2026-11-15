#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Read-only анализ отказов vs откликов.
 *
 * Usage: php scripts/analysis/rejections_report.php [--out=docs/analysis/rejections_report.md]
 *
 * Только GET к Core + чтение data/cache. Ничего не пишет в HH/Core/кеш
 * (кроме целевого markdown-отчёта).
 */

use JobSearch\Infrastructure\Config\Env;
use JobSearch\Infrastructure\Core\CurlCoreClient;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$outPath = $root . '/docs/analysis/rejections_report.md';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outPath = $arg === '--out=' ? $outPath : substr($arg, 6);
        if ($outPath !== '' && $outPath[0] !== '/') {
            $outPath = $root . '/' . ltrim($outPath, '/');
        }
    }
}

$config = Env::load($root . '/.env');
$core = CurlCoreClient::fromConfig($config);
$cacheDir = $root . '/data/cache';
$generatedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i:s');

$applied = loadApplied($cacheDir . '/applied_by_vacancy.json');
$resumes = loadResumes($cacheDir . '/resumes.json');
$resumeTexts = loadResumeTexts($cacheDir . '/resume_texts.json');
$resumeViews = loadResumeViews($cacheDir . '/resume_views.json');
$vacancyCache = loadVacancyCache($cacheDir);
$chats = mergeChats(
    loadChatItems($cacheDir . '/chat_rejections.json'),
    loadChatItems($cacheDir . '/chat_history.json'),
    loadLiveChats($core),
);
$assessments = loadLatestAssessments($core);

$resumeIds = array_keys($resumes);
if ($resumeIds === []) {
    $resumeIds = array_values(array_unique(array_merge(
        array_column($applied, 'resume_external_id'),
        array_keys($resumeTexts),
    )));
}

// --- 1. Funnel ---
$funnel = [];
foreach ($resumeIds as $rid) {
    $title = $resumes[$rid]['title'] ?? ($resumeTexts[$rid]['title'] ?? $rid);
    $apps = [];
    foreach ($applied as $vid => $meta) {
        if (($meta['resume_external_id'] ?? '') === $rid) {
            $apps[$vid] = $meta;
        }
    }
    $viewedCompanies = companySet($resumeViews[$rid]['employers'] ?? []);
    $viewed = 0;
    $reject = 0;
    $interview = 0;
    $noAnswer = 0;
    $rejectNoView = 0;
    foreach ($apps as $vid => $meta) {
        $chat = $chats[$vid] ?? null;
        $state = normalizeState((string) ($chat['applicant_state'] ?? ''));
        $company = (string) ($chat['company_name'] ?? '');
        $wasViewed = companyMatched($company, $viewedCompanies);
        if ($wasViewed) {
            $viewed++;
        }
        if ($state === 'DISCARD') {
            $reject++;
            if (!$wasViewed) {
                $rejectNoView++;
            }
        } elseif (in_array($state, ['INTERVIEW', 'PHONE_INTERVIEW', 'INVITATION'], true)) {
            $interview++;
        } else {
            // RESPONSE / empty / unknown — «нет ответа» в смысле нет финального решения
            $noAnswer++;
        }
    }
    $n = count($apps);
    $funnel[$rid] = [
        'title' => $title,
        'applied' => $n,
        'viewed' => $viewed,
        'reject' => $reject,
        'interview' => $interview,
        'no_answer' => $noAnswer,
        'reject_no_view' => $rejectNoView,
        'pct_viewed' => pct($viewed, $n),
        'pct_reject' => pct($reject, $n),
        'pct_interview' => pct($interview, $n),
        'pct_no_answer' => pct($noAnswer, $n),
        'pct_reject_no_view' => pct($rejectNoView, $reject),
    ];
}

// --- 2. Time to rejection (trusted applied_at only) ---
$timeRows = [];
$fast1h = 0;
$fast10m = 0;
foreach ($applied as $vid => $meta) {
    if (($meta['source'] ?? '') === 'chatik') {
        continue;
    }
    $appliedAt = trim((string) ($meta['applied_at'] ?? ''));
    if ($appliedAt === '') {
        continue;
    }
    $chat = $chats[$vid] ?? null;
    if ($chat === null || normalizeState((string) ($chat['applicant_state'] ?? '')) !== 'DISCARD') {
        continue;
    }
    $rejAt = trim((string) ($chat['last_message_at'] ?: ($chat['last_activity_at'] ?? '')));
    if ($rejAt === '') {
        continue;
    }
    try {
        $t0 = new DateTimeImmutable($appliedAt);
        $t1 = new DateTimeImmutable($rejAt);
    } catch (Exception) {
        continue;
    }
    $sec = $t1->getTimestamp() - $t0->getTimestamp();
    if ($sec < 0) {
        continue; // часы/TZ артефакт — не считаем
    }
    $rid = (string) ($meta['resume_external_id'] ?? '');
    $timeRows[] = [
        'vacancy_id' => $vid,
        'resume_id' => $rid,
        'title' => $resumes[$rid]['title'] ?? ($meta['resume_title'] ?? $rid),
        'company' => (string) ($chat['company_name'] ?? ''),
        'vacancy_title' => (string) ($chat['vacancy_title'] ?? ''),
        'seconds' => $sec,
        'applied_at' => $appliedAt,
        'rejected_at' => $rejAt,
        'source' => (string) ($meta['source'] ?? ''),
    ];
    if ($sec <= 3600) {
        $fast1h++;
    }
    if ($sec <= 600) {
        $fast10m++;
    }
}
usort($timeRows, static fn (array $a, array $b): int => $a['seconds'] <=> $b['seconds']);
$timeTrustedN = count($timeRows);
$timeMedian = $timeTrustedN > 0 ? median(array_column($timeRows, 'seconds')) : null;

// --- 3. Reject without view (already in funnel; global) ---
$totalRejectNoView = array_sum(array_column($funnel, 'reject_no_view'));
$totalReject = array_sum(array_column($funnel, 'reject'));

// --- 4. Scoring vs outcome ---
$buckets = [
    'skip' => ['label' => 'verdict=skip', 'applied' => 0, 'reject' => 0, 'interview' => 0, 'response' => 0],
    'maybe' => ['label' => 'verdict=maybe', 'applied' => 0, 'reject' => 0, 'interview' => 0, 'response' => 0],
    'apply' => ['label' => 'verdict=apply', 'applied' => 0, 'reject' => 0, 'interview' => 0, 'response' => 0],
    'score_0_39' => ['label' => 'score 0–39', 'applied' => 0, 'reject' => 0, 'interview' => 0, 'response' => 0],
    'score_40_59' => ['label' => 'score 40–59', 'applied' => 0, 'reject' => 0, 'interview' => 0, 'response' => 0],
    'score_60_74' => ['label' => 'score 60–74', 'applied' => 0, 'reject' => 0, 'interview' => 0, 'response' => 0],
    'score_75_100' => ['label' => 'score 75–100', 'applied' => 0, 'reject' => 0, 'interview' => 0, 'response' => 0],
    'no_score' => ['label' => 'без assessment', 'applied' => 0, 'reject' => 0, 'interview' => 0, 'response' => 0],
];
$highRejectVids = [];
$interviewVids = [];
$misaligned = [];
$shouldSkip = [];

foreach ($applied as $vid => $meta) {
    $a = $assessments[$vid] ?? null;
    $chat = $chats[$vid] ?? null;
    $state = normalizeState((string) ($chat['applicant_state'] ?? 'RESPONSE'));
    if ($state === '') {
        $state = 'RESPONSE';
    }
    $outcome = match (true) {
        $state === 'DISCARD' => 'reject',
        in_array($state, ['INTERVIEW', 'PHONE_INTERVIEW', 'INVITATION'], true) => 'interview',
        default => 'response',
    };

    $keys = ['no_score'];
    if ($a !== null) {
        $verdict = strtolower(trim((string) ($a['verdict'] ?? '')));
        $score = (float) ($a['relevance_score'] ?? 0);
        $keys = [];
        if (isset($buckets[$verdict])) {
            $keys[] = $verdict;
        }
        if ($score < 40) {
            $keys[] = 'score_0_39';
        } elseif ($score < 60) {
            $keys[] = 'score_40_59';
        } elseif ($score < 75) {
            $keys[] = 'score_60_74';
        } else {
            $keys[] = 'score_75_100';
        }
        if (
            $outcome === 'reject'
            && (in_array($verdict, ['apply', 'maybe'], true) || $score >= 60)
        ) {
            $highRejectVids[$vid] = [
                'score' => $score,
                'verdict' => $verdict,
                'reason' => trim((string) ($a['reason'] ?? $a['detail']['reason'] ?? '')),
                'gaps' => $a['detail']['gaps'] ?? [],
                'resume_id' => (string) ($meta['resume_external_id'] ?? ''),
                'company' => (string) ($chat['company_name'] ?? ($a['vacancy']['company_name'] ?? '')),
                'title' => (string) ($chat['vacancy_title'] ?? ($a['vacancy']['title'] ?? '')),
            ];
        }
    }
    foreach ($keys as $k) {
        $buckets[$k]['applied']++;
        $buckets[$k][$outcome]++;
    }

    if ($outcome === 'interview') {
        $interviewVids[$vid] = [
            'resume_id' => (string) ($meta['resume_external_id'] ?? ''),
            'company' => (string) ($chat['company_name'] ?? ''),
            'title' => (string) ($chat['vacancy_title'] ?? ($a['vacancy']['title'] ?? '')),
            'score' => $a !== null ? (float) ($a['relevance_score'] ?? 0) : null,
            'verdict' => $a !== null ? strtolower((string) ($a['verdict'] ?? '')) : '',
        ];
    }

    $rid = (string) ($meta['resume_external_id'] ?? '');
    $rTitle = $resumes[$rid]['title'] ?? ($meta['resume_title'] ?? '');
    $vTitle = (string) ($chat['vacancy_title'] ?? ($a['vacancy']['title'] ?? ''));
    $mismatch = roleMismatch($rTitle, $vTitle);
    if ($mismatch !== null) {
        $misaligned[] = [
            'vacancy_id' => $vid,
            'resume' => $rTitle,
            'vacancy' => $vTitle,
            'reason' => $mismatch,
            'outcome' => $outcome,
            'verdict' => $a !== null ? strtolower((string) ($a['verdict'] ?? '')) : '',
        ];
    }

    $skipHit = policySkipHint($vTitle, (string) ($a['reason'] ?? ''), $a['detail'] ?? []);
    if ($skipHit !== null) {
        $shouldSkip[] = [
            'vacancy_id' => $vid,
            'resume' => $rTitle,
            'vacancy' => $vTitle,
            'policy' => $skipHit,
            'outcome' => $outcome,
            'verdict' => $a !== null ? strtolower((string) ($a['verdict'] ?? '')) : '',
            'score' => $a !== null ? (float) ($a['relevance_score'] ?? 0) : null,
        ];
    }
}

// --- 8. Auto-reject: |reject_at − nearest resume_view| < 10s ---
$viewTimesByResume = [];
foreach ($resumeViews as $rid => $block) {
    $map = [];
    $employers = is_array($block['employers'] ?? null) ? $block['employers'] : [];
    foreach ($employers as $emp) {
        if (!is_array($emp)) {
            continue;
        }
        $key = normalizeCompany((string) ($emp['company_name'] ?? ''));
        if ($key === '') {
            continue;
        }
        foreach ($emp['viewed_at'] ?? [] as $ts) {
            $ts = trim((string) $ts);
            if ($ts !== '') {
                $map[$key][] = $ts;
            }
        }
    }
    $viewTimesByResume[$rid] = $map;
}

/** @var list<array<string, mixed>> $autoRejects */
$autoRejects = [];
/** @var list<array<string, mixed>> $nearMinuteRejects */
$nearMinuteRejects = [];
/** @var array<string, array{reject: int, auto: int, near_minute: int, with_view: int}> $autoByResume */
$autoByResume = [];
foreach ($resumeIds as $rid) {
    $autoByResume[$rid] = [
        'reject' => (int) ($funnel[$rid]['reject'] ?? 0),
        'auto' => 0,
        'near_minute' => 0,
        'with_view' => 0,
    ];
}

$autoRejectNoTimestamp = 0;
foreach ($applied as $vid => $meta) {
    $chat = $chats[$vid] ?? null;
    if ($chat === null || normalizeState((string) ($chat['applicant_state'] ?? '')) !== 'DISCARD') {
        continue;
    }
    $rid = (string) ($meta['resume_external_id'] ?? '');
    if ($rid === '') {
        continue;
    }
    if (!isset($autoByResume[$rid])) {
        $autoByResume[$rid] = ['reject' => 0, 'auto' => 0, 'near_minute' => 0, 'with_view' => 0];
    }
    $company = (string) ($chat['company_name'] ?? '');
    $viewTimes = companyViewTimes($company, $viewTimesByResume[$rid] ?? []);
    if ($viewTimes === []) {
        continue;
    }
    $autoByResume[$rid]['with_view']++;
    $rejAt = trim((string) ($chat['last_message_at'] ?: ($chat['last_activity_at'] ?? '')));
    if ($rejAt === '') {
        $autoRejectNoTimestamp++;
        continue;
    }
    $nearest = nearestTimestampDelta($rejAt, $viewTimes);
    if ($nearest === null) {
        continue;
    }
    $row = [
        'vacancy_id' => $vid,
        'vacancy_title' => (string) ($chat['vacancy_title'] ?? ''),
        'company' => $company,
        'resume_id' => $rid,
        'resume_title' => $resumes[$rid]['title'] ?? ($meta['resume_title'] ?? $rid),
        'rejected_at' => $rejAt,
        'viewed_at' => $nearest['at'],
        'delta_sec' => $nearest['abs'],
        'delta_signed' => $nearest['signed'],
    ];
    // HH resume_views округляет до минуты (:00) — см. near_minute.
    if ($nearest['abs'] < 10) {
        $autoRejects[] = $row;
        $autoByResume[$rid]['auto']++;
    } elseif ($nearest['abs'] < 60) {
        $nearMinuteRejects[] = $row;
        $autoByResume[$rid]['near_minute']++;
    }
}
usort(
    $autoRejects,
    static fn (array $a, array $b): int => $a['delta_sec'] <=> $b['delta_sec']
        ?: strcmp((string) $a['company'], (string) $b['company']),
);
usort(
    $nearMinuteRejects,
    static fn (array $a, array $b): int => $a['delta_sec'] <=> $b['delta_sec'],
);

// --- 5–6 + 8: fetch vacancy texts/meta for high-reject, interview, auto-reject ---
$fetchIds = array_values(array_unique(array_merge(
    array_keys($highRejectVids),
    array_keys($interviewVids),
    array_column($autoRejects, 'vacancy_id'),
    array_column($nearMinuteRejects, 'vacancy_id'),
)));
/** @var array<string, string> $descriptions */
$descriptions = [];
/** @var array<string, array<string, mixed>> $vacancyDetails */
$vacancyDetails = [];
foreach ($fetchIds as $ext) {
    $cached = $vacancyCache[$ext] ?? null;
    if (is_array($cached)) {
        $desc = trim(strip_tags((string) ($cached['description'] ?? '')));
        if ($desc !== '') {
            $descriptions[$ext] = $desc;
        }
        $vacancyDetails[$ext] = [
            'description' => $desc,
            'salary_text' => trim((string) ($cached['salary_text'] ?? '')),
            'area_text' => trim((string) ($cached['area_text'] ?? '')),
            'work_format_text' => trim((string) ($cached['work_format_text'] ?? '')),
            'employment_text' => trim((string) ($cached['employment_text'] ?? '')),
            'experience_text' => trim((string) ($cached['experience_text'] ?? '')),
            'key_skills' => $cached['key_skills'] ?? [],
            'title' => trim((string) ($cached['title'] ?? '')),
        ];
    }
}
$missing = array_values(array_filter(
    $fetchIds,
    static fn (string $id): bool => !isset($descriptions[$id]) || trim($descriptions[$id]) === '',
));
$idMap = [];
foreach (array_chunk($missing, 50) as $chunk) {
    try {
        $idMap += $core->lookupVacancyIdsByExternalIds($chunk);
    } catch (Throwable $e) {
        fwrite(STDERR, 'lookupVacancyIds warn: ' . $e->getMessage() . "\n");
    }
}
$fetched = 0;
$fetchFail = 0;
foreach ($missing as $ext) {
    $uuid = $idMap[$ext] ?? null;
    if (!is_string($uuid) || $uuid === '') {
        $fetchFail++;
        continue;
    }
    try {
        $vac = $core->getVacancy($uuid);
        $desc = trim(strip_tags((string) ($vac['description'] ?? '')));
        if ($desc !== '') {
            $descriptions[$ext] = $desc;
            $fetched++;
        } else {
            $fetchFail++;
        }
        $vacancyDetails[$ext] = [
            'description' => $desc,
            'salary_text' => trim((string) ($vac['salary_text'] ?? '')),
            'area_text' => trim((string) ($vac['area_text'] ?? '')),
            'work_format_text' => trim((string) ($vac['work_format_text'] ?? '')),
            'employment_text' => trim((string) ($vac['employment_text'] ?? '')),
            'experience_text' => trim((string) ($vac['experience_text'] ?? '')),
            'key_skills' => $vac['key_skills'] ?? [],
            'title' => trim((string) ($vac['title'] ?? '')),
        ];
    } catch (Throwable) {
        $fetchFail++;
    }
}

// Filter signals for auto-rejects (<10s) + near-minute (<60s) as soft set
$autoFilterPool = array_merge($autoRejects, $nearMinuteRejects);
$autoFilterStats = summarizeAutoFilters($autoFilterPool, $vacancyDetails, $descriptions);

$rejectReqFreq = [];
$interviewReqFreq = [];
$rejectMissingVsResume = [];
$interviewOverlap = [];
$resumeTokenCache = [];

foreach ($highRejectVids as $vid => $info) {
    $desc = $descriptions[$vid] ?? '';
    if ($desc === '') {
        continue;
    }
    $reqs = extractRequirements($desc . "\n" . ($info['title'] ?? ''));
    foreach ($reqs as $term) {
        $rejectReqFreq[$term] = ($rejectReqFreq[$term] ?? 0) + 1;
    }
    $rid = $info['resume_id'];
    if (!isset($resumeTokenCache[$rid])) {
        $resumeTokenCache[$rid] = normalizeText($resumeTexts[$rid]['text'] ?? '');
    }
    $rText = $resumeTokenCache[$rid];
    foreach ($reqs as $term) {
        if (!termInText($term, $rText)) {
            $rejectMissingVsResume[$term] = ($rejectMissingVsResume[$term] ?? 0) + 1;
        }
    }
}

foreach ($interviewVids as $vid => $info) {
    $desc = $descriptions[$vid] ?? '';
    if ($desc === '') {
        continue;
    }
    $reqs = extractRequirements($desc . "\n" . ($info['title'] ?? ''));
    foreach ($reqs as $term) {
        $interviewReqFreq[$term] = ($interviewReqFreq[$term] ?? 0) + 1;
    }
    $rid = $info['resume_id'];
    if (!isset($resumeTokenCache[$rid])) {
        $resumeTokenCache[$rid] = normalizeText($resumeTexts[$rid]['text'] ?? '');
    }
    $rText = $resumeTokenCache[$rid];
    foreach ($reqs as $term) {
        if (termInText($term, $rText)) {
            $interviewOverlap[$term] = ($interviewOverlap[$term] ?? 0) + 1;
        }
    }
}

arsort($rejectMissingVsResume);
arsort($interviewOverlap);
arsort($rejectReqFreq);
arsort($interviewReqFreq);
$topMissing = array_slice($rejectMissingVsResume, 0, 20, true);
$topInterviewOverlap = array_slice($interviewOverlap, 0, 20, true);

// --- Per-resume edit suggestions ---
$edits = suggestEdits($funnel, $topMissing, $resumeTexts, $resumes, $highRejectVids, $descriptions);

// --- Build markdown ---
$md = [];
$md[] = '# Анализ отказов по откликам HH';
$md[] = '';
$md[] = 'Сгенерировано: **' . $generatedAt . '** (Europe/Moscow).';
$md[] = 'Скрипт: `scripts/analysis/rejections_report.php` (только чтение кеша + GET Core).';
$md[] = '';
$md[] = '## Главные выводы';
$md[] = '';

$totalApplied = array_sum(array_column($funnel, 'applied'));
$totalInterview = array_sum(array_column($funnel, 'interview'));
$md[] = '1. **Всего откликов в карте:** ' . $totalApplied
    . '; отказов (сопоставленных с откликом): ' . $totalReject
    . ' (' . pct($totalReject, $totalApplied) . '%); собеседований: ' . $totalInterview
    . ' (' . pct($totalInterview, $totalApplied) . '%).';

$bestResume = null;
$worstResume = null;
foreach ($funnel as $row) {
    if ($row['applied'] < 20) {
        continue;
    }
    if ($bestResume === null || $row['pct_reject'] < $bestResume['pct_reject']) {
        $bestResume = $row;
    }
    if ($worstResume === null || $row['pct_reject'] > $worstResume['pct_reject']) {
        $worstResume = $row;
    }
}
if ($bestResume && $worstResume) {
    $md[] = '2. **Доля отказов сильно зависит от резюме:** лучше всего «'
        . $bestResume['title'] . '» — ' . $bestResume['pct_reject'] . '% отказов ('
        . $bestResume['reject'] . '/' . $bestResume['applied'] . '); хуже «'
        . $worstResume['title'] . '» — ' . $worstResume['pct_reject'] . '% ('
        . $worstResume['reject'] . '/' . $worstResume['applied'] . ').';
}

$md[] = '3. **Время до отказа почти нельзя считать по истории:** доверенных пар '
    . '(`source≠chatik` + дата отказа) всего **' . $timeTrustedN . '**. '
    . ($timeTrustedN > 0
        ? 'Из них ≤10 мин: **' . $fast10m . '** (' . pct($fast10m, $timeTrustedN)
            . '%); ≤1 ч: **' . $fast1h . '** (' . pct($fast1h, $timeTrustedN)
            . '%); медиана: ' . formatDuration((int) $timeMedian) . '.'
        : 'Вывод про автофильтр по скорости — **недостаёт данных**.');

$md[] = '4. **Отказ без просмотра резюме:** ' . $totalRejectNoView . ' из ' . $totalReject
    . ' отказов (' . pct($totalRejectNoView, $totalReject)
    . '%) — компания из чата не найдена в `resume_views` этого резюме. '
    . 'Это **верхняя оценка** автофильтра/анкеты (имена компаний могут не совпасть 1-в-1).';

$applyBucket = $buckets['apply'];
$highBucket = $buckets['score_75_100'];
$md[] = '5. **Высокий скоринг не защищает от отказа:** при verdict=apply отказов '
    . $applyBucket['reject'] . '/' . $applyBucket['applied']
    . ' (' . pct($applyBucket['reject'], $applyBucket['applied']) . '%); '
    . 'в корзине score 75–100 — ' . $highBucket['reject'] . '/' . $highBucket['applied']
    . ' (' . pct($highBucket['reject'], $highBucket['applied']) . '%). '
    . 'Собеседований в apply: ' . $applyBucket['interview'] . '.';

$topMissTerms = array_slice(array_keys($topMissing), 0, 5);
$md[] = '6. **В вакансиях с высоким баллом, но отказом, чаще нет в резюме:** '
    . ($topMissTerms !== [] ? implode(', ', array_map(static fn ($t) => '«' . $t . '»', $topMissTerms)) : 'недостаточно текстов вакансий')
    . '. Текстов вакансий для анализа: ' . count($descriptions) . ' из ' . count($fetchIds)
    . ' (догружено из Core: ' . $fetched . ', не найдено: ' . $fetchFail . ').';

$md[] = '7. **Отклики «не туда» / спорный SKIP:** роль-mismatch — **' . count($misaligned)
    . '** откликов; по эвристике scoring-policy (должны были SKIP) — **'
    . count($shouldSkip) . '** (см. таблицы ниже; эвристика по заголовку/reason, не полный DevAI).';

$autoTotal = count($autoRejects);
$nearTotal = count($nearMinuteRejects);
$rejectWithView = array_sum(array_column($autoByResume, 'with_view'));
$autoWithTsSummary = max(0, $rejectWithView - $autoRejectNoTimestamp);
$md[] = '8. **Автоотказ (просмотр и отказ < 10 с):** **' . $autoTotal
    . '** строгих; ещё **' . $nearTotal . '** в той же минуте (Δ&lt;60 с, из‑за округления `resume_views`). '
    . 'Сравнимы только **' . $autoWithTsSummary . '** отказов (есть и просмотр компании, и время отказа); '
    . 'авто+минута = **' . ($autoTotal + $nearTotal) . '** ('
    . pct($autoTotal + $nearTotal, $autoWithTsSummary) . '% сравнимых). '
    . 'У многих DISCARD в архиве пустой `last_message_at` — Δ посчитать нельзя.';
$md[] = '';

// Funnel table
$md[] = '## 1. Воронка по резюме';
$md[] = '';
$md[] = '| Резюме | Отклики | Просмотр* | Отказ | Собес | Нет ответа | % отказ | % собес | Отказ без просмотра |';
$md[] = '|---|---:|---:|---:|---:|---:|---:|---:|---:|';
foreach ($funnel as $row) {
    $md[] = '| ' . mdCell($row['title'])
        . ' | ' . $row['applied']
        . ' | ' . $row['viewed'] . ' (' . $row['pct_viewed'] . '%)'
        . ' | ' . $row['reject'] . ' (' . $row['pct_reject'] . '%)'
        . ' | ' . $row['interview'] . ' (' . $row['pct_interview'] . '%)'
        . ' | ' . $row['no_answer'] . ' (' . $row['pct_no_answer'] . '%)'
        . ' | ' . $row['pct_reject'] . '%'
        . ' | ' . $row['pct_interview'] . '%'
        . ' | ' . $row['reject_no_view'] . ' (' . $row['pct_reject_no_view'] . '% отказов)'
        . ' |';
}
$md[] = '';
$md[] = '\\*Просмотр = компания из чата/отказа совпала (нормализованно) с работодателем из `resume_views` этого резюме. HH не всегда пишет одно и то же имя — возможны ложные «без просмотра».';
$md[] = '';
$md[] = '**Недостаток данных:** `resume_views` — снимок на момент синка, без гарантии полноты истории; чаты без `resume_external_id` привязаны через `applied_by_vacancy`.';
$md[] = '';

// Time table
$md[] = '## 2. Время до отказа (только доверенные даты)';
$md[] = '';
$md[] = 'Критерий: `applied_by_vacancy.source ≠ chatik` и есть `last_message_at`/`last_activity_at` отказа.';
$md[] = '';
$md[] = '| Метрика | Значение |';
$md[] = '|---|---|';
$md[] = '| Доверенных пар | ' . $timeTrustedN . ' |';
$md[] = '| ≤ 10 минут | ' . $fast10m . ' (' . pct($fast10m, $timeTrustedN) . '%) |';
$md[] = '| ≤ 1 час | ' . $fast1h . ' (' . pct($fast1h, $timeTrustedN) . '%) |';
$md[] = '| Медиана | ' . ($timeMedian !== null ? formatDuration((int) $timeMedian) : '—') . ' |';
$md[] = '';
if ($timeRows === []) {
    $md[] = '_Нет доверенных пар — вывод про автофильтр по скорости сделать нельзя._';
} else {
    $md[] = '| Вакансия | Компания | Резюме | Δ | source |';
    $md[] = '|---|---|---|---|---|';
    foreach (array_slice($timeRows, 0, 25) as $row) {
        $md[] = '| ' . mdCell($row['vacancy_title'] !== '' ? $row['vacancy_title'] : $row['vacancy_id'])
            . ' | ' . mdCell($row['company'])
            . ' | ' . mdCell($row['title'])
            . ' | ' . formatDuration((int) $row['seconds'])
            . ' | ' . mdCell($row['source'])
            . ' |';
    }
    if (count($timeRows) > 25) {
        $md[] = '';
        $md[] = '_…ещё ' . (count($timeRows) - 25) . ' пар в данных скрипта (в отчёт не выводились)._';
    }
}
$md[] = '';
$md[] = '**Недостаток данных:** ~90% `applied_at` — дата синка chatik (09.11). Для исторической скорости нужен бэкфилл реальной даты отклика.';
$md[] = '';

// Reject no view detail
$md[] = '## 3. Отказ без просмотра резюме';
$md[] = '';
$md[] = 'Всего отказов с откликом: **' . $totalReject . '**; без совпадения компании в `resume_views`: **'
    . $totalRejectNoView . '** (' . pct($totalRejectNoView, $totalReject) . '%).';
$md[] = '';
$md[] = '| Резюме | Отказы | Без просмотра | Доля |';
$md[] = '|---|---:|---:|---:|';
foreach ($funnel as $row) {
    $md[] = '| ' . mdCell($row['title'])
        . ' | ' . $row['reject']
        . ' | ' . $row['reject_no_view']
        . ' | ' . $row['pct_reject_no_view'] . '% |';
}
$md[] = '';

// Scoring buckets
$md[] = '## 4. Скоринг против исхода';
$md[] = '';
$md[] = 'База: отклики из `applied_by_vacancy`, исход из чатов (live ∪ архивы), assessment — последний по `assessed_at`.';
$md[] = '';
$md[] = '| Корзина | Откликов | Отказ | % отказ | Собес | % собес | Без решения |';
$md[] = '|---|---:|---:|---:|---:|---:|---:|';
foreach ($buckets as $row) {
    $n = $row['applied'];
    $md[] = '| ' . $row['label']
        . ' | ' . $n
        . ' | ' . $row['reject']
        . ' | ' . pct($row['reject'], $n) . '%'
        . ' | ' . $row['interview']
        . ' | ' . pct($row['interview'], $n) . '%'
        . ' | ' . $row['response']
        . ' |';
}
$md[] = '';
$md[] = 'Высокий балл / apply, но отказ — **' . count($highRejectVids) . '** вакансий (участвуют в анализе требований).';
$md[] = '';

// Keywords missing
$md[] = '## 5. Требования в high-score отказах, которых нет в резюме (топ-20)';
$md[] = '';
$md[] = 'Выборка: verdict ∈ {apply, maybe} или score ≥ 60, исход = отказ, есть текст вакансии.';
$md[] = '';
if ($topMissing === []) {
    $md[] = '_Недостаточно описаний вакансий для частотного анализа._';
} else {
    $md[] = '| # | Требование / термин | Вакансий (нет в резюме) |';
    $md[] = '|---:|---|---:|';
    $i = 1;
    foreach ($topMissing as $term => $cnt) {
        $md[] = '| ' . $i++ . ' | ' . mdCell((string) $term) . ' | ' . $cnt . ' |';
    }
}
$md[] = '';
$md[] = 'Частые требования в этих вакансиях (независимо от наличия в резюме), топ-15:';
$md[] = '';
$md[] = '| Термин | Частота |';
$md[] = '|---|---:|';
$i = 0;
foreach ($rejectReqFreq as $term => $cnt) {
    $md[] = '| ' . mdCell((string) $term) . ' | ' . $cnt . ' |';
    if (++$i >= 15) {
        break;
    }
}
$md[] = '';

// Interview overlap
$md[] = '## 6. Что совпадает с резюме у собеседований';
$md[] = '';
$md[] = 'Собеседований / приглашений в выборке: **' . count($interviewVids) . '**.';
$md[] = '';
if ($topInterviewOverlap === []) {
    $md[] = '_Мало текстов вакансий с собеседованием — частотный overlap ненадёжен._';
} else {
    $md[] = '| # | Термин из вакансии, есть в резюме | Вакансий |';
    $md[] = '|---:|---|---:|';
    $i = 1;
    foreach ($topInterviewOverlap as $term => $cnt) {
        $md[] = '| ' . $i++ . ' | ' . mdCell((string) $term) . ' | ' . $cnt . ' |';
    }
}
$md[] = '';
$md[] = '| Вакансия | Компания | Резюме | score | verdict |';
$md[] = '|---|---|---|---:|---|';
foreach ($interviewVids as $vid => $info) {
    $rid = $info['resume_id'];
    $md[] = '| ' . mdCell($info['title'] !== '' ? $info['title'] : $vid)
        . ' | ' . mdCell($info['company'])
        . ' | ' . mdCell($resumes[$rid]['title'] ?? $rid)
        . ' | ' . ($info['score'] !== null ? (string) $info['score'] : '—')
        . ' | ' . mdCell($info['verdict'] !== '' ? $info['verdict'] : '—')
        . ' |';
}
$md[] = '';

// Misaligned / should skip
$md[] = '## 7. Отклики «не туда» и спорный SKIP';
$md[] = '';
$md[] = '### 7.1. Роль резюме ≠ роль вакансии (эвристика по заголовкам)';
$md[] = '';
if ($misaligned === []) {
    $md[] = '_Явных mismatch по заголовкам не найдено (или мало данных в чатах)._';
} else {
    $md[] = '| Вакансия | Резюме | Почему | Исход | verdict |';
    $md[] = '|---|---|---|---|---|';
    foreach (array_slice($misaligned, 0, 40) as $row) {
        $md[] = '| ' . mdCell($row['vacancy'])
            . ' | ' . mdCell($row['resume'])
            . ' | ' . mdCell($row['reason'])
            . ' | ' . $row['outcome']
            . ' | ' . mdCell($row['verdict'] !== '' ? $row['verdict'] : '—')
            . ' |';
    }
    if (count($misaligned) > 40) {
        $md[] = '';
        $md[] = '_…ещё ' . (count($misaligned) - 40) . '._';
    }
}
$md[] = '';
$md[] = '### 7.2. Похоже на жёсткий SKIP из scoring-policy (эвристика)';
$md[] = '';
$md[] = 'Сверка по заголовку вакансии + reason assessment (не полный прогон DevAI).';
$md[] = '';
if ($shouldSkip === []) {
    $md[] = '_Эвристика ничего не поймала среди откликов._';
} else {
    $md[] = '| Вакансия | Резюме | Правило | Исход | verdict | score |';
    $md[] = '|---|---|---|---|---|---:|';
    foreach (array_slice($shouldSkip, 0, 40) as $row) {
        $md[] = '| ' . mdCell($row['vacancy'])
            . ' | ' . mdCell($row['resume'])
            . ' | ' . mdCell($row['policy'])
            . ' | ' . $row['outcome']
            . ' | ' . mdCell($row['verdict'] !== '' ? $row['verdict'] : '—')
            . ' | ' . ($row['score'] !== null ? (string) $row['score'] : '—')
            . ' |';
    }
    if (count($shouldSkip) > 40) {
        $md[] = '';
        $md[] = '_…ещё ' . (count($shouldSkip) - 40) . '._';
    }
}
$md[] = '';

// --- 8. Auto-reject ---
$md[] = '## 8. Автоотказ: просмотр и отказ почти одновременно';
$md[] = '';
$md[] = 'Правило: для отказа берём `last_message_at` (иначе `last_activity_at`), ищем просмотры '
    . 'той же компании в `resume_views` этого резюме, берём ближайший `viewed_at`. '
    . 'Если |Δ| **&lt; 10 секунд** — считаем автоотказом.';
$md[] = '';
$md[] = '**Важно про данные:** HH в истории просмотров отдаёт время с точностью до **минуты** '
    . '(`…:00`). Отказ же с миллисекундами. Поэтому часть явных автоотказов попадает в корзину '
    . '«та же минута» (Δ 10–59 с), а не в &lt;10 с. Ниже обе корзины.';
$md[] = '';
$md[] = '| Метрика | Значение |';
$md[] = '|---|---|';
$md[] = '| Отказов всего | ' . $totalReject . ' |';
$autoWithTs = $rejectWithView - $autoRejectNoTimestamp;
$md[] = '| Отказов с найденным просмотром компании | ' . $rejectWithView . ' |';
$md[] = '| … из них без времени отказа (нельзя считать Δ) | ' . $autoRejectNoTimestamp . ' |';
$md[] = '| … из них с временем (сравнимы) | ' . max(0, $autoWithTs) . ' |';
$md[] = '| Авто (Δ&lt;10 с) | ' . $autoTotal . ' (' . pct($autoTotal, $totalReject)
    . '% всех / ' . pct($autoTotal, max(0, $autoWithTs)) . '% сравнимых) |';
$md[] = '| Та же минута (10≤Δ&lt;60 с) | ' . $nearTotal . ' (' . pct($nearTotal, $totalReject)
    . '% всех / ' . pct($nearTotal, max(0, $autoWithTs)) . '% сравнимых) |';
$md[] = '| Авто + та же минута | ' . ($autoTotal + $nearTotal) . ' ('
    . pct($autoTotal + $nearTotal, $totalReject) . '% всех / '
    . pct($autoTotal + $nearTotal, max(0, $autoWithTs)) . '% сравнимых) |';
$md[] = '';

$md[] = '### 8.1. Доля по резюме';
$md[] = '';
$md[] = '| Резюме | Отказы | С просмотром | Авто &lt;10с | % авто/отказы | % авто/с просмотром | Та же минута |';
$md[] = '|---|---:|---:|---:|---:|---:|---:|';
foreach ($autoByResume as $rid => $row) {
    $title = $resumes[$rid]['title'] ?? ($funnel[$rid]['title'] ?? $rid);
    $rejN = (int) $row['reject'];
    $with = (int) $row['with_view'];
    $autoN = (int) $row['auto'];
    $nearN = (int) $row['near_minute'];
    $md[] = '| ' . mdCell((string) $title)
        . ' | ' . $rejN
        . ' | ' . $with
        . ' | ' . $autoN
        . ' | ' . pct($autoN, $rejN) . '%'
        . ' | ' . pct($autoN, $with) . '%'
        . ' | ' . $nearN
        . ' |';
}
$md[] = '';

$md[] = '### 8.2. Компании и вакансии — авто (Δ&lt;10 с)';
$md[] = '';
if ($autoRejects === []) {
    $md[] = '_Строгих совпадений &lt;10 с нет (см. корзину «та же минута» ниже)._';
} else {
    $md[] = '| Компания | Вакансия | Резюме | Отказ | Просмотр | Δ |';
    $md[] = '|---|---|---|---|---|---:|';
    foreach ($autoRejects as $row) {
        $md[] = '| ' . mdCell((string) $row['company'])
            . ' | ' . mdCell((string) ($row['vacancy_title'] !== '' ? $row['vacancy_title'] : $row['vacancy_id']))
            . ' | ' . mdCell((string) $row['resume_title'])
            . ' | ' . mdCell(shortIso((string) $row['rejected_at']))
            . ' | ' . mdCell(shortIso((string) $row['viewed_at']))
            . ' | ' . (int) $row['delta_sec'] . ' с'
            . ' |';
    }
}
$md[] = '';

$md[] = '### 8.3. Компании и вакансии — та же минута (10≤Δ&lt;60 с)';
$md[] = '';
$md[] = 'Вероятные автоотказы с учётом округления `resume_views` до минуты.';
$md[] = '';
if ($nearMinuteRejects === []) {
    $md[] = '_Пусто._';
} else {
    $md[] = '| Компания | Вакансия | Резюме | Отказ | Просмотр | Δ |';
    $md[] = '|---|---|---|---|---|---:|';
    foreach ($nearMinuteRejects as $row) {
        $md[] = '| ' . mdCell((string) $row['company'])
            . ' | ' . mdCell((string) ($row['vacancy_title'] !== '' ? $row['vacancy_title'] : $row['vacancy_id']))
            . ' | ' . mdCell((string) $row['resume_title'])
            . ' | ' . mdCell(shortIso((string) $row['rejected_at']))
            . ' | ' . mdCell(shortIso((string) $row['viewed_at']))
            . ' | ' . (int) $row['delta_sec'] . ' с'
            . ' |';
    }
}
$md[] = '';

$md[] = '### 8.4. Требования / фильтры в автоотказах (авто + та же минута)';
$md[] = '';
$md[] = 'Выборка: ' . count($autoFilterPool) . ' вакансий. Тексты/поля есть у '
    . (int) ($autoFilterStats['with_text'] ?? 0) . '.';
$md[] = '';
$md[] = 'Именно эти поля чаще всего режут на HH до живого чтения: опыт, обязательные навыки, '
    . 'формат, город, вилка ЗП, вопросы анкеты.';
$md[] = '';

$md[] = '#### Опыт (годы)';
$md[] = '';
$expFreq = $autoFilterStats['experience'] ?? [];
if ($expFreq === []) {
    $md[] = '_Не удалось извлечь опыт из полей/текста._';
} else {
    $md[] = '| Формулировка опыта | Вакансий |';
    $md[] = '|---|---:|';
    foreach ($expFreq as $label => $cnt) {
        $md[] = '| ' . mdCell((string) $label) . ' | ' . $cnt . ' |';
    }
}
$md[] = '';

$md[] = '#### Обязательные навыки / стек';
$md[] = '';
$skillFreq = $autoFilterStats['skills'] ?? [];
if ($skillFreq === []) {
    $md[] = '_Мало key_skills / терминов в текстах._';
} else {
    $md[] = '| Навык | Вакансий |';
    $md[] = '|---|---:|';
    $i = 0;
    foreach ($skillFreq as $label => $cnt) {
        $md[] = '| ' . mdCell((string) $label) . ' | ' . $cnt . ' |';
        if (++$i >= 25) {
            break;
        }
    }
}
$md[] = '';

$md[] = '#### Формат работы';
$md[] = '';
$fmtFreq = $autoFilterStats['format'] ?? [];
if ($fmtFreq === []) {
    $md[] = '_Нет work_format / упоминаний remote/офис/гибрид._';
} else {
    $md[] = '| Формат | Вакансий |';
    $md[] = '|---|---:|';
    foreach ($fmtFreq as $label => $cnt) {
        $md[] = '| ' . mdCell((string) $label) . ' | ' . $cnt . ' |';
    }
}
$md[] = '';

$md[] = '#### Город / локация';
$md[] = '';
$areaFreq = $autoFilterStats['area'] ?? [];
if ($areaFreq === []) {
    $md[] = '_Нет area_text / города в тексте._';
} else {
    $md[] = '| Локация | Вакансий |';
    $md[] = '|---|---:|';
    foreach ($areaFreq as $label => $cnt) {
        $md[] = '| ' . mdCell((string) $label) . ' | ' . $cnt . ' |';
    }
}
$md[] = '';

$md[] = '#### Зарплата';
$md[] = '';
$salFreq = $autoFilterStats['salary'] ?? [];
if ($salFreq === []) {
    $md[] = '_В выборке нет salary_text (часто скрыта до отклика)._';
} else {
    $md[] = '| Вилка / пометка | Вакансий |';
    $md[] = '|---|---:|';
    foreach ($salFreq as $label => $cnt) {
        $md[] = '| ' . mdCell((string) $label) . ' | ' . $cnt . ' |';
    }
}
$md[] = '';

$md[] = '#### Вопросы анкеты / скрининг (из текста)';
$md[] = '';
$qFreq = $autoFilterStats['questions'] ?? [];
if ($qFreq === []) {
    $md[] = '_В описаниях явных вопросов анкеты не извлечено (или текстов мало). '
        . 'Сами ответы анкеты HH в кеше отклика не хранятся — только `screening_answers_learned` '
        . 'как общая база, без привязки к этим отказам._';
} else {
    $md[] = '| Фрагмент вопроса | Вакансий |';
    $md[] = '|---|---:|';
    $i = 0;
    foreach ($qFreq as $label => $cnt) {
        $md[] = '| ' . mdCell((string) $label) . ' | ' . $cnt . ' |';
        if (++$i >= 20) {
            break;
        }
    }
}
$md[] = '';
$md[] = '**Недостаток данных по п.8:** нет employer_id в `resume_views` (только имя); '
    . 'нет факта «анкета не пройдена» отдельно от DISCARD; время просмотра с точностью до минуты.';
$md[] = '';

// Edits
$md[] = '## Правки в резюме (черновик формулировок)';
$md[] = '';
$md[] = 'Только то, что следует из частот требований и уже есть (или явно отсутствует) в текстах резюме. Без выдуманного опыта.';
$md[] = '';
foreach ($edits as $block) {
    $md[] = '### ' . $block['title'];
    $md[] = '';
    if ($block['notes'] !== []) {
        foreach ($block['notes'] as $note) {
            $md[] = '- ' . $note;
        }
        $md[] = '';
    }
    if ($block['edits'] === []) {
        $md[] = '_Недостаточно сигналов для конкретных правок._';
        $md[] = '';
        continue;
    }
    $md[] = '| Было (сейчас в резюме / пробел) | Стало (предложение) |';
    $md[] = '|---|---|';
    foreach ($block['edits'] as $e) {
        $md[] = '| ' . mdCell($e['was']) . ' | ' . mdCell($e['become']) . ' |';
    }
    $md[] = '';
}

$md[] = '## Где данных не хватило';
$md[] = '';
$md[] = '- Реальная дата отклика для `source=chatik` (~90% карты) — нельзя считать скорость отказа по истории.';
$md[] = '- Описания вакансий: запрошено ' . count($fetchIds) . ', получено ' . count($descriptions)
    . ' (Core miss/fail: ' . $fetchFail . ').';
$md[] = '- Сопоставление компаний view↔reject — по нормализованному имени, не по HH employer id.';
$md[] = '- `resume_views` не гарантирует полную историю просмотров; время просмотра — с точностью до минуты.';
$md[] = '- П.7 — эвристики по заголовкам/reason, не замена DevAI + scoring-policy.';
$md[] = '- П.8: порог &lt;10 с на минутных `viewed_at` занижает автоотказы; смотреть также Δ&lt;60 с.';
$md[] = '- В chatik `negotiation_topics` нет created_at — бэкфилл даты отклика этим списком невозможен.';
$md[] = '- Ответы анкеты работодателя по конкретным автоотказам в кеше не привязаны.';
$md[] = '';
$md[] = '## Источники';
$md[] = '';
$md[] = '- `data/cache/applied_by_vacancy.json`, `chat_rejections.json`, `chat_history.json`, `resume_views.json`, `resume_texts.json`, `resumes.json`, `vacancies_*.json`';
$md[] = '- Core GET `/api/v1/hh-chats`, `/api/v1/assessments`, `/api/v1/vacancies/{id}` (lookup by external_id)';
$md[] = '- Политика: `docs/scoring-policy.md`';
$md[] = '';

$dir = dirname($outPath);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}
if (file_put_contents($outPath, implode("\n", $md) . "\n") === false) {
    fwrite(STDERR, "Cannot write {$outPath}\n");
    exit(1);
}

echo "Wrote {$outPath}\n";
echo "applied={$totalApplied} reject={$totalReject} interview={$totalInterview}"
    . " trusted_time={$timeTrustedN} high_reject=" . count($highRejectVids)
    . " auto_reject={$autoTotal} near_minute={$nearTotal}\n";
exit(0);

// ----------------- helpers -----------------

/** @return array<string, array{resume_external_id: string, resume_title: string, applied_at: string, source: string}> */
function loadApplied(string $path): array
{
    $payload = readCachePayload($path);
    $out = [];
    if (!is_array($payload)) {
        return [];
    }
    foreach ($payload as $vid => $row) {
        if (!is_array($row)) {
            continue;
        }
        $vid = trim((string) $vid);
        $rid = trim((string) ($row['resume_external_id'] ?? ''));
        if ($vid === '' || $rid === '') {
            continue;
        }
        $out[$vid] = [
            'resume_external_id' => $rid,
            'resume_title' => trim((string) ($row['resume_title'] ?? '')),
            'applied_at' => trim((string) ($row['applied_at'] ?? '')),
            'source' => trim((string) ($row['source'] ?? '')),
        ];
    }

    return $out;
}

/** @return array<string, array{title: string}> */
function loadResumes(string $path): array
{
    $payload = readCachePayload($path);
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['external_id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $out[$id] = ['title' => trim((string) ($row['title'] ?? ''))];
    }

    return $out;
}

/** @return array<string, array{title: string, text: string}> */
function loadResumeTexts(string $path): array
{
    $payload = readCachePayload($path);
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['external_id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $out[$id] = [
            'title' => trim((string) ($row['title'] ?? '')),
            'text' => (string) ($row['text'] ?? ''),
        ];
    }

    return $out;
}

/** @return array<string, array{synced_at?: string, employers?: list<array<string, mixed>>}> */
function loadResumeViews(string $path): array
{
    $payload = readCachePayload($path);

    return is_array($payload) ? $payload : [];
}

/** @return array<string, array<string, mixed>> */
function loadVacancyCache(string $cacheDir): array
{
    $out = [];
    foreach (glob($cacheDir . '/vacancies_*.json') ?: [] as $file) {
        $payload = readCachePayload($file);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : (is_array($payload) ? $payload : []);
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ext = trim((string) ($row['external_id'] ?? ''));
            if ($ext === '') {
                continue;
            }
            $out[$ext] = $row;
        }
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function loadChatItems(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $payload = readCachePayload($path);
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

    return array_values(array_filter($items, 'is_array'));
}

/** @return list<array<string, mixed>> */
function loadLiveChats(CurlCoreClient $core): array
{
    try {
        $payload = $core->listHhChats();
    } catch (Throwable $e) {
        fwrite(STDERR, 'listHhChats warn: ' . $e->getMessage() . "\n");

        return [];
    }
    $items = $payload['items'] ?? [];

    return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
}

/**
 * @param list<array<string, mixed>> ...$batches
 * @return array<string, array<string, mixed>> vacancy_external_id → richest chat row
 */
function mergeChats(array ...$batches): array
{
    /** @var array<string, array<string, mixed>> $byVac */
    $byVac = [];
    /** @var array<string, array<string, mixed>> $byChat */
    $byChat = [];
    foreach ($batches as $batch) {
        foreach ($batch as $row) {
            $chatId = trim((string) ($row['chat_id'] ?? ''));
            $vid = trim((string) ($row['vacancy_external_id'] ?? ''));
            if ($chatId !== '') {
                $prev = $byChat[$chatId] ?? null;
                $byChat[$chatId] = preferChat($prev, $row);
            }
            if ($vid !== '') {
                $prev = $byVac[$vid] ?? null;
                $byVac[$vid] = preferChat($prev, $row);
            }
        }
    }
    // chat-id rows without vacancy still ignored for applied join
    return $byVac;
}

/** @param array<string, mixed>|null $a */
function preferChat(?array $a, array $b): array
{
    if ($a === null) {
        return $b;
    }
    $rank = static function (array $r): int {
        $s = normalizeState((string) ($r['applicant_state'] ?? ''));

        return match ($s) {
            'INTERVIEW', 'PHONE_INTERVIEW', 'INVITATION' => 3,
            'DISCARD', 'DISCARDED' => 2,
            'RESPONSE', 'CONSIDER' => 1,
            default => 0,
        };
    };
    $eventAt = static function (array $r): string {
        return trim((string) (($r['last_message_at'] ?? '') ?: ($r['last_activity_at'] ?? '')));
    };
    if ($rank($b) > $rank($a)) {
        $merged = $b + $a;
    } elseif ($rank($b) < $rank($a)) {
        $merged = $a + $b;
    } else {
        $atA = $eventAt($a);
        $atB = $eventAt($b);
        // Предпочитаем сторону с непустым временем события (в архиве часто пусто).
        if ($atA === '' && $atB !== '') {
            $merged = $b + $a;
        } elseif ($atB === '' && $atA !== '') {
            $merged = $a + $b;
        } else {
            $merged = $atB >= $atA ? ($b + $a) : ($a + $b);
        }
    }
    foreach (['resume_external_id', 'resume_title', 'company_name', 'vacancy_title', 'last_message_at', 'last_activity_at'] as $k) {
        if (trim((string) ($merged[$k] ?? '')) === '') {
            if (trim((string) ($a[$k] ?? '')) !== '') {
                $merged[$k] = $a[$k];
            } elseif (trim((string) ($b[$k] ?? '')) !== '') {
                $merged[$k] = $b[$k];
            }
        }
    }

    return $merged;
}

/** @return array<string, array<string, mixed>> */
function loadLatestAssessments(CurlCoreClient $core): array
{
    try {
        $payload = $core->listAssessments(null, 200);
    } catch (Throwable $e) {
        fwrite(STDERR, 'listAssessments warn: ' . $e->getMessage() . "\n");

        return [];
    }
    $best = [];
    foreach ($payload['items'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $vac = is_array($row['vacancy'] ?? null) ? $row['vacancy'] : [];
        $ext = trim((string) ($vac['external_id'] ?? ''));
        if ($ext === '') {
            continue;
        }
        $at = trim((string) ($row['assessed_at'] ?? $row['created_at'] ?? ''));
        $prev = $best[$ext] ?? null;
        if ($prev === null || $at >= (string) ($prev['assessed_at'] ?? '')) {
            $best[$ext] = $row;
        }
    }

    return $best;
}

function readCachePayload(string $path): mixed
{
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }

    return $json['payload'] ?? $json;
}

function normalizeState(string $state): string
{
    $s = strtoupper(trim($state));
    if ($s === 'DISCARDED') {
        return 'DISCARD';
    }

    return $s;
}

/** @param list<array<string, mixed>> $employers @return array<string, true> */
function companySet(array $employers): array
{
    $set = [];
    foreach ($employers as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = normalizeCompany((string) ($row['company_name'] ?? ''));
        if ($name !== '') {
            $set[$name] = true;
        }
    }

    return $set;
}

function companyMatched(string $company, array $viewSet): bool
{
    $n = normalizeCompany($company);
    if ($n === '' || $viewSet === []) {
        return false;
    }
    if (isset($viewSet[$n])) {
        return true;
    }
    foreach ($viewSet as $v => $_) {
        if ($v === '') {
            continue;
        }
        if (str_contains($n, $v) || str_contains($v, $n)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, list<string>> $viewMap company_norm → timestamps
 * @return list<string>
 */
function companyViewTimes(string $company, array $viewMap): array
{
    $n = normalizeCompany($company);
    if ($n === '' || $viewMap === []) {
        return [];
    }
    $times = [];
    if (isset($viewMap[$n])) {
        $times = $viewMap[$n];
    }
    foreach ($viewMap as $v => $list) {
        if ($v === '' || $v === $n) {
            continue;
        }
        if (str_contains($n, $v) || str_contains($v, $n)) {
            foreach ($list as $ts) {
                $times[] = $ts;
            }
        }
    }

    return array_values(array_unique($times));
}

/**
 * @param list<string> $timestamps
 * @return array{at: string, abs: int, signed: int}|null
 */
function nearestTimestampDelta(string $pivotIso, array $timestamps): ?array
{
    try {
        $pivot = (new DateTimeImmutable($pivotIso))->getTimestamp();
    } catch (Exception) {
        return null;
    }
    $bestAt = null;
    $bestAbs = null;
    $bestSigned = null;
    foreach ($timestamps as $ts) {
        try {
            $t = (new DateTimeImmutable($ts))->getTimestamp();
        } catch (Exception) {
            continue;
        }
        $signed = $pivot - $t;
        $abs = abs($signed);
        if ($bestAbs === null || $abs < $bestAbs) {
            $bestAbs = $abs;
            $bestSigned = $signed;
            $bestAt = $ts;
        }
    }
    if ($bestAt === null || $bestAbs === null || $bestSigned === null) {
        return null;
    }

    return ['at' => $bestAt, 'abs' => $bestAbs, 'signed' => $bestSigned];
}

function shortIso(string $iso): string
{
    try {
        return (new DateTimeImmutable($iso))
            ->setTimezone(new DateTimeZone('Europe/Moscow'))
            ->format('d.m.Y H:i:s');
    } catch (Exception) {
        return $iso;
    }
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<string, array<string, mixed>> $details
 * @param array<string, string> $descriptions
 * @return array{
 *   with_text: int,
 *   experience: array<string, int>,
 *   skills: array<string, int>,
 *   format: array<string, int>,
 *   area: array<string, int>,
 *   salary: array<string, int>,
 *   questions: array<string, int>
 * }
 */
function summarizeAutoFilters(array $rows, array $details, array $descriptions): array
{
    $experience = [];
    $skills = [];
    $format = [];
    $area = [];
    $salary = [];
    $questions = [];
    $withText = 0;
    $seenVac = [];

    foreach ($rows as $row) {
        $vid = (string) ($row['vacancy_id'] ?? '');
        if ($vid === '' || isset($seenVac[$vid])) {
            continue;
        }
        $seenVac[$vid] = true;
        $meta = $details[$vid] ?? [];
        $desc = (string) ($descriptions[$vid] ?? ($meta['description'] ?? ''));
        if ($desc !== '' || $meta !== []) {
            $withText++;
        }

        $exp = trim((string) ($meta['experience_text'] ?? ''));
        if ($exp === '') {
            $exp = extractExperienceLabel($desc);
        }
        if ($exp !== '') {
            $experience[$exp] = ($experience[$exp] ?? 0) + 1;
        }

        $skillsList = normalizeSkillList($meta['key_skills'] ?? []);
        if ($skillsList === [] && $desc !== '') {
            $skillsList = extractRequirements($desc . "\n" . (string) ($row['vacancy_title'] ?? ''));
        }
        foreach ($skillsList as $sk) {
            $skills[$sk] = ($skills[$sk] ?? 0) + 1;
        }

        $fmt = trim((string) ($meta['work_format_text'] ?? ''));
        if ($fmt === '') {
            $fmt = extractWorkFormat($desc);
        }
        if ($fmt !== '') {
            $format[$fmt] = ($format[$fmt] ?? 0) + 1;
        }

        $ar = trim((string) ($meta['area_text'] ?? ''));
        if ($ar === '') {
            $ar = extractArea($desc);
        }
        if ($ar !== '') {
            $area[$ar] = ($area[$ar] ?? 0) + 1;
        }

        $sal = trim((string) ($meta['salary_text'] ?? ''));
        if ($sal === '') {
            $sal = extractSalary($desc);
        }
        if ($sal !== '') {
            $salary[$sal] = ($salary[$sal] ?? 0) + 1;
        }

        foreach (extractScreeningQuestions($desc) as $q) {
            $questions[$q] = ($questions[$q] ?? 0) + 1;
        }
    }

    arsort($experience);
    arsort($skills);
    arsort($format);
    arsort($area);
    arsort($salary);
    arsort($questions);

    return [
        'with_text' => $withText,
        'experience' => $experience,
        'skills' => $skills,
        'format' => $format,
        'area' => $area,
        'salary' => $salary,
        'questions' => $questions,
    ];
}

/** @param mixed $skills @return list<string> */
function normalizeSkillList(mixed $skills): array
{
    if (!is_array($skills)) {
        return [];
    }
    $out = [];
    foreach ($skills as $item) {
        if (is_string($item)) {
            $name = trim($item);
        } elseif (is_array($item)) {
            $name = trim((string) ($item['name'] ?? $item['title'] ?? ''));
        } else {
            continue;
        }
        if ($name !== '') {
            $out[] = $name;
        }
    }

    return array_values(array_unique($out));
}

function extractExperienceLabel(string $desc): string
{
    $t = normalizeText($desc);
    if (preg_match('/опыт[а]?[^\n.]{0,40}?(\d+)\s*\+?\s*(лет|года|год)/u', $t, $m) === 1) {
        return 'от ' . $m[1] . '+ лет (из текста)';
    }
    if (preg_match('/(\d+)\s*\+?\s*(лет|года|год)[^\n.]{0,20}опыт/u', $t, $m) === 1) {
        return 'от ' . $m[1] . '+ лет (из текста)';
    }
    if (str_contains($t, 'без опыта') || str_contains($t, 'опыт не требуется')) {
        return 'без опыта';
    }

    return '';
}

function extractWorkFormat(string $desc): string
{
    $t = normalizeText($desc);
    $bits = [];
    if (preg_match('/\b(удал[её]нк\w*|remote|удаленная работа)\b/u', $t) === 1) {
        $bits[] = 'удалёнка';
    }
    if (preg_match('/\b(гибрид\w*|hybrid)\b/u', $t) === 1) {
        $bits[] = 'гибрид';
    }
    if (preg_match('/\b(офис\w*|на территории работодателя)\b/u', $t) === 1) {
        $bits[] = 'офис';
    }

    return implode(' / ', $bits);
}

function extractArea(string $desc): string
{
    $t = normalizeText($desc);
    foreach (['москва', 'санкт-петербург', 'новосибирск', 'екатеринбург', 'казань', 'минск', 'алматы'] as $city) {
        if (str_contains($t, $city)) {
            return mb_convert_case($city, MB_CASE_TITLE, 'UTF-8');
        }
    }

    return '';
}

function extractSalary(string $desc): string
{
    if (preg_match('/(\d[\d\s]{2,8})\s*(?:[-–—]\s*(\d[\d\s]{2,8}))?\s*(₽|руб|rub)/ui', $desc, $m) === 1) {
        $a = preg_replace('/\s+/', '', $m[1]) ?? $m[1];
        $b = isset($m[2]) && $m[2] !== '' ? (preg_replace('/\s+/', '', $m[2]) ?? $m[2]) : '';

        return $b !== '' ? ($a . '–' . $b . ' ₽') : ('от ' . $a . ' ₽');
    }

    return '';
}

/** @return list<string> */
function extractScreeningQuestions(string $desc): array
{
    if ($desc === '') {
        return [];
    }
    $out = [];
    // Строки с «?» или типичные анкетные маркеры HH.
    foreach (preg_split('/\r\n|\n|\r/', $desc) ?: [] as $line) {
        $line = trim(html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($line === '' || mb_strlen($line) < 8 || mb_strlen($line) > 180) {
            continue;
        }
        $low = normalizeText($line);
        $looksLikeQ = str_contains($line, '?')
            || str_starts_with($low, 'укажите')
            || str_starts_with($low, 'готовы ли')
            || str_starts_with($low, 'есть ли')
            || str_starts_with($low, 'какой у вас')
            || str_starts_with($low, 'сколько лет')
            || (str_contains($low, 'обязательн') && str_contains($low, 'опыт'));
        if (!$looksLikeQ) {
            continue;
        }
        $out[$line] = true;
        if (count($out) >= 30) {
            break;
        }
    }

    return array_keys($out);
}

function normalizeCompany(string $name): string
{
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = str_replace(['ё', '«', '»', '"', "'"], ['е', '', '', '', ''], $s);
    $s = preg_replace('/\b(ооо|ао|пао|зао|ип|оао|llc|ltd|inc|gmbh)\b/u', '', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9а-я]+/u', ' ', $s) ?? $s;
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

    return $s;
}

function pct(int|float $part, int|float $whole): float
{
    if ($whole <= 0) {
        return 0.0;
    }

    return round(100 * $part / $whole, 1);
}

/** @param list<int|float> $values */
function median(array $values): float
{
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return 0.0;
    }
    $mid = intdiv($n, 2);
    if ($n % 2 === 1) {
        return (float) $values[$mid];
    }

    return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
}

function formatDuration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . ' с';
    }
    if ($seconds < 3600) {
        return (int) round($seconds / 60) . ' мин';
    }
    if ($seconds < 86400) {
        $h = intdiv($seconds, 3600);
        $m = (int) round(($seconds % 3600) / 60);

        return $h . ' ч' . ($m > 0 ? ' ' . $m . ' мин' : '');
    }
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);

    return $d . ' д' . ($h > 0 ? ' ' . $h . ' ч' : '');
}

function mdCell(string $value): string
{
    $v = str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], trim($value));

    return $v !== '' ? $v : '—';
}

function normalizeText(string $text): string
{
    $s = mb_strtolower($text, 'UTF-8');
    $s = str_replace('ё', 'е', $s);

    return $s;
}

function termInText(string $term, string $normalizedResume): bool
{
    $t = normalizeText($term);
    if ($t === '' || $normalizedResume === '') {
        return false;
    }
    if (str_contains($normalizedResume, $t)) {
        return true;
    }
    // soft variants
    $compact = preg_replace('/\s+/u', '', $t) ?? $t;
    $hay = preg_replace('/\s+/u', '', $normalizedResume) ?? $normalizedResume;

    return $compact !== '' && str_contains($hay, $compact);
}

/** @return list<string> */
function extractRequirements(string $text): array
{
    $plain = normalizeText(strip_tags($text));
    $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
    $dict = requirementDictionary();
    $found = [];
    foreach ($dict as $term) {
        $t = normalizeText($term);
        if ($t === '') {
            continue;
        }
        if (preg_match('/(?:^|[^a-zа-я0-9+.#])' . preg_quote($t, '/') . '(?:[^a-zа-я0-9+.#]|$)/u', $plain) === 1) {
            $found[$term] = true;
        }
    }

    return array_keys($found);
}

/** @return list<string> */
function requirementDictionary(): array
{
    return [
        // delivery / pm
        'Agile', 'Scrum', 'Kanban', 'SAFe', 'LeSS', 'Waterfall', 'Prince2', 'PMP', 'PMBOK',
        'Jira', 'Confluence', 'YouTrack', 'Azure DevOps', 'Trello', 'Notion',
        'roadmap', 'backlog', 'OKR', 'KPI', 'SLA', 'RACI', 'RAID', 'stakeholder',
        'budget', 'P&L', 'unit-экономика', 'unit экономика', 'Time-to-Market',
        'discovery', 'delivery', 'release management', 'change management',
        'product owner', 'product manager', 'scrum master', 'project manager',
        'program manager', 'delivery manager', 'technical project manager',
        'руководитель проектов', 'руководитель проекта', 'проектный офис', 'PMO',
        // tech / stack
        'PHP', 'Bitrix24', 'Битрикс24', '1С-Битрикс', '1C-Bitrix', '1С', '1C',
        'REST', 'SOAP', 'GraphQL', 'gRPC', 'Kafka', 'RabbitMQ', 'Redis',
        'PostgreSQL', 'MySQL', 'ClickHouse', 'MongoDB', 'Elasticsearch',
        'Docker', 'Kubernetes', 'k8s', 'CI/CD', 'GitLab', 'GitHub Actions',
        'AWS', 'Azure', 'GCP', 'Yandex Cloud', 'S3',
        'Python', 'Java', 'Go', 'Node.js', 'TypeScript', 'JavaScript', 'React', 'Vue',
        'Laravel', 'Symfony', 'Spring', 'Django', 'FastAPI',
        'микросервис', 'микросервисы', 'highload', 'ETL', 'DWH', 'BI', 'Power BI', 'Tableau',
        'Salesforce', 'AmoCRM', 'amocrm',
        'SAP', 'ERP', 'CRM', 'BPM', 'BPMN', 'UML',
        // domains
        'fintech', 'e-commerce', 'ecommerce', 'маркетплейс', 'банк', 'страхование',
        'телеком', 'логистика', 'ритейл', 'retail', 'EdTech', 'HealthTech',
        'B2B', 'B2C', 'SaaS', 'marketplace',
        // leadership / other
        'матричное управление', 'кросс-функциональн', 'cross-functional',
        'найм', 'performance review',
        'информационная безопасность', 'DevSecOps', 'SOC',
        'Machine Learning', 'LLM', 'Data Science',
        'интеграции', 'миграция', 'внедрение',
        'English', 'английский',
        'релокация', 'remote', 'гибрид',
    ];
}

function roleMismatch(string $resumeTitle, string $vacancyTitle): ?string
{
    $r = normalizeText($resumeTitle);
    $v = normalizeText($vacancyTitle);
    if ($r === '' || $v === '') {
        return null;
    }
    $resumeIsDev = preg_match('/(php|разработ|developer|программист|backend|frontend)/u', $r) === 1
        && preg_match('/(project|delivery|scrum|руководитель|head of|manager)/u', $r) !== 1;
    $resumeIsPm = preg_match('/(project|delivery|scrum|руководитель|head of|manager|tpm)/u', $r) === 1;
    $vacIsDev = preg_match('/(php|разработчик|developer|программист|backend|frontend|java|golang|python[- ]?dev)/u', $v) === 1
        && preg_match('/(project manager|руководитель проекта|delivery|scrum master|product manager)/u', $v) !== 1;
    $vacIsSales = preg_match('/(sales|продаж|account manager|bdm|бизнес[- ]?развит|менеджер по продажам)/u', $v) === 1;
    $vacIsSupport = preg_match('/(техническ(ая|ой) поддерж|support engineer|helpdesk|service desk)/u', $v) === 1;
    $vacIsMarketing = preg_match('/(маркетолог|marketing|smm|performance[- ]маркетинг)/u', $v) === 1;

    if ($resumeIsPm && $vacIsDev) {
        return 'PM/Delivery/SM-резюме на developer-вакансию';
    }
    if ($resumeIsDev && $vacIsSales) {
        return 'Dev-резюме на sales-вакансию';
    }
    if ($resumeIsPm && $vacIsSales) {
        return 'PM-резюме на sales/BDM';
    }
    if ($resumeIsPm && $vacIsSupport) {
        return 'PM-резюме на support';
    }
    if ($resumeIsPm && $vacIsMarketing) {
        return 'PM-резюме на маркетинг';
    }
    if ($resumeIsDev && preg_match('/(scrum master|product owner|product manager)/u', $v) === 1) {
        return 'Dev-резюме на SM/PO/PM';
    }

    return null;
}

/**
 * @param array<string, mixed> $detail
 */
function policySkipHint(string $vacancyTitle, string $reason, array $detail): ?string
{
    $hay = normalizeText($vacancyTitle . "\n" . $reason . "\n" . json_encode($detail, JSON_UNESCAPED_UNICODE) ?: '');
    $hardPrefixes = [
        'FIT.1c_engineering_required',
        'FIT.crm_manager',
        'FIT.commercial_primary',
        'DESIRABILITY.sales_managers_leadership',
        'DESIRABILITY.leadgen_partnerships_primary',
        'DESIRABILITY.dedicated_crm_analyst',
        'DESIRABILITY.infrastructure_primary',
        'EXCLUDE-01',
        'EXCLUDE-02',
    ];
    $signals = is_array($detail['deterministic_signals'] ?? null) ? $detail['deterministic_signals'] : [];
    foreach ($signals as $sig) {
        $code = is_array($sig) ? (string) ($sig['code'] ?? $sig['id'] ?? '') : (string) $sig;
        foreach ($hardPrefixes as $prefix) {
            if ($code !== '' && str_starts_with($code, $prefix)) {
                return $code;
            }
        }
    }
    if (str_contains($hay, 'exclude-01') || str_contains($hay, 'sales_managers_leadership')) {
        return 'DESIRABILITY.sales_managers_leadership.skip';
    }
    if (str_contains($hay, 'exclude-02') || str_contains($hay, 'leadgen_partnerships')) {
        return 'DESIRABILITY.leadgen_partnerships_primary.skip';
    }
    if (str_contains($hay, 'dedicated_crm_analyst')) {
        return 'DESIRABILITY.dedicated_crm_analyst.skip';
    }
    if (str_contains($hay, 'infrastructure_primary')) {
        return 'DESIRABILITY.infrastructure_primary.skip';
    }
    if (str_contains($hay, 'commercial_primary') || preg_match('/\b(head of sales|директор по продажам|менеджер по продажам|bdm)\b/u', $hay)) {
        if (preg_match('/\b(head of sales|директор по продажам|менеджер по продажам)\b/u', normalizeText($vacancyTitle))) {
            return 'FIT.commercial_primary (по заголовку)';
        }
    }
    if (preg_match('/\b(crm[- ]?manager|менеджер crm|оператор crm)\b/u', normalizeText($vacancyTitle))) {
        return 'FIT.crm_manager (по заголовку)';
    }
    if (preg_match('/(руководител\w+ .*1[сc]|внедрен\w+ 1[сc]|1[сc] программист)/u', normalizeText($vacancyTitle))) {
        return 'FIT.1c_engineering_required (по заголовку)';
    }
    if (preg_match('/\b(cto|cio|cmo|team lead|тимлид разработ)\b/u', normalizeText($vacancyTitle))) {
        return 'SKIP по смыслу: C-level / team lead разработки (заголовок)';
    }
    if (preg_match('/(релокац|relocation|европа|германи|portugal|serbia|cyprus)/u', $hay)
        && preg_match('/(релокац|relocation|переезд)/u', normalizeText($vacancyTitle) . ' ' . normalizeText($reason))) {
        return 'International relocation';
    }

    return null;
}

/**
 * @param array<string, array<string, mixed>> $funnel
 * @param array<string, int> $topMissing
 * @param array<string, array{title: string, text: string}> $resumeTexts
 * @param array<string, array{title: string}> $resumes
 * @param array<string, array<string, mixed>> $highRejectVids
 * @param array<string, string> $descriptions
 * @return list<array{title: string, notes: list<string>, edits: list<array{was: string, become: string}>}>
 */
function suggestEdits(
    array $funnel,
    array $topMissing,
    array $resumeTexts,
    array $resumes,
    array $highRejectVids,
    array $descriptions,
): array {
    $out = [];
    foreach ($funnel as $rid => $row) {
        $title = (string) $row['title'];
        $text = $resumeTexts[$rid]['text'] ?? '';
        $norm = normalizeText($text);
        $notes = [
            'Откликов: ' . $row['applied'] . ', отказов: ' . $row['reject']
                . ' (' . $row['pct_reject'] . '%), собесов: ' . $row['interview']
                . ', отказов без просмотра: ' . $row['reject_no_view'] . '.',
        ];
        $edits = [];

        // per-resume missing terms among its high rejects
        $miss = [];
        foreach ($highRejectVids as $vid => $info) {
            if (($info['resume_id'] ?? '') !== $rid) {
                continue;
            }
            $desc = $descriptions[$vid] ?? '';
            if ($desc === '') {
                continue;
            }
            foreach (extractRequirements($desc . "\n" . ($info['title'] ?? '')) as $term) {
                if (!termInText($term, $norm)) {
                    $miss[$term] = ($miss[$term] ?? 0) + 1;
                }
            }
        }
        arsort($miss);
        $i = 0;
        foreach ($miss as $term => $cnt) {
            if ($cnt < 1) {
                continue;
            }
            $presence = termInText((string) $term, $norm);
            if ($presence) {
                continue;
            }
            $suggestion = editSuggestionForTerm((string) $term, $title, $norm);
            $edits[] = [
                'was' => 'В тексте резюме нет явного «' . $term . '» (встречалось в '
                    . $cnt . ' high-score отказах)',
                'become' => $suggestion,
            ];
            if (++$i >= 8) {
                break;
            }
        }

        if ($edits === [] && $topMissing !== []) {
            $notes[] = 'Мало персональных high-score отказов с текстом вакансии — общих топ-дыр может не хватить для правок.';
        }

        $out[] = [
            'title' => $title !== '' ? $title : $rid,
            'notes' => $notes,
            'edits' => $edits,
        ];
    }

    return $out;
}

function editSuggestionForTerm(string $term, string $resumeTitle, string $normResume): string
{
    $t = normalizeText($term);
    $safe = [
        'jira' => 'Добавить в блок инструментов: «Jira (boards, workflows, отчёты)» — если реально пользовался.',
        'confluence' => 'Добавить: «Confluence / вики проекта» рядом с Jira.',
        'kanban' => 'В методологиях явно указать Kanban (если вёл flow/WIP), не только Scrum.',
        'safe' => '[нужен факт от Алексея] Был ли опыт SAFe / PI-planning? Если да — одна строка в методологиях; если нет — не добавлять.',
        'less' => '[нужен факт от Алексея] Опыт LeSS? Иначе не писать.',
        'pmp' => '[нужен факт от Алексея] Есть ли сертификат PMP/Prince2? Если нет — не указывать.',
        'okr' => 'Если ставил OKR команде/продукту — сформулировать в достижениях с метрикой.',
        'kpi' => 'Уточнить 1–2 KPI delivery (срок, качество, throughput), которыми управлял.',
        'english' => '[нужен факт от Алексея] Уровень English (B1/B2/C1) и был ли рабочий язык — добавить в шапку.',
        'b2' => '[нужен факт от Алексея] Подтвердить уровень английского.',
        'c1' => '[нужен факт от Алексея] Подтвердить уровень английского.',
        'kubernetes' => '[нужен факт от Алексея] Лично администрировал k8s или только координировал? Писать только координацию, если так.',
        'aws' => '[нужен факт от Алексея] Какой облачный опыт (заказчик/архитектура/сам)?',
        'azure' => '[нужен факт от Алексея] Опыт Azure — какой именно?',
        'gcp' => '[нужен факт от Алексея] Опыт GCP — какой именно?',
        'kafka' => 'Если интегрировал/заказывал Kafka как PM — «интеграции через Kafka/очереди» в проектах; иначе не выдумывать.',
        'salesforce' => 'Уже есть миграция Salesforce→Bitrix24 в одном из резюме — пронести ту же формулировку во все PM/Delivery резюме, где уместно.',
        'финтех' => 'Если проекты были в финтехе/платежах — явно пометить домен в заголовке проекта.',
        'fintech' => 'Явно маркировать fintech-проекты в опыте (если были).',
        'e-commerce' => 'Явно маркировать e-com/retail проекты.',
        'маркетплейс' => 'Если был marketplace — одна строка про ассортимент/заказы/интеграции.',
        'saas' => 'Пометить SaaS/B2B product delivery, если продукт был подписочный.',
    ];
    foreach ($safe as $key => $msg) {
        if ($t === $key) {
            return $msg;
        }
        // Короткие ключи (b2/c1) — только точное совпадение, иначе B2C→English.
        if (mb_strlen($key) <= 2) {
            continue;
        }
        if (str_contains($t, $key)) {
            return $msg;
        }
    }
    if (str_contains($t, '1с') || $t === '1c') {
        return 'Оставить акцент на **интеграции** Bitrix24↔1С, не на внедрении 1С как продукта. Не писать «руководил разработкой 1С», если этого не было.';
    }
    if (str_contains($t, 'bitrix')) {
        return 'Усилить формулировку про Bitrix24 REST / бизнес-процессы / миграции — если уже есть в PHP/PM резюме, синхронизировать блок «Ключевые навыки».';
    }
    if (str_contains($t, 'scrum')) {
        return 'Для SM-резюме: явные артефакты (sprint goal, refinement, метрики flow). Для PM — «работал со Scrum-командой», не выдавать себя за SM, если не вел ритуалы.';
    }

    return 'Добавить в навыки/опыт упоминание «' . $term . '», **только если был реальный опыт**. Иначе — `[нужен факт от Алексея]` или не откликаться на такие вакансии.';
}
