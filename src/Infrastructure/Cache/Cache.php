<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Cache;

/**
 * Контракт простого кеша снимков (аккаунт, город, резюме).
 * Без TTL по умолчанию: редко меняющиеся данные, сброс — вручную.
 */
interface Cache
{
    public function get(string $key): ?CacheEntry;

    public function set(string $key, mixed $payload): void;

    public function delete(string $key): void;
}
