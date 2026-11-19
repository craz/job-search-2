<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Http;

/**
 * HTTP через PHP curl. Опциональный прокси (http:// или socks5h://).
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly string $proxy = '',
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 3,
    ) {}

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        return $this->request('POST', $url, $body, $headers);
    }

    public function put(string $url, string $body, array $headers = []): HttpResponse
    {
        return $this->request('PUT', $url, $body, $headers);
    }

    public function patch(string $url, string $body, array $headers = []): HttpResponse
    {
        return $this->request('PATCH', $url, $body, $headers);
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpTransportException('curl_init failed');
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CONNECTTIMEOUT => max(1, $this->connectTimeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);

        if ($this->proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($errno !== 0) {
            throw new HttpTransportException("curl error {$errno}: {$error}", $errno);
        }

        return new HttpResponse($status, (string) $responseBody);
    }
}
