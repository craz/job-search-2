<?php
declare(strict_types=1);

namespace JobSearch\Application\Error;

use JobSearch\Application\JobApplication\AppliedByVacancyStore;
use JobSearch\Domain\Chat\ChatSummary;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;

/**
 * Открытая ошибка apply + чат HH по той же vacancy → закрыть ошибку и пометить applied.
 * Мост нужен, потому что chatik часто без resume_external_id, а ошибка знает резюме.
 */
final class ReconcileApplyErrorsFromChats
{
    public function __construct(
        private readonly AppErrorStore $errors,
        private readonly ?AppliedByVacancyStore $applied = null,
        private readonly ?CoreApi $core = null,
    ) {}

    /**
     * @param list<ChatSummary|array<string, mixed>>|null $chats null = взять из Core hh_chats
     *
     * @return int сколько ошибок закрыто
     */
    public function run(?array $chats = null): int
    {
        $vacancyIds = $this->vacancyIds($chats);
        if ($vacancyIds === []) {
            return 0;
        }

        $resolved = $this->errors->reconcileOpenAgainstVacancies(
            $vacancyIds,
            'Исправлено — есть чат HH (отклик уже ушёл).',
        );
        foreach ($resolved as $pair) {
            $this->applied?->rememberFromApply(
                $pair['vacancy_id'],
                $pair['resume_id'],
                $pair['resume_title'],
            );
        }

        return count($resolved);
    }

    /**
     * @param list<ChatSummary|array<string, mixed>>|null $chats
     *
     * @return array<string, true>
     */
    private function vacancyIds(?array $chats): array
    {
        if ($chats === null) {
            $chats = $this->loadCoreChats();
        }
        $out = [];
        foreach ($chats as $chat) {
            if ($chat instanceof ChatSummary) {
                $ext = trim($chat->vacancyExternalId);
            } elseif (is_array($chat)) {
                $ext = trim((string) ($chat['vacancy_external_id'] ?? ''));
            } else {
                continue;
            }
            if ($ext !== '') {
                $out[$ext] = true;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function loadCoreChats(): array
    {
        if ($this->core === null) {
            return [];
        }
        try {
            $items = $this->core->listHhChats()['items'] ?? [];
        } catch (CoreException) {
            return [];
        }

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
