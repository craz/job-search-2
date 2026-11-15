<?php
declare(strict_types=1);

namespace JobSearch\Domain\Core;

/** Ошибка вызова Core API (сеть, HTTP, валидация ingest). */
final class CoreException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $responseBody = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
