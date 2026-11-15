<?php
declare(strict_types=1);

namespace JobSearch\Application\Chat;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use JobSearch\Application\JobApplication\AppliedByVacancyStore;
use JobSearch\Domain\Chat\ChatStore;
use JobSearch\Domain\Chat\ChatSummary;

/**
 * Use case: прочитать сохранённые карточки чатов для UI (live ∪ локальный архив).
 */
final class ListChats
{
    public function __construct(
        private readonly ChatStore $store,
        private readonly ?AppliedByVacancyStore $appliedResumes = null,
        private readonly ?ChatHistoryArchive $historyArchive = null,
    ) {}

    /**
     * @return array{
     *   total: int,
     *   shown: int,
     *   live_count: int,
     *   archived_count: int,
     *   rejection_count: int,
     *   old_count: int,
     *   filter: string,
     *   query: string,
     *   vacancy_id: string,
     *   items: list<array<string, mixed>>,
     *   synced_at: string
     * }
     */
    public function page(string $query = '', string $filter = 'all'): array
    {
        $filter = match (strtolower(trim($filter))) {
            'live', 'hh' => 'live',
            'archive', 'archived' => 'archive',
            default => 'all',
        };
        $live = $this->store->listAll();
        /** @var array<string, ChatSummary> $byKey */
        $byKey = [];
        /** @var array<string, true> $liveKeys */
        $liveKeys = [];
        $syncedAt = '';
        foreach ($live as $item) {
            $key = $this->dedupeKey($item);
            $byKey[$key] = $item;
            $liveKeys[$key] = true;
            if ($item->syncedAt !== '' && ($syncedAt === '' || $item->syncedAt > $syncedAt)) {
                $syncedAt = $item->syncedAt;
            }
        }

        $archivedOnly = 0;
        foreach ($this->historyArchive?->all() ?? [] as $item) {
            $key = $this->dedupeKey($item);
            if (isset($liveKeys[$key])) {
                continue;
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = $item;
                $archivedOnly++;
            }
        }

        $all = array_values($byKey);
        // Live сверху (порядок HH), архив — по дате сообщения.
        usort($all, function (ChatSummary $a, ChatSummary $b) use ($liveKeys): int {
            $aLive = isset($liveKeys[$this->dedupeKey($a)]);
            $bLive = isset($liveKeys[$this->dedupeKey($b)]);
            if ($aLive !== $bLive) {
                return $aLive ? -1 : 1;
            }
            if ($aLive) {
                return $a->listPosition <=> $b->listPosition;
            }
            $atA = $a->lastMessageAt !== '' ? $a->lastMessageAt : $a->lastActivityAt;
            $atB = $b->lastMessageAt !== '' ? $b->lastMessageAt : $b->lastActivityAt;

            return strcmp($atB, $atA);
        });

        $rejectionCount = 0;
        foreach ($live as $item) {
            if ($item->isRejection()) {
                $rejectionCount++;
            }
        }

        $query = trim($query);
        $vacancyId = $this->vacancyIdFromQuery($query);
        $items = $all;
        if ($filter === 'live') {
            $items = array_values(array_filter(
                $items,
                fn (ChatSummary $chat): bool => isset($liveKeys[$this->dedupeKey($chat)]),
            ));
        } elseif ($filter === 'archive') {
            $items = array_values(array_filter(
                $items,
                fn (ChatSummary $chat): bool => !isset($liveKeys[$this->dedupeKey($chat)]),
            ));
        }
        if ($query !== '') {
            $items = array_values(array_filter(
                $items,
                function (ChatSummary $chat) use ($query, $vacancyId): bool {
                    if ($vacancyId !== '') {
                        return $chat->vacancyExternalId === $vacancyId;
                    }
                    $hay = mb_strtolower(
                        $chat->vacancyTitle . ' ' . $chat->companyName . ' ' . $chat->vacancyExternalId
                        . ' ' . $chat->resumeTitle . ' ' . $chat->resumeExternalId,
                        'UTF-8',
                    );

                    return str_contains($hay, mb_strtolower($query, 'UTF-8'));
                },
            ));
        }

        $map = $this->appliedResumes?->all() ?? [];
        $oldCount = $this->countOlderThanMonths($live, 1);

        return [
            'total' => count($all),
            'shown' => count($items),
            'live_count' => count($live),
            'archived_count' => $archivedOnly,
            'rejection_count' => $rejectionCount,
            'old_count' => $oldCount,
            'filter' => $filter,
            'query' => $query,
            'vacancy_id' => $vacancyId,
            'synced_at' => $syncedAt,
            'items' => array_map(
                function (ChatSummary $c) use ($map, $liveKeys): array {
                    $row = $c->toArray();
                    $row['origin'] = isset($liveKeys[$this->dedupeKey($c)]) ? 'live' : 'archive';
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

    /** ID из `139203618` или `https://hh.ru/vacancy/139203618?...`. */
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

    /**
     * @param list<ChatSummary> $chats
     */
    private function countOlderThanMonths(array $chats, int $months): int
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))
            ->sub(new DateInterval('P' . max(1, $months) . 'M'));
        $n = 0;
        foreach ($chats as $chat) {
            if (trim($chat->chatId) === '') {
                continue;
            }
            $raw = trim($chat->lastMessageAt);
            if ($raw === '') {
                $raw = trim($chat->lastActivityAt);
            }
            if ($raw === '') {
                continue;
            }
            try {
                $at = new DateTimeImmutable($raw);
            } catch (\Exception) {
                continue;
            }
            if ($at < $cutoff) {
                $n++;
            }
        }

        return $n;
    }

}
