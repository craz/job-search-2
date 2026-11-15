<?php
declare(strict_types=1);

namespace JobSearch\Application\JobApplication;

use JobSearch\Domain\Core\CoreApi;

/**
 * Use case: список откликов из Core (applications + tracking-бакеты).
 */
final class ListPage
{
    /** Короткие ярлыки известных resume_version (HH external id). */
    private const RESUME_LABELS = [
        'd511f1d1ff1145b22e0039ed1f4e6946685076' => 'TPM',
        'f3e5e5f7ff0f50d3e50039ed1f4436664d7338' => 'PM',
        'd7cf4e9eff11521f2e0039ed1f504a67754631' => 'PHP',
    ];

    private const BUCKET_LABELS = [
        'waiting' => 'Ждём ответ',
        'in_process' => 'В работе',
        'completed' => 'Завершено',
        'unknown' => 'Без статуса',
    ];

    public function __construct(private readonly CoreApi $core) {}

    /**
     * Данные для страницы откликов.
     *
     * @return array{
     *     total: int,
     *     counts: array{waiting: int, in_process: int, completed: int, unknown: int},
     *     items: list<array<string, mixed>>,
     *     bucket: string
     * }
     */
    public function listPage(?string $bucket = null): array
    {
        $bucket = $this->normalizeBucket($bucket);
        $apps = $this->core->listApplications();
        $tracking = $this->core->getPostApplicationTracking();
        $byVacancy = $this->indexTracking($tracking);

        $items = [];
        foreach ($apps['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = $this->mapRow($row, $byVacancy);
            if ($bucket !== 'all' && $mapped['bucket'] !== $bucket) {
                continue;
            }
            $items[] = $mapped;
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp((string) $b['applied_at'], (string) $a['applied_at']),
        );

        $counts = [
            'waiting' => $this->bucketCount($tracking, 'waiting'),
            'in_process' => $this->bucketCount($tracking, 'in_process'),
            'completed' => $this->bucketCount($tracking, 'completed'),
            'unknown' => 0,
        ];
        foreach ($apps['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $vacancy = is_array($row['vacancy'] ?? null) ? $row['vacancy'] : [];
            $vid = (string) ($vacancy['id'] ?? '');
            if ($vid !== '' && !isset($byVacancy[$vid])) {
                $counts['unknown']++;
            }
        }

        return [
            'total' => (int) $apps['total'],
            'counts' => $counts,
            'items' => $items,
            'bucket' => $bucket,
        ];
    }

    /**
     * @param array<string, mixed> $tracking
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexTracking(array $tracking): array
    {
        $out = [];
        foreach (['waiting', 'in_process', 'completed'] as $name) {
            $bucket = is_array($tracking[$name] ?? null) ? $tracking[$name] : [];
            $list = is_array($bucket['items'] ?? null) ? $bucket['items'] : [];
            foreach ($list as $card) {
                if (!is_array($card)) {
                    continue;
                }
                $vid = (string) ($card['vacancy_id'] ?? '');
                if ($vid === '') {
                    continue;
                }
                $out[$vid] = $card;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $byVacancy
     *
     * @return array<string, mixed>
     */
    private function mapRow(array $row, array $byVacancy): array
    {
        $vacancy = is_array($row['vacancy'] ?? null) ? $row['vacancy'] : [];
        $vacancyId = (string) ($vacancy['id'] ?? '');
        $track = $byVacancy[$vacancyId] ?? null;
        $bucket = is_array($track) ? (string) ($track['bucket'] ?? 'unknown') : 'unknown';
        if ($bucket === '') {
            $bucket = 'unknown';
        }

        $externalId = (string) ($row['external_id'] ?? '');
        $hhId = (string) ($vacancy['external_id'] ?? '');
        if ($hhId === '') {
            $hhId = $this->hhVacancyId($externalId);
        }
        $url = (string) ($vacancy['url'] ?? '');
        if ($url === '' && $hhId !== '') {
            $url = 'https://hh.ru/vacancy/' . rawurlencode($hhId);
        }

        $resumeVersion = (string) ($row['resume_version'] ?? '');
        $appliedAt = (string) ($row['applied_at'] ?? $row['created_at'] ?? '');
        $title = (string) ($vacancy['title'] ?? '');
        if ($title === '' && is_array($track)) {
            $title = (string) ($track['vacancy_title'] ?? '');
        }
        if ($title === '') {
            $title = 'Без названия';
        }

        $company = (string) ($vacancy['company_name'] ?? '');
        if ($company === '' && is_array($track)) {
            $company = (string) ($track['company_name'] ?? '');
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'vacancy_id' => $vacancyId,
            'title' => $title,
            'company' => $company,
            'url' => $url,
            'hh_id' => $hhId,
            'applied_at' => $appliedAt,
            'resume_version' => $resumeVersion,
            'resume_label' => self::RESUME_LABELS[$resumeVersion] ?? ($resumeVersion !== '' ? substr($resumeVersion, 0, 8) : '—'),
            'bucket' => $bucket,
            'bucket_label' => self::BUCKET_LABELS[$bucket] ?? $bucket,
            'result' => $row['result'] ?? (is_array($track) ? ($track['application_result'] ?? null) : null),
            'response_type' => is_array($track) ? ($track['latest_response_type'] ?? null) : null,
            'reason' => is_array($track) ? (string) ($track['reason'] ?? '') : '',
        ];
    }

    private function hhVacancyId(string $externalId): string
    {
        if (preg_match('/(\d{6,})/', $externalId, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    private function normalizeBucket(?string $bucket): string
    {
        $bucket = strtolower(trim((string) $bucket));
        if (in_array($bucket, ['waiting', 'in_process', 'completed', 'unknown', 'all'], true)) {
            return $bucket;
        }

        return 'all';
    }

    /** @param array<string, mixed> $tracking */
    private function bucketCount(array $tracking, string $name): int
    {
        $bucket = is_array($tracking[$name] ?? null) ? $tracking[$name] : [];
        $items = is_array($bucket['items'] ?? null) ? $bucket['items'] : [];

        return count($items);
    }
}
