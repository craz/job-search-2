<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Config;

/**
 * Контракт доступа к конфигурации (ключ → строка).
 * Реализация может читать .env, getenv или другой источник.
 */
interface Config
{
    public function get(string $key, ?string $default = null): ?string;

    /** @throws \RuntimeException если ключ отсутствует или пустой */
    public function require(string $key): string;
}
