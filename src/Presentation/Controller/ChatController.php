<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Chat\ListChats;
use JobSearch\Application\Chat\SyncChatList;

/** Presentation: чаты HH (список + синк). */
final class ChatController
{
    public function __construct(
        private readonly ListChats $list,
        private readonly ?SyncChatList $sync = null,
    ) {}

    /** @return array<string, mixed> */
    public function listPage(string $query = '', string $filter = 'all'): array
    {
        return $this->list->page($query, $filter);
    }

    /** @return array{ok: bool, count: int, message: string} */
    public function sync(string $storageStatePath): array
    {
        if ($this->sync === null) {
            throw new \RuntimeException('SyncChatList не подключён');
        }

        return $this->sync->sync($storageStatePath);
    }
}
