<?php
declare(strict_types=1);

namespace JobSearch\Domain\Applicant;

/** Итог программного отклика на вакансию через UI hh.ru. */
final class ApplyResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $submitted,
        public readonly bool $alreadyApplied,
        public readonly string $message,
        public readonly string $vacancyExternalId = '',
        public readonly string $resumeId = '',
        public readonly bool $skipped = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'submitted' => $this->submitted,
            'already_applied' => $this->alreadyApplied,
            'skipped' => $this->skipped,
            'message' => $this->message,
            'vacancy_external_id' => $this->vacancyExternalId,
            'resume_id' => $this->resumeId,
        ];
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }
}
