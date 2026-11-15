<?php
declare(strict_types=1);

namespace JobSearch\Domain\Chat;

/**
 * Карточка чата HH (шаг 1: список без тела переписки).
 */
final class ChatSummary
{
    public function __construct(
        public readonly string $chatId,
        public readonly string $url,
        public readonly string $vacancyExternalId,
        public readonly string $vacancyTitle,
        public readonly string $companyName,
        public readonly string $applicantState,
        public readonly string $statusLabel,
        public readonly string $lastActivityAt,
        public readonly string $lastMessageAt,
        public readonly string $lastMessagePreview,
        public readonly int $unreadCount,
        public readonly int $listPosition = 0,
        public readonly string $syncedAt = '',
        public readonly string $resumeExternalId = '',
        public readonly string $resumeTitle = '',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'chat_id' => $this->chatId,
            'url' => $this->url,
            'vacancy_external_id' => $this->vacancyExternalId,
            'vacancy_title' => $this->vacancyTitle,
            'company_name' => $this->companyName,
            'applicant_state' => $this->applicantState,
            'status_label' => $this->statusLabel,
            'last_activity_at' => $this->lastActivityAt,
            'last_message_at' => $this->lastMessageAt,
            'last_message_preview' => $this->lastMessagePreview,
            'unread_count' => $this->unreadCount,
            'list_position' => $this->listPosition,
            'synced_at' => $this->syncedAt,
            'resume_external_id' => $this->resumeExternalId,
            'resume_title' => $this->resumeTitle,
        ];
    }

    /** Поля для Core PUT (без resume — живёт в FileCache). */
    /** @return array<string, mixed> */
    public function toCoreArray(): array
    {
        $row = $this->toArray();
        unset($row['resume_external_id'], $row['resume_title']);

        return $row;
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            chatId: trim((string) ($row['chat_id'] ?? '')),
            url: trim((string) ($row['url'] ?? '')),
            vacancyExternalId: trim((string) ($row['vacancy_external_id'] ?? '')),
            vacancyTitle: trim((string) ($row['vacancy_title'] ?? '')),
            companyName: trim((string) ($row['company_name'] ?? '')),
            applicantState: trim((string) ($row['applicant_state'] ?? '')),
            statusLabel: trim((string) ($row['status_label'] ?? '')),
            lastActivityAt: trim((string) ($row['last_activity_at'] ?? '')),
            lastMessageAt: trim((string) ($row['last_message_at'] ?? '')),
            lastMessagePreview: trim((string) ($row['last_message_preview'] ?? '')),
            unreadCount: (int) ($row['unread_count'] ?? 0),
            listPosition: (int) ($row['list_position'] ?? 0),
            syncedAt: trim((string) ($row['synced_at'] ?? '')),
            resumeExternalId: trim((string) ($row['resume_external_id'] ?? '')),
            resumeTitle: trim((string) ($row['resume_title'] ?? '')),
        );
    }

    /**
     * Стандартное HH-уведомление: «…но уже закрыли эту позицию».
     * Учитывает неразрывные пробелы из chatik.
     */
    public function isVacancyClosed(): bool
    {
        $label = mb_strtolower(trim($this->statusLabel), 'UTF-8');
        if ($label === 'закрыта') {
            return true;
        }
        $preview = mb_strtolower(str_replace("\u{00A0}", ' ', $this->lastMessagePreview), 'UTF-8');
        $preview = preg_replace('/\s+/u', ' ', $preview) ?? $preview;

        return str_contains($preview, 'закрыли эту позицию');
    }

    /** Отказ работодателя или закрытая вакансия (для компаний / leave / журнала). */
    public function isRejection(): bool
    {
        if ($this->isVacancyClosed()) {
            return true;
        }
        $state = strtoupper(trim($this->applicantState));
        if (in_array($state, ['DISCARD', 'DISCARDED'], true)) {
            return true;
        }
        $label = mb_strtolower(trim($this->statusLabel), 'UTF-8');

        return $label === 'отказ' || str_starts_with($label, 'отказ');
    }

    /** State для UI/архива: закрытая позиция → DISCARD. */
    public function displayApplicantState(): string
    {
        return $this->isVacancyClosed() ? 'DISCARD' : $this->applicantState;
    }

    /** Подпись бейджа: закрытая позиция → «Закрыта». */
    public function displayStatusLabel(): string
    {
        if ($this->isVacancyClosed()) {
            return 'Закрыта';
        }

        return $this->statusLabel;
    }

    /** Нормализованная копия (DISCARD + «Закрыта»), если в превью шаблон закрытия. */
    public function withClosedVacancyNormalized(): self
    {
        if (!$this->isVacancyClosed()) {
            return $this;
        }
        if ($this->applicantState === 'DISCARD' && $this->statusLabel === 'Закрыта') {
            return $this;
        }

        return new self(
            chatId: $this->chatId,
            url: $this->url,
            vacancyExternalId: $this->vacancyExternalId,
            vacancyTitle: $this->vacancyTitle,
            companyName: $this->companyName,
            applicantState: 'DISCARD',
            statusLabel: 'Закрыта',
            lastActivityAt: $this->lastActivityAt,
            lastMessageAt: $this->lastMessageAt,
            lastMessagePreview: $this->lastMessagePreview,
            unreadCount: $this->unreadCount,
            listPosition: $this->listPosition,
            syncedAt: $this->syncedAt,
            resumeExternalId: $this->resumeExternalId,
            resumeTitle: $this->resumeTitle,
        );
    }
}
