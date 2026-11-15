<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Scoring;

use JobSearch\Domain\Scoring\ScoringApi;
use JobSearch\Domain\Scoring\ScoringException;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\Http\CurlHttpClient;
use JobSearch\Infrastructure\Http\HttpClient;
use JobSearch\Infrastructure\Http\HttpTransportException;
use JobSearch\Infrastructure\Text\Utf8;

/**
 * Scoring semantic-bulk без HH_PROXY — localhost.
 */
final class CurlScoringClient implements ScoringApi
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $scoringBaseUrl,
    ) {}

    public static function fromConfig(Config $config, ?HttpClient $http = null): self
    {
        $scoring = rtrim(
            $config->get('SCORING_API_URL', 'http://127.0.0.1:8090') ?? 'http://127.0.0.1:8090',
            '/',
        );

        return new self($http ?? new CurlHttpClient('', 60), $scoring);
    }

    public function enqueueSemanticBulk(array $vacancyIds, string $source = 'devai_auto'): array
    {
        $ids = [];
        $seen = [];
        foreach ($vacancyIds as $raw) {
            $id = trim((string) $raw);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }
        if ($ids === []) {
            throw new ScoringException('empty_vacancy_ids');
        }

        return $this->requestJson(
            'POST',
            '/api/v1/score/semantic-v1/bulk',
            [
                'vacancy_ids' => $ids,
                'source' => $source !== '' ? $source : 'devai_auto',
            ],
        );
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, ?array $body = null): array
    {
        $url = $this->scoringBaseUrl . $path;
        $headers = ['Accept' => 'application/json'];

        try {
            if ($method === 'GET') {
                $response = $this->http->get($url, $headers);
            } else {
                $payload = $body === null ? new \stdClass() : Utf8::scrubMixed($body);
                $json = json_encode($payload, Utf8::jsonFlags(JSON_THROW_ON_ERROR));
                $headers['Content-Type'] = 'application/json';
                $response = $this->http->post($url, $json, $headers);
            }
        } catch (HttpTransportException $e) {
            throw new ScoringException('Scoring transport: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new ScoringException('Scoring encode: ' . $e->getMessage(), 0, $e);
        }

        $payload = $response->jsonObject();
        if ($response->status < 200 || $response->status >= 300) {
            $code = is_array($payload) ? (string) ($payload['code'] ?? $payload['detail']['code'] ?? '') : '';
            $msg = is_array($payload)
                ? (string) ($payload['message'] ?? $payload['detail']['message'] ?? json_encode($payload, JSON_UNESCAPED_UNICODE))
                : 'HTTP ' . $response->status;
            throw new ScoringException(($code !== '' ? $code . ': ' : '') . $msg);
        }

        return is_array($payload) ? $payload : [];
    }
}
