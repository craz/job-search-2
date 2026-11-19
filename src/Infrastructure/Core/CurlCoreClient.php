<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Core;


use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\Http\CurlHttpClient;
use JobSearch\Infrastructure\Http\HttpClient;
use JobSearch\Infrastructure\Http\HttpTransportException;
use JobSearch\Infrastructure\Text\Utf8;

/**
 * Core API поверх HttpClient (по умолчанию curl без прокси — localhost).
 */
final class CurlCoreClient implements CoreApi
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
    ) {}

    public static function fromConfig(Config $config, ?HttpClient $http = null): self
    {
        $base = rtrim($config->get('CORE_API_URL', 'http://127.0.0.1:18000') ?? 'http://127.0.0.1:18000', '/');

        // Core на localhost — без HH_PROXY. Короткий timeout: UI на php -S не клинит навечно.
        return new self($http ?? new CurlHttpClient('', 15, 3), $base);
    }

    public function ingestVacancy(array $payload): array
    {
        return $this->requestJson('POST', '/api/v1/vacancies/ingest', $payload);
    }

    public function startResumeSuitableRun(string $hhResumeExternalId): array
    {
        $hhResumeExternalId = trim($hhResumeExternalId);
        if ($hhResumeExternalId === '') {
            throw new CoreException('hh_resume_external_id is empty');
        }

        return $this->requestJson('POST', '/api/v1/search-runs', [
            'acquisition_kind' => 'resume_suitable',
            'candidate_context_snapshot' => [
                'hh_resume_external_id' => $hhResumeExternalId,
            ],
            'execution' => [
                'order' => 'publication_time',
                'page_size' => 10,
                'max_pages' => 1,
            ],
        ]);
    }

    public function addSearchRunItem(
        string $runId,
        string $sourceExternalId,
        string $outcome,
        ?string $vacancyId = null,
        ?string $detailStatus = null,
        ?array $serpMeta = null,
    ): array {
        $body = [
            'source_external_id' => $sourceExternalId,
            'outcome' => $outcome,
        ];
        if ($vacancyId !== null && $vacancyId !== '') {
            $body['vacancy_id'] = $vacancyId;
        }
        if ($detailStatus !== null && $detailStatus !== '') {
            $body['detail_status'] = $detailStatus;
        }
        if ($serpMeta !== null) {
            $body['serp_meta'] = $serpMeta;
        }

        return $this->requestJson('POST', '/api/v1/search-runs/' . rawurlencode($runId) . '/items', $body);
    }

    public function finalizeSearchRun(string $runId, string $status = 'success', ?int $sourceTotal = null): array
    {
        $body = ['status' => $status];
        if ($sourceTotal !== null) {
            $body['source_total'] = $sourceTotal;
        }

        return $this->requestJson('POST', '/api/v1/search-runs/' . rawurlencode($runId) . '/finalize', $body);
    }

    public function listSearchRuns(): array
    {
        $payload = $this->requestJson('GET', '/api/v1/search-runs');
        $items = $payload['items'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    public function listSearchRunItems(string $runId): array
    {
        $payload = $this->requestJson('GET', '/api/v1/search-runs/' . rawurlencode($runId) . '/items');
        $items = $payload['items'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    public function getVacancy(string $vacancyId): array
    {
        return $this->requestJson('GET', '/api/v1/vacancies/' . rawurlencode($vacancyId));
    }

    public function lookupVacancyIdsByExternalIds(array $externalIds, string $source = 'hh'): array
    {
        $source = trim($source) !== '' ? trim($source) : 'hh';
        $ids = [];
        foreach ($externalIds as $id) {
            if (!is_string($id) && !is_int($id)) {
                continue;
            }
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $payload = $this->requestJson('POST', '/api/v1/vacancies/lookup-by-external-ids', [
            'source' => $source,
            'external_ids' => $ids,
        ]);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ext = trim((string) ($row['external_id'] ?? ''));
            $vid = trim((string) ($row['vacancy_id'] ?? ''));
            if ($ext !== '' && $vid !== '') {
                $out[$ext] = $vid;
            }
        }

        return $out;
    }

    public function updateVacancySourceStatus(
        string $vacancyId,
        string $status,
        ?string $reason = null,
    ): array {
        $vacancyId = trim($vacancyId);
        $status = trim($status);
        if ($vacancyId === '' || $status === '') {
            throw new CoreException('vacancy_id/status is empty');
        }

        $body = ['status' => $status];
        if ($reason !== null && $reason !== '') {
            $body['reason'] = $reason;
        }

        return $this->requestJson(
            'POST',
            '/api/v1/vacancies/' . rawurlencode($vacancyId) . '/source-status',
            $body,
        );
    }

    public function getCandidateContext(): array
    {
        return $this->requestJson('GET', '/api/v1/candidate-context');
    }

    public function ingestResumeVersion(
        string $externalResumeId,
        array $content,
        string $transport = 'fixture',
        ?string $extractorVersion = null,
    ): array {
        $externalResumeId = trim($externalResumeId);
        if ($externalResumeId === '') {
            throw new CoreException('external_resume_id is empty');
        }

        $body = [
            'source' => 'hh',
            'external_resume_id' => $externalResumeId,
            'transport' => $transport !== '' ? $transport : 'fixture',
            'content' => $content,
        ];
        if ($extractorVersion !== null && $extractorVersion !== '') {
            $body['extractor_version'] = $extractorVersion;
        }

        return $this->requestJson('POST', '/api/v1/resume-versions', $body);
    }

    public function listAssessments(?string $vacancyId = null, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $query = ['limit' => (string) $limit];
        $vacancyId = $vacancyId !== null ? trim($vacancyId) : '';
        if ($vacancyId !== '') {
            $query['vacancy_id'] = $vacancyId;
        }
        $payload = $this->requestJson(
            'GET',
            '/api/v1/assessments?' . http_build_query($query),
        );
        $items = $payload['items'] ?? [];
        $total = $payload['total'] ?? (is_array($items) ? count($items) : 0);

        return [
            'items' => is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
            'total' => (int) $total,
        ];
    }

    public function createAssessment(array $payload, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new CoreException('Idempotency-Key required');
        }

        return $this->requestJson(
            'POST',
            '/api/v1/assessments',
            $payload,
            ['Idempotency-Key' => $idempotencyKey],
        );
    }

    public function listApplications(): array
    {
        $payload = $this->requestJson('GET', '/api/v1/applications');
        $items = $payload['items'] ?? [];
        $total = $payload['total'] ?? (is_array($items) ? count($items) : 0);

        return [
            'items' => is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
            'total' => (int) $total,
        ];
    }

    public function listHhChats(): array
    {
        $payload = $this->requestJson('GET', '/api/v1/hh-chats');
        $items = $payload['items'] ?? [];
        $total = $payload['total'] ?? (is_array($items) ? count($items) : 0);

        return [
            'items' => is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
            'total' => (int) $total,
        ];
    }

    public function replaceHhChats(array $items): array
    {
        $payload = $this->requestJson('PUT', '/api/v1/hh-chats', ['items' => array_values($items)]);

        return ['total' => (int) ($payload['total'] ?? 0)];
    }

    public function getPostApplicationTracking(): array
    {
        return $this->requestJson('GET', '/api/v1/post-application-tracking');
    }

    public function getDailyMetric(string $metricDate): ?array
    {
        $metricDate = trim($metricDate);
        if ($metricDate === '') {
            throw new CoreException('metric_date is empty');
        }

        try {
            return $this->requestJson('GET', '/api/v1/metrics/' . rawurlencode($metricDate));
        } catch (CoreException $e) {
            if ($e->httpStatus === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function setDailyMetric(string $metricDate, array $fields, string $idempotencyKey): array
    {
        $metricDate = trim($metricDate);
        $idempotencyKey = trim($idempotencyKey);
        if ($metricDate === '' || $idempotencyKey === '') {
            throw new CoreException('metric_date / Idempotency-Key required');
        }

        return $this->requestJson(
            'PUT',
            '/api/v1/metrics/' . rawurlencode($metricDate),
            $fields,
            ['Idempotency-Key' => $idempotencyKey],
        );
    }

    public function createCoverLetter(array $payload, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new CoreException('Idempotency-Key required');
        }

        return $this->requestJson(
            'POST',
            '/api/v1/cover-letters',
            $payload,
            ['Idempotency-Key' => $idempotencyKey],
        );
    }

    public function updateCoverLetter(string $coverLetterId, array $fields): array
    {
        $coverLetterId = trim($coverLetterId);
        if ($coverLetterId === '') {
            throw new CoreException('cover_letter_id required');
        }

        return $this->requestJson(
            'PATCH',
            '/api/v1/cover-letters/' . rawurlencode($coverLetterId),
            $fields,
        );
    }

    public function listCoverLetters(
        ?string $vacancyExternalId = null,
        ?string $resumeExternalId = null,
        ?string $status = null,
        int $limit = 50,
    ): array {
        $limit = max(1, min(200, $limit));
        $query = ['limit' => (string) $limit];
        $vacancyExternalId = $vacancyExternalId !== null ? trim($vacancyExternalId) : '';
        $resumeExternalId = $resumeExternalId !== null ? trim($resumeExternalId) : '';
        $status = $status !== null ? trim($status) : '';
        if ($vacancyExternalId !== '') {
            $query['vacancy_external_id'] = $vacancyExternalId;
        }
        if ($resumeExternalId !== '') {
            $query['resume_external_id'] = $resumeExternalId;
        }
        if ($status !== '') {
            $query['status'] = $status;
        }
        $payload = $this->requestJson(
            'GET',
            '/api/v1/cover-letters?' . http_build_query($query),
        );
        $items = $payload['items'] ?? [];
        $total = $payload['total'] ?? (is_array($items) ? count($items) : 0);

        return [
            'items' => is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
            'total' => (int) $total,
        ];
    }

    public function createTelegramDmEvent(array $payload): array
    {
        return $this->requestJson('POST', '/api/v1/telegram-dm-events', $payload);
    }

    public function listTelegramDmEvents(
        ?string $kind = null,
        ?string $vacancyExternalId = null,
        int $limit = 50,
    ): array {
        $limit = max(1, min(500, $limit));
        $query = ['limit' => (string) $limit];
        $kind = $kind !== null ? trim($kind) : '';
        $vacancyExternalId = $vacancyExternalId !== null ? trim($vacancyExternalId) : '';
        if ($kind !== '') {
            $query['kind'] = $kind;
        }
        if ($vacancyExternalId !== '') {
            $query['vacancy_external_id'] = $vacancyExternalId;
        }
        $payload = $this->requestJson(
            'GET',
            '/api/v1/telegram-dm-events?' . http_build_query($query),
        );
        $items = $payload['items'] ?? [];
        $total = $payload['total'] ?? (is_array($items) ? count($items) : 0);

        return [
            'items' => is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
            'total' => (int) $total,
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $extraHeaders
     *
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        string $path,
        ?array $body = null,
        array $extraHeaders = [],
    ): array {
        $url = $this->baseUrl . $path;
        $headers = [
            'Accept' => 'application/json',
            ...$extraHeaders,
        ];

        try {
            if ($method === 'GET') {
                $response = $this->http->get($url, $headers);
            } else {
                $payload = $body === null ? new \stdClass() : Utf8::scrubMixed($body);
                $json = json_encode($payload, Utf8::jsonFlags(JSON_THROW_ON_ERROR));
                $headers['Content-Type'] = 'application/json';
                $response = match ($method) {
                    'PUT' => $this->http->put($url, $json, $headers),
                    'PATCH' => $this->http->patch($url, $json, $headers),
                    default => $this->http->post($url, $json, $headers),
                };
            }
        } catch (HttpTransportException $e) {
            throw new CoreException('Core transport: ' . $e->getMessage(), 0, '', $e);
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw new CoreException(
                "Core {$method} {$path} HTTP {$response->status}",
                $response->status,
                substr($response->body, 0, 800),
            );
        }

        try {
            return $response->jsonObject();
        } catch (\JsonException $e) {
            throw new CoreException(
                "Core {$method} {$path}: non-JSON body",
                $response->status,
                substr($response->body, 0, 500),
                $e,
            );
        }
    }
}
