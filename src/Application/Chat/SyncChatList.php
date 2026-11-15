<?php
declare(strict_types=1);

namespace JobSearch\Application\Chat;

use JobSearch\Application\Error\ReconcileApplyErrorsFromChats;
use JobSearch\Application\JobApplication\AppliedByVacancyStore;
use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Domain\Chat\ChatStore;

/**
 * Use case: скачать список чатов HH → локальная БД (шаг 1, без сообщений).
 */
final class SyncChatList
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
        private readonly ChatStore $store,
        private readonly ?AppliedByVacancyStore $appliedResumes = null,
        private readonly ?ChatRejectionArchive $rejectionArchive = null,
        private readonly ?ReconcileApplyErrorsFromChats $reconcileErrors = null,
    ) {}

    /**
     * @return array{ok: bool, count: int, message: string, archived: int}
     */
    public function sync(string $storageStatePath): array
    {
        if (!is_readable($storageStatePath)) {
            return [
                'ok' => false,
                'count' => 0,
                'archived' => 0,
                'message' => 'Нет browser-сессии. Сначала login.',
            ];
        }

        $context = $this->browser->openChromium($storageStatePath);
        try {
            $page = $context->newPage();
            $chats = $this->pages->listChats($page);
            $this->appliedResumes?->mergeFromChats($chats);
            $errorsClosed = $this->reconcileErrors?->run($chats) ?? 0;
            // До replaceAll — иначе leave/wipe сотрёт DISCARD из Core.
            $archived = $this->rejectionArchive?->mergeFromChats($chats) ?? 0;
            $saved = $this->store->replaceAll($chats);

            $message = $saved > 0
                ? sprintf('Скачано чатов: %d.', $saved)
                : 'Чатов на HH не найдено.';
            if ($archived > 0) {
                $message .= sprintf(' В архив отказов: +%d.', $archived);
            }
            if ($errorsClosed > 0) {
                $message .= sprintf(' Ошибок apply закрыто по чатам: %d.', $errorsClosed);
            }

            return [
                'ok' => true,
                'count' => $saved,
                'archived' => $archived,
                'errors_closed' => $errorsClosed,
                'message' => $message,
            ];
        } finally {
            $context->close();
        }
    }
}
