<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Hh;

/** Не удалось достучаться до api.hh.ru (сеть, TLS, прокси). */
final class HhTransportException extends \RuntimeException
{
}
