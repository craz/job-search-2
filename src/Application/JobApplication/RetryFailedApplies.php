<?php
declare(strict_types=1);

namespace JobSearch\Application\JobApplication;

use JobSearch\Application\Error\AppErrorStore;
use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Browser\BrowserGateway;

/**
 * Повторный отклик по открытым записям журнала ошибок (resume+vacancy).
 */
final class RetryFailedApplies
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
        private readonly AppErrorStore $errors,
        private readonly ?AppliedByVacancyStore $appliedResumes = null,
    ) {}

    /**
     * @param (callable(int, int, string, string): void)|null $onProgress
     *
     * @return array{
     *   ok: bool,
     *   message: string,
     *   targets: int,
     *   submitted: int,
     *   already: int,
     *   failed: int,
     *   errors: list<array{vacancy_id: string, vacancy_title: string, message: string}>
     * }
     */
    public function retryAll(string $storageStatePath, ?callable $onProgress = null): array
    {
        if (!is_readable($storageStatePath)) {
            return [
                'ok' => false,
                'message' => 'Нет browser-сессии. Сначала login.',
                'targets' => 0,
                'submitted' => 0,
                'already' => 0,
                'failed' => 0,
                'errors' => [],
            ];
        }

        $targets = $this->errors->retryableTargets();
        if ($targets === []) {
            return [
                'ok' => true,
                'message' => 'Нет открытых ошибок для повтора.',
                'targets' => 0,
                'submitted' => 0,
                'already' => 0,
                'failed' => 0,
                'errors' => [],
            ];
        }

        $submitted = 0;
        $already = 0;
        $failed = 0;
        /** @var list<array{vacancy_id: string, vacancy_title: string, message: string}> $failRows */
        $failRows = [];
        $total = count($targets);

        $context = $this->browser->openChromium($storageStatePath);
        try {
            $page = $context->newPage();
            foreach ($targets as $index => $target) {
                $n = $index + 1;
                $resumeId = $target['resume_id'];
                $vacancyId = $target['vacancy_id'];
                $label = $target['vacancy_title'] !== '' ? $target['vacancy_title'] : $vacancyId;
                $delay = ApplyPace::delaySecondsBefore($index);
                if ($delay > 0) {
                    if ($onProgress !== null) {
                        $onProgress(
                            $n,
                            $total,
                            $vacancyId,
                            sprintf('Пауза %d с перед повтором %d/%d…', $delay, $n, $total),
                        );
                    }
                    ApplyPace::sleepSeconds($delay);
                }
                if ($onProgress !== null) {
                    $onProgress($n, $total, $vacancyId, sprintf('Повтор %d/%d…', $n, $total));
                }
                try {
                    $result = $this->pages->applyToVacancy($page, $vacancyId, $resumeId);
                } catch (\Throwable $e) {
                    $failed++;
                    $failRows[] = [
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
                        $target['resume_title'],
                    );
                    $note = $result->alreadyApplied
                        ? 'Исправлено — уже откликались.'
                        : 'Исправлено — откликнуто.';
                    $this->errors->markApplied($resumeId, $vacancyId, $note);
                    if ($result->alreadyApplied) {
                        $already++;
                    } else {
                        $submitted++;
                    }
                    continue;
                }
                $failed++;
                $msg = trim($result->message);
                $failRows[] = [
                    'vacancy_id' => $vacancyId,
                    'vacancy_title' => $label,
                    'message' => $msg !== '' ? $msg : 'неизвестная ошибка',
                ];
            }
        } finally {
            $context->close();
        }

        $parts = [sprintf('К повтору %d', $total)];
        $parts[] = sprintf('отправлено %d', $submitted);
        if ($already > 0) {
            $parts[] = sprintf('уже были %d', $already);
        }
        if ($failed > 0) {
            $parts[] = sprintf('ошибок %d', $failed);
        }
        $message = implode(' · ', $parts) . '.';
        if ($failRows !== []) {
            $lines = [];
            foreach ($failRows as $err) {
                $lines[] = $err['vacancy_title'] . ': ' . $err['message'];
            }
            $message .= "\nОшибки:\n— " . implode("\n— ", $lines);
        }

        return [
            'ok' => $failed === 0,
            'message' => $message,
            'targets' => $total,
            'submitted' => $submitted,
            'already' => $already,
            'failed' => $failed,
            'errors' => $failRows,
        ];
    }
}
