<?php
declare(strict_types=1);

namespace JobSearch\Domain\Applicant;

/**
 * Итог заполнения анкеты работодателя.
 *
 * kind: filled | questions_required | skipped | tg_timeout
 */
final class ScreeningFillResult
{
    public function __construct(
        public readonly string $kind,
        public readonly string $message = '',
        public readonly int $filled = 0,
        public readonly int $unknown = 0,
    ) {}

    public function ok(): bool
    {
        return $this->kind === 'filled';
    }

    public static function filled(int $filled): self
    {
        return new self('filled', '', $filled, 0);
    }

    public static function questionsRequired(string $message, int $filled = 0, int $unknown = 0): self
    {
        return new self('questions_required', $message, $filled, $unknown);
    }

    public static function skipped(string $message = ''): self
    {
        return new self(
            'skipped',
            $message !== '' ? $message : 'Анкета пропущена (skip в TG).',
        );
    }

    public static function tgTimeout(): self
    {
        return new self(
            'tg_timeout',
            'HH требует ответы на вопросы работодателя — TG-ответ не получен / таймаут.',
        );
    }
}
