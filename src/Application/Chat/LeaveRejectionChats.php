<?php
declare(strict_types=1);

namespace JobSearch\Application\Chat;

use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Domain\Chat\ChatStore;
use JobSearch\Domain\Chat\ChatSummary;

/**
 * Покинуть на HH все чаты со статусом отказ (DISCARD), затем пересинк списка.
 */
final class LeaveRejectionChats
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
        private readonly ChatStore $store,
        private readonly ?SyncChatList $resync = null,
        private readonly ?ChatRejectionArchive $rejectionArchive = null,
        private readonly ?ChatHistoryArchive $historyArchive = null,
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
     *   message: string,
     *   errors: list<string>
     * }
     */
    public function leaveAll(string $storageStatePath, ?callable $onProgress = null): array
    {
        if (!is_readable($storageStatePath)) {
            return [
                'ok' => false,
                'targets' => 0,
                'left' => 0,
                'failed' => 0,
                'synced' => 0,
                'archived' => 0,
                'message' => 'Нет browser-сессии. Сначала login.',
                'errors' => [],
            ];
        }

        /** @var list<ChatSummary> $rejectionChats */
        $rejectionChats = [];
        $targets = [];
        foreach ($this->store->listAll() as $chat) {
            if (!$chat instanceof ChatSummary) {
                continue;
            }
            if (!$chat->isRejection()) {
                continue;
            }
            $rejectionChats[] = $chat->withClosedVacancyNormalized();
            $id = trim($chat->chatId);
            if ($id !== '') {
                $targets[] = $id;
            }
        }
        $targets = array_values(array_unique($targets));
        // Снимок до leave+replaceAll — иначе компании/чат/журнал потеряют карточки.
        $archived = $this->rejectionArchive?->mergeFromChats($rejectionChats) ?? 0;
        $archived += $this->historyArchive?->mergeFromChats($rejectionChats) ?? 0;
        if ($targets === []) {
            return [
                'ok' => true,
                'targets' => 0,
                'left' => 0,
                'failed' => 0,
                'synced' => 0,
                'archived' => $archived,
                'message' => 'Отказов в локальном журнале нет.',
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
            'Покинул отказов: %d из %d%s.',
            $left,
            count($targets),
            $failed > 0 ? (', ошибок ' . $failed) : '',
        );
        if ($archived > 0) {
            $message .= sprintf(' Архив отказов: +%d.', $archived);
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
            'message' => $message,
            'errors' => $errors,
        ];
    }

}
