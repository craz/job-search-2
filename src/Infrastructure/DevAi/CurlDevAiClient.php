<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\DevAi;

use JobSearch\Domain\DevAi\DevAi;
use JobSearch\Domain\DevAi\DevAiException;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\Http\CurlHttpClient;
use JobSearch\Infrastructure\Http\HttpClient;
use JobSearch\Infrastructure\Http\HttpTransportException;
use JobSearch\Infrastructure\Text\Utf8;

/**
 * DevAI chat completions без Python scoring.
 */
final class CurlDevAiClient implements DevAi
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $routerKey,
        private readonly int $timeoutSec = 180,
    ) {}

    public static function fromConfig(Config $config, ?HttpClient $http = null): self
    {
        $base = rtrim(
            $config->get('DEVAI_BASE_URL', 'http://127.0.0.1:8190') ?? 'http://127.0.0.1:8190',
            '/',
        );
        $key = trim((string) ($config->get('DEVAI_ROUTER_KEY', '') ?? ''));
        $timeout = (int) ($config->get('DEVAI_TIMEOUT_SEC', '180') ?? '180');

        return new self(
            $http ?? new CurlHttpClient('', max(30, $timeout)),
            $base,
            $key,
            max(30, $timeout),
        );
    }

    public function chatCompletions(string $model, array $messages): array
    {
        $model = trim($model);
        if ($model === '') {
            throw new DevAiException('devai_model_empty');
        }
        if ($messages === []) {
            throw new DevAiException('devai_messages_empty');
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($this->routerKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->routerKey;
        }

        try {
            $json = json_encode(
                Utf8::scrubMixed([
                    'model' => $model,
                    'messages' => array_values($messages),
                    'stream' => false,
                ]),
                Utf8::jsonFlags(JSON_THROW_ON_ERROR),
            );
            $response = $this->http->post(
                $this->baseUrl . '/v1/chat/completions',
                $json,
                $headers,
            );
        } catch (HttpTransportException $e) {
            throw new DevAiException('DevAI transport: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new DevAiException('DevAI encode: ' . $e->getMessage(), 0, $e);
        }

        $payload = $response->jsonObject();
        if ($response->status === 401) {
            throw new DevAiException('devai_auth_failed');
        }
        if ($response->status < 200 || $response->status >= 300) {
            $msg = is_array($payload)
                ? (string) ($payload['error']['message'] ?? $payload['message'] ?? json_encode($payload, JSON_UNESCAPED_UNICODE))
                : 'HTTP ' . $response->status;
            throw new DevAiException('devai_error: ' . $msg);
        }

        return is_array($payload) ? $payload : [];
    }
}
