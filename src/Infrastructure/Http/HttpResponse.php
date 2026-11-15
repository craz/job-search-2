<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Http;

/**
 * Сырой HTTP-ответ: статус и тело (ещё без разбора домена).
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {}

    /**
     * Тело как JSON-объект (ассоциативный массив).
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    public function jsonObject(): array
    {
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('JSON root is not an object/array');
        }

        return $decoded;
    }
}
