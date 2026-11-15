<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;
use JobSearch\Infrastructure\Cache\Cache;

/**
 * Use case: довести candidate-context до synced (текст резюме из FileCache → Core).
 *
 * Scoring/DevAI не стартует без resume_content.content_state=synced.
 */
final class SyncResumeContext
{
    public function __construct(
        private readonly CoreApi $core,
        private readonly Cache $cache,
    ) {}

    /**
     * @return array{ok: bool, synced: bool, created: bool, message: string, external_resume_id?: string}
     */
    public function ensureSynced(?string $preferResumeId = null): array
    {
        try {
            $ctx = $this->core->getCandidateContext();
        } catch (CoreException $e) {
            return ['ok' => false, 'synced' => false, 'created' => false, 'message' => $e->getMessage()];
        }

        $meta = is_array($ctx['resume_content'] ?? null) ? $ctx['resume_content'] : [];
        $state = (string) ($meta['content_state'] ?? '');
        if ($state === 'synced' && trim((string) ($meta['resume_version_id'] ?? '')) !== '') {
            return [
                'ok' => true,
                'synced' => true,
                'created' => false,
                'message' => 'candidate-context already synced',
                'external_resume_id' => (string) ($meta['external_resume_id'] ?? ''),
            ];
        }

        $link = is_array($ctx['hh_resume_link'] ?? null) ? $ctx['hh_resume_link'] : [];
        $externalId = trim((string) ($preferResumeId ?: ($link['external_resume_id'] ?? ($meta['external_resume_id'] ?? ''))));
        if ($externalId === '') {
            return [
                'ok' => false,
                'synced' => false,
                'created' => false,
                'message' => 'Нет HH resume id для sync (выберите резюме в Core / обновите данные).',
            ];
        }

        $snap = $this->textFromCache($externalId);
        if ($snap === null) {
            return [
                'ok' => false,
                'synced' => false,
                'created' => false,
                'message' => 'Текст резюме не в кеше. Нажмите «Обновить данные».',
                'external_resume_id' => $externalId,
            ];
        }

        $about = $this->scrubPii($snap['text']);
        if ($about === '') {
            return [
                'ok' => false,
                'synced' => false,
                'created' => false,
                'message' => 'Пустой текст резюме после очистки.',
                'external_resume_id' => $externalId,
            ];
        }

        $title = $snap['title'] !== '' ? $snap['title'] : 'Resume';
        try {
            $result = $this->core->ingestResumeVersion(
                $externalId,
                [
                    'title' => $title,
                    'desired_position' => $title,
                    'about' => mb_substr($about, 0, 20000),
                ],
                'fixture',
                'job_search_2-plain-v1',
            );
        } catch (CoreException $e) {
            return [
                'ok' => false,
                'synced' => false,
                'created' => false,
                'message' => $e->getMessage(),
                'external_resume_id' => $externalId,
            ];
        }

        return [
            'ok' => true,
            'synced' => true,
            'created' => (bool) ($result['created'] ?? false),
            'message' => 'ResumeVersion synced into Core',
            'external_resume_id' => $externalId,
        ];
    }

    /** @return array{title: string, text: string}|null */
    private function textFromCache(string $externalId): ?array
    {
        $entry = $this->cache->get('resume_texts');
        $payload = is_array($entry?->payload) ? $entry->payload : null;
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['external_id'] ?? '') !== $externalId) {
                continue;
            }
            $text = isset($row['text']) && is_string($row['text']) ? trim($row['text']) : '';
            if ($text === '') {
                return null;
            }

            return [
                'title' => (string) ($row['title'] ?? ''),
                'text' => $text,
            ];
        }

        return null;
    }

    private function scrubPii(string $text): string
    {
        $out = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '', $text) ?? '';
        $out = preg_replace('/(?:\+?\d[\d\-\s()]{8,}\d)/', '', $out) ?? '';
        $out = preg_replace('/\s+/u', ' ', $out) ?? '';

        return trim($out);
    }
}
