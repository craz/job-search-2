<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Hh;

/** Ответ HH получен, но тело/статус непригодны для разбора (например не-JSON). */
final class HhHttpException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $bodySnippet = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
