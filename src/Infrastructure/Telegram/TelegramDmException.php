<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Telegram;

/** Ошибка Telegram DM Bot API / таймаут ожидания кода. */
final class TelegramDmException extends \RuntimeException
{
}
