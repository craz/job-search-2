<?php
declare(strict_types=1);

namespace JobSearch\Application\JobApplication;

use JobSearch\Domain\Chat\ChatSummary;
use JobSearch\Infrastructure\Cache\Cache;

/**
 * Локальная карта vacancy_external_id → резюме отклика (без Core applications).
 * Источники: наш Playwright apply и chatik (RESUME + resumeHashById).
 */
final class AppliedByVacancyStore
{
    public const CACHE_KEY = 'applied_by_vacancy';

    public function __construct(private readonly Cache $cache) {}

    /**
     * @return array<string, array{
     *   resume_external_id: string,
     *   resume_title: string,
     *   applied_at: string,
     *   source: string
     * }>
     */
    public function all(): array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        if ($entry === null || !is_array($entry->payload)) {
            return [];
        }
        $out = [];
        foreach ($entry->payload as $vacancyId => $row) {
            // json_decode превращает числовые ключи в int — нормализуем в string.
            $vacancyId = trim((string) $vacancyId);
            if ($vacancyId === '' || !is_array($row)) {
                continue;
            }
            $resumeId = trim((string) ($row['resume_external_id'] ?? ''));
            if ($resumeId === '') {
                continue;
            }
            $out[$vacancyId] = [
                'resume_external_id' => $resumeId,
                'resume_title' => trim((string) ($row['resume_title'] ?? '')),
                'applied_at' => trim((string) ($row['applied_at'] ?? '')),
                'source' => trim((string) ($row['source'] ?? '')),
            ];
        }

        return $out;
    }

    /** Запомнить резюме после нашего apply / already_applied. */
    public function rememberFromApply(
        string $vacancyExternalId,
        string $resumeExternalId,
        string $resumeTitle = '',
    ): void {
        $vacancyExternalId = trim($vacancyExternalId);
        $resumeExternalId = trim($resumeExternalId);
        if ($vacancyExternalId === '' || $resumeExternalId === '') {
            return;
        }
        $this->upsert(
            $vacancyExternalId,
            $resumeExternalId,
            $resumeTitle,
            'apply',
            true,
        );
    }

    /**
     * Дописать из синка чатов. Не затирает source=apply пустым/слабым chatik.
     *
     * @param list<ChatSummary> $chats
     */
    public function mergeFromChats(array $chats): void
    {
        $all = $this->all();
        $changed = false;
        foreach ($chats as $chat) {
            if (!$chat instanceof ChatSummary) {
                continue;
            }
            if ($chat->vacancyExternalId === '' || $chat->resumeExternalId === '') {
                continue;
            }
            if ($this->mergeRow(
                $all,
                $chat->vacancyExternalId,
                $chat->resumeExternalId,
                $chat->resumeTitle,
                'chatik',
                false,
            )) {
                $changed = true;
            }
        }
        if ($changed) {
            $this->cache->set(self::CACHE_KEY, $all);
        }
    }

    /**
     * @param array<string, string> $titlesByResumeId map hash → title (из FileCache resumes)
     */
    public function resolveTitles(array $titlesByResumeId): void
    {
        $all = $this->all();
        $changed = false;
        foreach ($all as $vacancyId => $row) {
            if ($row['resume_title'] !== '') {
                continue;
            }
            $title = trim((string) ($titlesByResumeId[$row['resume_external_id']] ?? ''));
            if ($title === '') {
                continue;
            }
            $all[$vacancyId]['resume_title'] = $title;
            $changed = true;
        }
        if ($changed) {
            $this->cache->set(self::CACHE_KEY, $all);
        }
    }

    private function upsert(
        string $vacancyExternalId,
        string $resumeExternalId,
        string $resumeTitle,
        string $source,
        bool $force,
    ): void {
        $all = $this->all();
        if ($this->mergeRow($all, $vacancyExternalId, $resumeExternalId, $resumeTitle, $source, $force)) {
            $this->cache->set(self::CACHE_KEY, $all);
        }
    }

    /**
     * @param array<string, array{
     *   resume_external_id: string,
     *   resume_title: string,
     *   applied_at: string,
     *   source: string
     * }> $all
     */
    private function mergeRow(
        array &$all,
        string $vacancyExternalId,
        string $resumeExternalId,
        string $resumeTitle,
        string $source,
        bool $force,
    ): bool {
        $prev = $all[$vacancyExternalId] ?? null;
        if ($prev !== null && !$force) {
            if (($prev['source'] ?? '') === 'apply') {
                // apply уже зафиксировал резюме — chatik только дополняет title
                if ($prev['resume_title'] === '' && $resumeTitle !== '') {
                    $prev['resume_title'] = $resumeTitle;
                    $all[$vacancyExternalId] = $prev;

                    return true;
                }

                return false;
            }
        }

        $all[$vacancyExternalId] = [
            'resume_external_id' => $resumeExternalId,
            'resume_title' => $resumeTitle !== ''
                ? $resumeTitle
                : (string) ($prev['resume_title'] ?? ''),
            'applied_at' => $force || $prev === null
                ? gmdate('c')
                : (string) ($prev['applied_at'] ?? gmdate('c')),
            'source' => $force ? $source : (string) ($prev['source'] ?? $source),
        ];

        return true;
    }
}
