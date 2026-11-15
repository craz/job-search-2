<?php
declare(strict_types=1);

namespace JobSearch\Domain\Chat;

/**
 * Порт хранения карточек чатов HH (реализация — Core Postgres через API).
 */
interface ChatStore
{
    /**
     * Upsert списка; возвращает число записанных строк.
     *
     * @param list<ChatSummary> $chats
     */
    public function replaceAll(array $chats): int;

    /** @return list<ChatSummary> */
    public function listAll(): array;

    public function count(): int;
}
