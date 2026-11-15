<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Chat;

use JobSearch\Domain\Chat\ChatStore;
use JobSearch\Domain\Chat\ChatSummary;
use JobSearch\Domain\Core\CoreApi;

/**
 * Хранение карточек чатов HH в Core Postgres через HTTP API.
 */
final class CoreChatStore implements ChatStore
{
    public function __construct(private readonly CoreApi $core) {}

    public function replaceAll(array $chats): int
    {
        $items = [];
        foreach ($chats as $chat) {
            if (!$chat instanceof ChatSummary || $chat->chatId === '') {
                continue;
            }
            $row = $chat->toCoreArray();
            // Core ждёт datetime|null — пустая строка даёт 422.
            foreach (['last_activity_at', 'last_message_at', 'synced_at'] as $key) {
                if (($row[$key] ?? '') === '') {
                    $row[$key] = null;
                }
            }
            $items[] = $row;
        }
        $result = $this->core->replaceHhChats($items);

        return (int) ($result['total'] ?? count($items));
    }

    public function listAll(): array
    {
        $payload = $this->core->listHhChats();
        $out = [];
        foreach ($payload['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = ChatSummary::fromArray($row);
        }

        return $out;
    }

    public function count(): int
    {
        return $this->core->listHhChats()['total'];
    }
}
