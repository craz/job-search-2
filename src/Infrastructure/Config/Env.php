<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Config;

/**
 * Конфиг из dotenv-файла (KEY=VALUE, строки с # игнорируются).
 */
final class Env implements Config
{
    /** @param array<string, string> $values */
    private function __construct(private readonly array $values) {}

    public static function load(string $path): self
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("Missing env file: {$path}");
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value);
        }

        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Required env key missing: {$key}");
        }

        return $value;
    }
}
