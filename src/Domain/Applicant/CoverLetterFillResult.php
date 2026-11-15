<?php
declare(strict_types=1);

namespace JobSearch\Domain\Applicant;

/**
 * Итог генерации/утверждения сопроводительного.
 *
 * kind: filled | skipped | tg_timeout | needs_input | draft_with_gaps | failed
 */
final class CoverLetterFillResult
{
    public function __construct(
        public readonly string $kind,
        public readonly string $message = '',
        public readonly string $letter = '',
        public readonly ?string $coverLetterId = null,
        public readonly string $missing = '',
    ) {}

    public function ok(): bool
    {
        return $this->kind === 'filled' && trim($this->letter) !== '';
    }

    public static function filled(string $letter, ?string $coverLetterId = null): self
    {
        return new self('filled', '', $letter, $coverLetterId);
    }

    public static function skipped(string $message = '', ?string $coverLetterId = null): self
    {
        return new self(
            'skipped',
            $message !== '' ? $message : 'Сопроводительное пропущено (skip в TG).',
            '',
            $coverLetterId,
        );
    }

    public static function tgTimeout(?string $coverLetterId = null): self
    {
        return new self(
            'tg_timeout',
            'HH требует сопроводительное письмо — TG-ответ не получен / таймаут.',
            '',
            $coverLetterId,
        );
    }

    public static function needsInput(string $missing, ?string $coverLetterId = null): self
    {
        $missing = trim($missing);
        $msg = 'HH требует сопроводительное письмо — нужен факт от тебя'
            . ($missing !== '' ? ': ' . $missing : '.');

        return new self('needs_input', $msg, '', $coverLetterId, $missing);
    }

    public static function draftWithGaps(string $missing, ?string $coverLetterId = null): self
    {
        $missing = trim($missing);
        $msg = 'HH требует сопроводительное письмо — черновик с пробелами (DRAFT_WITH_GAPS)'
            . ($missing !== '' ? ': ' . $missing : '.');

        return new self('draft_with_gaps', $msg, '', $coverLetterId, $missing);
    }

    public static function failed(string $message, ?string $coverLetterId = null): self
    {
        return new self('failed', $message, '', $coverLetterId);
    }
}
