<?php
declare(strict_types=1);

namespace JobSearch\Application\Company;

use JobSearch\Application\Chat\ChatRejectionArchive;
use JobSearch\Application\Resume\ResumeViewsStore;
use JobSearch\Domain\Chat\ChatStore;
use JobSearch\Domain\Chat\ChatSummary;

/**
 * Индекс компаний с хотя бы одним отказом в чатах HH (для бейджа на вакансиях).
 */
final class CompanyRejectionIndex
{
    public function __construct(
        private readonly ChatStore $chats,
        private readonly ?ChatRejectionArchive $archive = null,
    ) {}

    /**
     * @return array{
     *   by_id: array<string, string>,
     *   by_name: array<string, string>
     * } value = краткая подсказка (вакансия отказа)
     */
    public function index(): array
    {
        /** @var array<string, string> $byId */
        $byId = [];
        /** @var array<string, string> $byName */
        $byName = [];

        foreach ($this->rejectionChats() as $chat) {
            $chat = $chat->withClosedVacancyNormalized();
            $tip = trim($chat->vacancyTitle);
            if ($tip === '') {
                $tip = trim($chat->vacancyExternalId);
            }
            if ($tip === '') {
                $tip = 'уже отказывала';
            }

            $companyName = trim($chat->companyName);
            $norm = ResumeViewsStore::normalizeName($companyName);
            if ($norm !== '' && !isset($byName[$norm])) {
                $byName[$norm] = $tip;
            }

            // У чатов нет employer id — только имя. id подтянется при матче вакансии по имени.
            unset($chat);
        }

        return ['by_id' => $byId, 'by_name' => $byName];
    }

    /** @return list<ChatSummary> */
    private function rejectionChats(): array
    {
        /** @var array<string, ChatSummary> $byKey */
        $byKey = [];
        foreach ($this->chats->listAll() as $chat) {
            if (!$chat->isRejection()) {
                continue;
            }
            $byKey[$this->dedupeKey($chat)] = $chat->withClosedVacancyNormalized();
        }
        foreach ($this->archive?->all() ?? [] as $chat) {
            if (!$chat->isRejection()) {
                continue;
            }
            $key = $this->dedupeKey($chat);
            if (!isset($byKey[$key])) {
                $byKey[$key] = $chat->withClosedVacancyNormalized();
            }
        }

        return array_values($byKey);
    }

    private function dedupeKey(ChatSummary $chat): string
    {
        if ($chat->chatId !== '') {
            return 'c:' . $chat->chatId;
        }
        if ($chat->vacancyExternalId !== '') {
            return 'v:' . $chat->vacancyExternalId;
        }

        return 't:' . mb_strtolower($chat->companyName . '|' . $chat->vacancyTitle, 'UTF-8');
    }

    /**
     * @param array{by_id: array<string, string>, by_name: array<string, string>} $index
     * @param array<string, mixed> $vacancy
     */
    public static function hintFor(array $index, array $vacancy): ?string
    {
        $companyExt = trim((string) ($vacancy['company_external_id'] ?? ''));
        if ($companyExt !== '' && !str_starts_with($companyExt, 'name:')) {
            $byId = $index['by_id'] ?? [];
            if (isset($byId[$companyExt])) {
                return $byId[$companyExt];
            }
        }

        $norm = ResumeViewsStore::normalizeName((string) ($vacancy['company_name'] ?? ''));
        if ($norm === '') {
            return null;
        }
        $byName = $index['by_name'] ?? [];

        return $byName[$norm] ?? null;
    }

    /**
     * Дописать employer id, если в suitable уже есть company_external_id у той же компании.
     *
     * @param array{by_id: array<string, string>, by_name: array<string, string>} $index
     * @param list<array<string, mixed>> $vacancies
     *
     * @return array{by_id: array<string, string>, by_name: array<string, string>}
     */
    public static function enrichIdsFromVacancies(array $index, array $vacancies): array
    {
        $byId = $index['by_id'] ?? [];
        $byName = $index['by_name'] ?? [];
        foreach ($vacancies as $row) {
            if (!is_array($row)) {
                continue;
            }
            $norm = ResumeViewsStore::normalizeName((string) ($row['company_name'] ?? ''));
            if ($norm === '' || !isset($byName[$norm])) {
                continue;
            }
            $companyExt = trim((string) ($row['company_external_id'] ?? ''));
            if ($companyExt === '' || str_starts_with($companyExt, 'name:')) {
                continue;
            }
            if (!isset($byId[$companyExt])) {
                $byId[$companyExt] = $byName[$norm];
            }
        }

        return ['by_id' => $byId, 'by_name' => $byName];
    }
}
