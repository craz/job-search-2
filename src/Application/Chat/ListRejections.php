<?php
declare(strict_types=1);

namespace JobSearch\Application\Chat;

use JobSearch\Application\JobApplication\AppliedByVacancyStore;
use JobSearch\Domain\Chat\ChatStore;
use JobSearch\Domain\Chat\ChatSummary;

/**
 * Плоский журнал отказов из hh_chats (DISCARD / «Отказ») для сверки полноты.
 */
final class ListRejections
{
    public function __construct(
        private readonly ChatStore $store,
        private readonly ?AppliedByVacancyStore $appliedResumes = null,
        private readonly ?ChatRejectionArchive $archive = null,
    ) {}

    /**
     * @return array{
     *   total_chats: int,
     *   total: int,
     *   shown: int,
     *   query: string,
     *   vacancy_id: string,
     *   synced_at: string,
     *   items: list<array<string, mixed>>
     * }
     */
    public function page(string $query = ''): array
    {
        $all = $this->store->listAll();
        $syncedAt = '';
        foreach ($all as $item) {
            if ($item->syncedAt !== '' && ($syncedAt === '' || $item->syncedAt > $syncedAt)) {
                $syncedAt = $item->syncedAt;
            }
        }

        /** @var array<string, ChatSummary> $byKey */
        $byKey = [];
        foreach ($all as $chat) {
            if (!$chat->isRejection()) {
                continue;
            }
            $byKey[$this->dedupeKey($chat)] = $chat->withClosedVacancyNormalized();
        }
        foreach ($this->archive?->all() ?? [] as $chat) {
            if (!$chat->isRejection()) {
                continue;
            }
            $chat = $chat->withClosedVacancyNormalized();
            $key = $this->dedupeKey($chat);
            if (!isset($byKey[$key])) {
                $byKey[$key] = $chat;
            }
        }
        $rejections = array_values($byKey);

        usort(
            $rejections,
            static function (ChatSummary $a, ChatSummary $b): int {
                $atA = $a->lastMessageAt !== '' ? $a->lastMessageAt : $a->lastActivityAt;
                $atB = $b->lastMessageAt !== '' ? $b->lastMessageAt : $b->lastActivityAt;

                return strcmp($atB, $atA);
            },
        );

        $query = trim($query);
        $vacancyId = $this->vacancyIdFromQuery($query);
        $items = $rejections;
        if ($query !== '') {
            $items = array_values(array_filter(
                $rejections,
                function (ChatSummary $chat) use ($query, $vacancyId): bool {
                    if ($vacancyId !== '') {
                        return $chat->vacancyExternalId === $vacancyId;
                    }
                    $hay = mb_strtolower(
                        $chat->vacancyTitle . ' ' . $chat->companyName . ' ' . $chat->vacancyExternalId
                        . ' ' . $chat->resumeTitle . ' ' . $chat->resumeExternalId
                        . ' ' . $chat->statusLabel,
                        'UTF-8',
                    );

                    return str_contains($hay, mb_strtolower($query, 'UTF-8'));
                },
            ));
        }

        $map = $this->appliedResumes?->all() ?? [];

        return [
            'total_chats' => count($all),
            'total' => count($rejections),
            'shown' => count($items),
            'query' => $query,
            'vacancy_id' => $vacancyId,
            'synced_at' => $syncedAt,
            'items' => array_map(
                function (ChatSummary $c) use ($map): array {
                    $row = $c->toArray();
                    $row['vacancy_closed'] = $c->isVacancyClosed();
                    $row['applicant_state'] = $c->displayApplicantState();
                    $row['status_label'] = $c->displayStatusLabel();
                    $vid = $c->vacancyExternalId;
                    $meta = $map[$vid] ?? null;
                    if ($meta === null && $vid !== '' && ctype_digit($vid)) {
                        $meta = $map[(int) $vid] ?? null;
                    }
                    if (is_array($meta)) {
                        if (($row['resume_external_id'] ?? '') === '') {
                            $row['resume_external_id'] = (string) ($meta['resume_external_id'] ?? '');
                        }
                        if (($row['resume_title'] ?? '') === '') {
                            $row['resume_title'] = (string) ($meta['resume_title'] ?? '');
                        }
                    }

                    return $row;
                },
                $items,
            ),
        ];
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

    private function vacancyIdFromQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        if (preg_match('~(?:hh\.ru)?/vacancy/(\d+)~i', $query, $m) === 1) {
            return $m[1];
        }
        if (preg_match('~^\d{5,}$~', $query) === 1) {
            return $query;
        }

        return '';
    }
}
