<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Cache;

/** Значение из кеша: полезные данные + когда сохранены. */
final class CacheEntry
{
    public function __construct(
        public readonly mixed $payload,
        public readonly int $savedAt,
    ) {}
}
