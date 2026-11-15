<?php
declare(strict_types=1);

namespace JobSearch\Application\JobApplication;

use JobSearch\Application\Error\AppErrorStore;
use JobSearch\Application\Vacancy\VacancyPage;
use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Browser\BrowserGateway;

/**
 * Пакетный отклик на все «Смотрим» (consider) для резюме через один browser-контекст.
 * Уже отмеченные applied / дубли / чужой owner / скип TG пропускаются.
 * Skip анкеты/письма в TG → markSkipped сразу (не ждать конца пакета).
 */
final class ApplyConsiderBatch
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
        private readonly VacancyPage $vacancies,
        private readonly ?AppliedByVacancyStore $appliedResumes = null,
        private readonly string $resumeTitle = '',
        private readonly ?AppErrorStore $appErrors = null,
    ) {}

    /**
     * @param (callable(int, int, string, string): void)|null $onProgress index, total, vacancyId, message
     *
     * @return array{
     *   ok: bool,
     *   message: string,
     *   consider: int,
     *   targets: int,
     *   submitted: int,
     *   already: int,
     *   failed: int,
     *   skipped: int,
     *   errors: list<array{vacancy_id: string, vacancy_title: string, message: string}>,
     *   succeeded_ids: list<string>,
     *   skipped_details: list<string>
     * }
     */
    public function applyAll(
        string $storageStatePath,
        string $resumeId,
        string $scope = 'recent',
        ?callable $onProgress = null,
        int $limit = 0,
    ): array {
        $resumeId = trim($resumeId);
        $scope = strtolower(trim($scope)) === 'all' ? 'all' : 'recent';
        $limit = max(0, min(50, $limit));
        if ($resumeId === '') {
            return $this->emptyResult(false, 'Укажите resume.');
        }
        if (!is_readable($storageStatePath)) {
            return $this->emptyResult(false, 'Нет browser-сессии. Сначала login.');
        }

        $pageData = $this->vacancies->forResume($resumeId, $this->resumeTitle, 'consider', '', $scope);
        $consider = (int) ($pageData['counts']['consider'] ?? 0);
        /** @var list<array<string, mixed>> $items */
        $items = is_array($pageData['items'] ?? null) ? $pageData['items'] : [];

        /** @var list<string> $targets */
        $targets = [];
        /** @var array<string, string> $labelsByExt */
        $labelsByExt = [];
        $skipped = 0;
        /** @var list<string> $skippedDetails */
        $skippedDetails = [];
        foreach ($items as $row) {
            $ext = trim((string) ($row['external_id'] ?? ''));
            $label = $this->rowLabel($row);
            if ($ext !== '') {
                $labelsByExt[$ext] = $label;
            }
            if ($ext === '') {
                $skipped++;
                $skippedDetails[] = $label . ' — нет vacancy id';
                continue;
            }
            if (!empty($row['company_rejected'])) {
                $skipped++;
                $hint = trim((string) ($row['company_rejection_hint'] ?? ''));
                $skippedDetails[] = $label . ' — компания отказывала'
                    . ($hint !== '' ? ' (' . $hint . ')' : '');
                continue;
            }
            if (!empty($row['company_excluded'])) {
                $skipped++;
                $hint = trim((string) ($row['company_exclusion_hint'] ?? ''));
                $skippedDetails[] = $label . ' — исключение'
                    . ($hint !== '' ? ' (' . $hint . ')' : '');
                continue;
            }
            if (!empty($row['is_duplicate'])) {
                $skipped++;
                $skippedDetails[] = $label . ' — дубль';
                continue;
            }
            if (isset($row['can_apply']) && !$row['can_apply']) {
                $skipped++;
                $reason = trim((string) ($row['owner_reason'] ?? ''));
                $skippedDetails[] = $label . ' — нельзя откликнуться'
                    . ($reason !== '' ? ' (' . $reason . ')' : '');
                continue;
            }
            if (!empty($row['applied'])) {
                $skipped++;
                $skippedDetails[] = $label . ' — уже отклик';
                continue;
            }
            if (!empty($row['apply_error'])) {
                $skipped++;
                $hint = trim((string) ($row['apply_error_message'] ?? ''));
                $skippedDetails[] = $label . ' — ошибка отклика'
                    . ($hint !== '' ? ' (' . $hint . ')' : '');
                continue;
            }
            if (!empty($row['apply_skipped'])) {
                $skipped++;
                $hint = trim((string) ($row['apply_skipped_message'] ?? ''));
                $skippedDetails[] = $label . ' — скип TG'
                    . ($hint !== '' ? ' (' . $hint . ')' : '');
                continue;
            }
            $targets[] = $ext;
            if ($limit > 0 && count($targets) >= $limit) {
                break;
            }
        }

        if ($targets === []) {
            $msg = $consider > 0
                ? sprintf('Смотрим %d: откликаться не на что (уже есть отклик или дубли).', $consider)
                : 'Нет вакансий в «Смотрим».';
            if ($skippedDetails !== []) {
                $msg .= "\nПропуск:\n— " . implode("\n— ", $skippedDetails);
            }

            return [
                'ok' => true,
                'message' => $msg,
                'consider' => $consider,
                'targets' => 0,
                'submitted' => 0,
                'already' => 0,
                'failed' => 0,
                'skipped' => $skipped,
                'errors' => [],
                'succeeded_ids' => [],
                'skipped_ids' => [],
                'skipped_apply_notes' => [],
                'skipped_details' => $skippedDetails,
            ];
        }

        $submitted = 0;
        $already = 0;
        $failed = 0;
        $tgSkipped = 0;
        /** @var list<array{vacancy_id: string, vacancy_title: string, message: string}> $errors */
        $errors = [];
        /** @var list<string> $succeededIds */
        $succeededIds = [];
        /** @var list<string> $skippedIds */
        $skippedIds = [];
        /** @var array<string, string> $skippedApplyNotes */
        $skippedApplyNotes = [];
        $total = count($targets);

        $context = $this->browser->openChromium($storageStatePath);
        try {
            $page = $context->newPage();
            foreach ($targets as $index => $vacancyId) {
                $n = $index + 1;
                $delay = ApplyPace::delaySecondsBefore($index);
                if ($delay > 0) {
                    if ($onProgress !== null) {
                        $onProgress(
                            $n,
                            $total,
                            $vacancyId,
                            sprintf('Пауза %d с перед откликом %d/%d…', $delay, $n, $total),
                        );
                    }
                    ApplyPace::sleepSeconds($delay);
                }
                if ($onProgress !== null) {
                    $onProgress(
                        $n,
                        $total,
                        $vacancyId,
                        sprintf('Отклик %d/%d…', $n, $total),
                    );
                }
                $label = $labelsByExt[$vacancyId] ?? '';
                if ($label === '' || $label === $vacancyId) {
                    $label = '';
                }
                try {
                    $result = $this->pages->applyToVacancy($page, $vacancyId, $resumeId);
                } catch (\Throwable $e) {
                    // Не валим весь пакет из‑за одной навигации Playwright.
                    $failed++;
                    $errors[] = [
                        'vacancy_id' => $vacancyId,
                        'vacancy_title' => $label,
                        'message' => trim($e->getMessage()) !== ''
                            ? trim($e->getMessage())
                            : 'сбой Playwright на отклике',
                    ];
                    continue;
                }
                if ($result->isOk() || $result->alreadyApplied) {
                    $this->appliedResumes?->rememberFromApply(
                        $vacancyId,
                        $resumeId,
                        $this->resumeTitle,
                    );
                    $succeededIds[] = $vacancyId;
                }
                if ($result->alreadyApplied) {
                    $already++;
                } elseif ($result->submitted || $result->isOk()) {
                    $submitted++;
                } elseif ($result->isSkipped()) {
                    $tgSkipped++;
                    $skippedIds[] = $vacancyId;
                    $note = trim($result->message) !== ''
                        ? trim($result->message)
                        : 'Пропуск — skip в TG.';
                    $skippedApplyNotes[$vacancyId] = $note;
                    // Сразу в «Скип TG», иначе параллельный/новый прогон снова дергает TG.
                    $this->appErrors?->markSkipped($resumeId, $vacancyId, $note);
                } else {
                    $failed++;
                    $errText = trim($result->message);
                    $errors[] = [
                        'vacancy_id' => $vacancyId,
                        'vacancy_title' => $label,
                        'message' => $errText !== '' ? $errText : 'неизвестная ошибка',
                    ];
                }
            }
        } finally {
            $context->close();
        }

        $ok = $failed === 0;
        // Считаем от реальных попыток (targets), «Смотрим» — только контекст для пропусков.
        $parts = [sprintf('К отклику %d', $total)];
        $parts[] = sprintf('отправлено %d', $submitted);
        if ($already > 0) {
            $parts[] = sprintf('уже были %d', $already);
        }
        if ($failed > 0) {
            $parts[] = sprintf('ошибок %d', $failed);
        }
        if ($tgSkipped > 0) {
            $parts[] = sprintf('скип TG %d', $tgSkipped);
        }
        if ($skipped > 0) {
            $parts[] = sprintf('пропуск %d (из Смотрим %d)', $skipped, $consider);
        }

        $message = implode(' · ', $parts) . '.';
        if ($errors !== []) {
            $lines = [];
            foreach ($errors as $err) {
                $lines[] = $err['vacancy_title'] . ': ' . $err['message'];
            }
            $message .= "\nОшибки:\n— " . implode("\n— ", $lines);
        }
        if ($skippedDetails !== []) {
            $message .= "\nПропуск:\n— " . implode("\n— ", $skippedDetails);
        }

        return [
            'ok' => $ok,
            'message' => $message,
            'consider' => $consider,
            'targets' => $total,
            'submitted' => $submitted,
            'already' => $already,
            'failed' => $failed,
            'skipped' => $skipped + $tgSkipped,
            'errors' => $errors,
            'succeeded_ids' => $succeededIds,
            'skipped_ids' => $skippedIds,
            'skipped_apply_notes' => $skippedApplyNotes,
            'skipped_details' => $skippedDetails,
        ];
    }

    /** Короткая подпись вакансии для отчёта об ошибке/пропуске. */
    private function rowLabel(array $row): string
    {
        $title = trim((string) ($row['title'] ?? ''));
        $company = trim((string) ($row['company_name'] ?? ''));
        $ext = trim((string) ($row['external_id'] ?? ''));
        // Для хранения в app_errors — только реальное название, не id.
        if ($title !== '') {
            $label = $title;
            if (mb_strlen($label) > 72) {
                $label = mb_substr($label, 0, 69) . '…';
            }
            if ($company !== '') {
                $label .= ' · ' . $company;
            }

            return $label;
        }

        return $ext !== '' ? $ext : 'Без названия';
    }

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   consider: int,
     *   targets: int,
     *   submitted: int,
     *   already: int,
     *   failed: int,
     *   skipped: int,
     *   errors: list<array{vacancy_id: string, vacancy_title: string, message: string}>,
     *   succeeded_ids: list<string>,
     *   skipped_ids: list<string>,
     *   skipped_apply_notes: array<string, string>,
     *   skipped_details: list<string>
     * }
     */
    private function emptyResult(bool $ok, string $message): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'consider' => 0,
            'targets' => 0,
            'submitted' => 0,
            'already' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'succeeded_ids' => [],
            'skipped_ids' => [],
            'skipped_apply_notes' => [],
            'skipped_details' => [],
        ];
    }
}
