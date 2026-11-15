<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Http;

/**
 * Минимальный HTTP-транспорт: GET/POST и сырой ответ.
 * Реализация скрывает curl/прокси/таймауты.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     *
     * @throws HttpTransportException при сетевом сбое
     */
    public function get(string $url, array $headers = []): HttpResponse;

    /**
     * @param array<string, string> $headers
     *
     * @throws HttpTransportException при сетевом сбое
     */
    public function post(string $url, string $body, array $headers = []): HttpResponse;

    /**
     * @param array<string, string> $headers
     *
     * @throws HttpTransportException при сетевом сбое
     */
    public function put(string $url, string $body, array $headers = []): HttpResponse;

    /**
     * @param array<string, string> $headers
     *
     * @throws HttpTransportException при сетевом сбое
     */
    public function patch(string $url, string $body, array $headers = []): HttpResponse;
}
