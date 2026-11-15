<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Hh;


use JobSearch\Domain\Account\HhApi;
use JobSearch\Domain\Account\Me;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\Http\CurlHttpClient;
use JobSearch\Infrastructure\Http\HttpClient;
use JobSearch\Infrastructure\Http\HttpTransportException;

/**
 * Реализация HhApi поверх произвольного HttpClient (по умолчанию curl).
 * Токен и User-Agent обязательны для api.hh.ru.
 */
final class HhClient implements HhApi
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $accessToken,
        private readonly string $userAgent,
        private readonly string $baseUrl = 'https://api.hh.ru',
    ) {}

    /** Собирает клиент из Config; HTTP-транспорт можно подменить. */
    public static function fromConfig(Config $config, ?HttpClient $http = null): self
    {
        $proxy = $config->get('HH_PROXY', '') ?? '';

        return new self(
            http: $http ?? new CurlHttpClient($proxy),
            accessToken: $config->require('HH_ACCESS_TOKEN'),
            userAgent: $config->require('HH_USER_AGENT'),
        );
    }

    public function me(): Me
    {
        $response = $this->request('GET', '/me');

        try {
            $data = $response->jsonObject();
        } catch (\JsonException $e) {
            throw new HhHttpException(
                "HTTP {$response->status}: non-JSON body",
                $response->status,
                substr($response->body, 0, 500),
                $e,
            );
        }

        return Me::fromApi($response->status, $data);
    }

    /** @throws HhTransportException */
    private function request(string $method, string $path): \JobSearch\Infrastructure\Http\HttpResponse
    {
        if ($method !== 'GET') {
            throw new \InvalidArgumentException("Unsupported method: {$method}");
        }

        try {
            return $this->http->get($this->baseUrl . $path, [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'User-Agent' => $this->userAgent,
                'HH-User-Agent' => $this->userAgent,
                'Accept' => 'application/json',
            ]);
        } catch (HttpTransportException $e) {
            throw new HhTransportException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
