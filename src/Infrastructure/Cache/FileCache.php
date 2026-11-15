<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Cache;

/**
 * Файловый JSON-кеш в каталоге data/cache (один файл на ключ).
 */
final class FileCache implements Cache
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Не удалось создать каталог кеша: ' . $this->directory);
        }
    }

    public function get(string $key): ?CacheEntry
    {
        $path = $this->path($key);
        if (!is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('payload', $decoded)) {
            return null;
        }

        $savedAt = isset($decoded['saved_at']) && is_int($decoded['saved_at'])
            ? $decoded['saved_at']
            : 0;

        return new CacheEntry($decoded['payload'], $savedAt);
    }

    public function set(string $key, mixed $payload): void
    {
        $path = $this->path($key);
        $json = json_encode(
            [
                'saved_at' => time(),
                'payload' => $payload,
            ],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT,
        );
        if ($json === false) {
            throw new \RuntimeException('Не удалось сериализовать кеш: ' . $key);
        }

        // Уникальный tmp: параллельные writer'ы на один ключ не затирают чужой .tmp
        // (иначе rename падает с «No such file» — как у vacancy_resume_owner в schedule).
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json . "\n") === false) {
            throw new \RuntimeException('Не удалось записать кеш: ' . $key);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось сохранить кеш: ' . $key);
        }
        chmod($path, 0600);
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Не удалось удалить кеш: ' . $key);
        }
    }

    private function path(string $key): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/i', $key)) {
            throw new \InvalidArgumentException('Недопустимый ключ кеша: ' . $key);
        }

        return $this->directory . '/' . $key . '.json';
    }
}
