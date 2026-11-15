<?php
declare(strict_types=1);

namespace JobSearch\Application\Chat;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Domain\Chat\ChatStore;
use JobSearch\Domain\Chat\ChatSummary;

/**
 * Покинуть на HH чаты старше N месяцев; карточки остаются в локальном архиве.
 */
final class LeaveOldChats
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
        private readonly ChatStore $store,
        private readonly ChatHistoryArchive $historyArchive,
        private readonly ?ChatRejectionArchive $rejectionArchive = null,
        private readonly ?SyncChatList $resync = null,
        private readonly int $olderThanMonths = 1,
    ) {}

    /**
     * @param (callable(int, int, string): void)|null $onProgress
     *
     * @return array{
     *   ok: bool,
     *   targets: int,
     *   left: int,
     *   failed: int,
     *   synced: int,
     *   archived: int,
     *   cutoff: string,
     *   message: string,
     *   errors: list<string>
     * }
     */
    public function leaveAll(string $storageStatePath, ?callable $onProgress = null): array
    {
        $cutoff = $this->cutoff();
        $cutoffIso = $cutoff->format(DateTimeImmutable::ATOM);

        if (!is_readable($storageStatePath)) {
            return [
                'ok' => false,
                'targets' => 0,
                'left' => 0,
                'failed' => 0,
                'synced' => 0,
                'archived' => 0,
                'cutoff' => $cutoffIso,
                'message' => 'Нет browser-сессии. Сначала login.',
                'errors' => [],
            ];
        }

        /** @var list<ChatSummary> $oldChats */
        $oldChats = [];
        $targets = [];
        foreach ($this->store->listAll() as $chat) {
            if (!$chat instanceof ChatSummary) {
                continue;
            }
            if (!$this->isOlderThan($chat, $cutoff)) {
                continue;
            }
            $oldChats[] = $chat;
            $id = trim($chat->chatId);
            if ($id !== '') {
                $targets[] = $id;
            }
        }
        $targets = array_values(array_unique($targets));

        $archived = $this->historyArchive->mergeFromChats($oldChats);
        // Отказы среди старых — в журнал отказов/компаний тоже.
        $this->rejectionArchive?->mergeFromChats($oldChats);

        if ($targets === []) {
            return [
                'ok' => true,
                'targets' => 0,
                'left' => 0,
                'failed' => 0,
                'synced' => 0,
                'archived' => $archived,
                'cutoff' => $cutoffIso,
                'message' => sprintf(
                    'Чатов старше %d мес. (до %s) в live-списке нет.',
                    $this->olderThanMonths,
                    $cutoff->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i:s'),
                ),
                'errors' => [],
            ];
        }

        $context = $this->browser->openChromium($storageStatePath);
        try {
            $page = $context->newPage();
            $result = $this->pages->leaveChats($page, $targets, $onProgress);
        } finally {
            $context->close();
        }

        $synced = 0;
        if ($this->resync !== null) {
            $sync = $this->resync->sync($storageStatePath);
            $synced = (int) ($sync['count'] ?? 0);
        }

        $left = (int) ($result['left'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        /** @var list<string> $errors */
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $ok = $failed === 0;
        $message = sprintf(
            'Покинул старых чатов: %d из %d%s (старше %d мес.).',
            $left,
            count($targets),
            $failed > 0 ? (', ошибок ' . $failed) : '',
            $this->olderThanMonths,
        );
        if ($archived > 0) {
            $message .= sprintf(' В архив приложения: +%d.', $archived);
        }
        if ($synced > 0) {
            $message .= sprintf(' Синк чатов: %d.', $synced);
        }

        return [
            'ok' => $ok,
            'targets' => count($targets),
            'left' => $left,
            'failed' => $failed,
            'synced' => $synced,
            'archived' => $archived,
            'cutoff' => $cutoffIso,
            'message' => $message,
            'errors' => $errors,
        ];
    }

    /** Сколько live-чатов сейчас старше порога (для кнопки UI). */
    public function countTargets(): int
    {
        $cutoff = $this->cutoff();
        $n = 0;
        foreach ($this->store->listAll() as $chat) {
            if ($this->isOlderThan($chat, $cutoff) && trim($chat->chatId) !== '') {
                $n++;
            }
        }

        return $n;
    }

    private function cutoff(): DateTimeImmutable
    {
        $months = max(1, $this->olderThanMonths);

        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))
            ->sub(new DateInterval('P' . $months . 'M'));
    }

    private function isOlderThan(ChatSummary $chat, DateTimeImmutable $cutoff): bool
    {
        $raw = trim($chat->lastMessageAt);
        if ($raw === '') {
            $raw = trim($chat->lastActivityAt);
        }
        if ($raw === '') {
            return false;
        }
        try {
            $at = new DateTimeImmutable($raw);
        } catch (\Exception) {
            return false;
        }

        return $at < $cutoff;
    }
}
