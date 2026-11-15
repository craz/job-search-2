<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Http;

/** Сетевой/транспортный сбой HTTP-клиента (до разбора доменного ответа). */
final class HttpTransportException extends \RuntimeException
{
}
