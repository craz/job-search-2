<?php
declare(strict_types=1);

namespace JobSearch\Domain\Resume;

/**
 * Один просмотр резюме работодателем (страница HH resumeview/history).
 */
final class ResumeViewEvent
{
    public function __construct(
        public readonly string $employerId,
        public readonly string $companyName,
        public readonly string $viewedAt,
    ) {}
}
