<?php
declare(strict_types=1);

namespace JobSearch\Domain\Core;

/**
 * Контракт HTTP API job_search_ref Core (Postgres vacancies + SearchRun).
 */
interface CoreApi
{
    /**
     * Upsert вакансии: POST /api/v1/vacancies/ingest.
     *
     * @param array<string, mixed> $payload поля VacancyIngest
     *
     * @return array<string, mixed> VacancyIngestResult (outcome + vacancy)
     *
     * @throws CoreException
     */
    public function ingestVacancy(array $payload): array;

    /**
     * Старт SearchRun acquisition_kind=resume_suitable.
     *
     * @return array<string, mixed> SearchRunRead
     *
     * @throws CoreException
     */
    public function startResumeSuitableRun(string $hhResumeExternalId): array;

    /**
     * Добавить item в running SearchRun.
     *
     * @param array<string, mixed>|null $serpMeta
     *
     * @return array<string, mixed> SearchRunItemRead
     *
     * @throws CoreException
     */
    public function addSearchRunItem(
        string $runId,
        string $sourceExternalId,
        string $outcome,
        ?string $vacancyId = null,
        ?string $detailStatus = null,
        ?array $serpMeta = null,
    ): array;

    /**
     * Завершить SearchRun (success|partial|failed).
     *
     * @return array<string, mixed> SearchRunRead
     *
     * @throws CoreException
     */
    public function finalizeSearchRun(string $runId, string $status = 'success', ?int $sourceTotal = null): array;

    /**
     * Список SearchRun (новые первые).
     *
     * @return list<array<string, mixed>>
     *
     * @throws CoreException
     */
    public function listSearchRuns(): array;

    /**
     * Items одного SearchRun.
     *
     * @return list<array<string, mixed>>
     *
     * @throws CoreException
     */
    public function listSearchRunItems(string $runId): array;

    /**
     * Одна вакансия по Core id.
     *
     * @return array<string, mixed> VacancyRead
     *
     * @throws CoreException
     */
    public function getVacancy(string $vacancyId): array;

    /**
     * Найти Core id по HH external_id: POST /api/v1/vacancies/lookup-by-external-ids.
     *
     * @param list<string> $externalIds
     * @return array<string, string> external_id → vacancy_id (только найденные)
     *
     * @throws CoreException
     */
    public function lookupVacancyIdsByExternalIds(array $externalIds, string $source = 'hh'): array;

    /**
     * Явный source-status: POST /api/v1/vacancies/{id}/source-status.
     *
     * @return array<string, mixed> VacancyRead
     *
     * @throws CoreException
     */
    public function updateVacancySourceStatus(
        string $vacancyId,
        string $status,
        ?string $reason = null,
    ): array;

    /**
     * Активный candidate-context: GET /api/v1/candidate-context.
     *
     * @return array<string, mixed>
     *
     * @throws CoreException
     */
    public function getCandidateContext(): array;

    /**
     * Snapshot резюме в Core: POST /api/v1/resume-versions.
     *
     * @param array<string, mixed> $content allowlist snapshot
     *
     * @return array<string, mixed>
     *
     * @throws CoreException
     */
    public function ingestResumeVersion(
        string $externalResumeId,
        array $content,
        string $transport = 'fixture',
        ?string $extractorVersion = null,
    ): array;

    /**
     * Assessments: GET /api/v1/assessments (опционально filter vacancy_id).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     *
     * @throws CoreException
     */
    public function listAssessments(?string $vacancyId = null, int $limit = 20): array;

    /**
     * Создать assessment: POST /api/v1/assessments + Idempotency-Key.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws CoreException
     */
    public function createAssessment(array $payload, string $idempotencyKey): array;

    /**
     * Список откликов: GET /api/v1/applications.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     *
     * @throws CoreException
     */
    public function listApplications(): array;

    /**
     * Карточки чатов HH: GET /api/v1/hh-chats.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     *
     * @throws CoreException
     */
    public function listHhChats(): array;

    /**
     * Replace-all синк чатов HH: PUT /api/v1/hh-chats.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return array{total: int}
     *
     * @throws CoreException
     */
    public function replaceHhChats(array $items): array;

    /**
     * Бакеты «Найм»: GET /api/v1/post-application-tracking.
     *
     * @return array{waiting: array<string, mixed>, in_process: array<string, mixed>, completed: array<string, mixed>}
     *
     * @throws CoreException
     */
    public function getPostApplicationTracking(): array;

    /**
     * Дневной снимок: GET /api/v1/metrics/{date}.
     *
     * @return array<string, mixed>|null null если metric_not_found
     *
     * @throws CoreException
     */
    public function getDailyMetric(string $metricDate): ?array;

    /**
     * Записать/обновить дневной снимок: PUT /api/v1/metrics/{date} + Idempotency-Key.
     *
     * @param array<string, mixed> $fields DailyMetricUpdate
     *
     * @return array<string, mixed> DailyMetricRead
     *
     * @throws CoreException
     */
    public function setDailyMetric(string $metricDate, array $fields, string $idempotencyKey): array;

    /**
     * Создать черновик сопроводительного: POST /api/v1/cover-letters + Idempotency-Key.
     *
     * @param array<string, mixed> $payload CoverLetterCreate
     *
     * @return array<string, mixed> CoverLetterRead
     *
     * @throws CoreException
     */
    public function createCoverLetter(array $payload, string $idempotencyKey): array;

    /**
     * Обновить статус/текст письма: PATCH /api/v1/cover-letters/{id}.
     *
     * @param array<string, mixed> $fields CoverLetterUpdate (status, letter)
     *
     * @return array<string, mixed> CoverLetterRead
     *
     * @throws CoreException
     */
    public function updateCoverLetter(string $coverLetterId, array $fields): array;

    /**
     * Список сопроводительных: GET /api/v1/cover-letters.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     *
     * @throws CoreException
     */
    public function listCoverLetters(
        ?string $vacancyExternalId = null,
        ?string $resumeExternalId = null,
        ?string $status = null,
        int $limit = 50,
    ): array;

    /**
     * Журнал TG DM: POST /api/v1/telegram-dm-events.
     *
     * @param array<string, mixed> $payload TelegramDmEventCreate
     *
     * @return array<string, mixed> TelegramDmEventRead
     *
     * @throws CoreException
     */
    public function createTelegramDmEvent(array $payload): array;

    /**
     * Список TG DM: GET /api/v1/telegram-dm-events.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     *
     * @throws CoreException
     */
    public function listTelegramDmEvents(
        ?string $kind = null,
        ?string $vacancyExternalId = null,
        int $limit = 50,
    ): array;
}
