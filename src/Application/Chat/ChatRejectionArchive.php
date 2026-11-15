<?php
declare(strict_types=1);

namespace JobSearch\Application\Chat;

use JobSearch\Domain\Chat\ChatSummary;
use JobSearch\Infrastructure\Cache\Cache;

/**
 * Долгий журнал отказов из чатов HH (не зависит от replaceAll hh_chats после leave).
 */
final class ChatRejectionArchive
{
    public const CACHE_KEY = 'chat_rejections';

    public function __construct(private readonly Cache $cache) {}

    /**
     * @return list<ChatSummary>
     */
    public function all(): array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        $payload = $entry?->payload;
        if (!is_array($payload)) {
            return [];
        }
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $chat = ChatSummary::fromArray($row);
            if ($chat->chatId === '' && $chat->vacancyExternalId === '' && $chat->companyName === '') {
                continue;
            }
            $out[] = $chat;
        }

        return $out;
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Дописать/обновить отказы. Не удаляет уже сохранённые.
     *
     * @param list<ChatSummary> $chats
     *
     * @return int сколько новых или обновлённых
     */
    public function mergeFromChats(array $chats): int
    {
        $byKey = [];
        foreach ($this->all() as $chat) {
            $byKey[$this->key($chat)] = $chat;
        }

        $changed = 0;
        foreach ($chats as $chat) {
            if (!$chat instanceof ChatSummary) {
                continue;
            }
            if (!$chat->isRejection()) {
                continue;
            }
            $chat = $chat->withClosedVacancyNormalized();
            $key = $this->key($chat);
            if ($key === '') {
                continue;
            }
            $prev = $byKey[$key] ?? null;
            if ($prev instanceof ChatSummary && $this->sameEnough($prev, $chat)) {
                continue;
            }
            $byKey[$key] = $this->preferRicher($prev, $chat);
            $changed++;
        }

        if ($changed === 0) {
            return 0;
        }

        $items = array_map(
            static fn (ChatSummary $c): array => $c->toArray(),
            array_values($byKey),
        );
        usort(
            $items,
            static function (array $a, array $b): int {
                $atA = (string) (($a['last_message_at'] ?? '') !== ''
                    ? $a['last_message_at']
                    : ($a['last_activity_at'] ?? ''));
                $atB = (string) (($b['last_message_at'] ?? '') !== ''
                    ? $b['last_message_at']
                    : ($b['last_activity_at'] ?? ''));

                return strcmp($atB, $atA);
            },
        );

        $this->cache->set(self::CACHE_KEY, ['items' => $items]);

        return $changed;
    }

    private function key(ChatSummary $chat): string
    {
        $id = trim($chat->chatId);
        if ($id !== '') {
            return 'c:' . $id;
        }
        $vac = trim($chat->vacancyExternalId);
        if ($vac !== '') {
            return 'v:' . $vac;
        }
        $company = mb_strtolower(trim($chat->companyName), 'UTF-8');
        $title = mb_strtolower(trim($chat->vacancyTitle), 'UTF-8');
        if ($company === '' && $title === '') {
            return '';
        }

        return 't:' . $company . '|' . $title;
    }

    private function sameEnough(ChatSummary $a, ChatSummary $b): bool
    {
        return $a->companyName === $b->companyName
            && $a->vacancyTitle === $b->vacancyTitle
            && $a->vacancyExternalId === $b->vacancyExternalId
            && $a->statusLabel === $b->statusLabel
            && $a->applicantState === $b->applicantState
            && $a->lastMessageAt === $b->lastMessageAt
            && $a->resumeExternalId === $b->resumeExternalId
            && $a->resumeTitle === $b->resumeTitle;
    }

    private function preferRicher(?ChatSummary $prev, ChatSummary $next): ChatSummary
    {
        if ($prev === null) {
            return $this->asDiscard($next);
        }

        return $this->asDiscard(new ChatSummary(
            chatId: $next->chatId !== '' ? $next->chatId : $prev->chatId,
            url: $next->url !== '' ? $next->url : $prev->url,
            vacancyExternalId: $next->vacancyExternalId !== '' ? $next->vacancyExternalId : $prev->vacancyExternalId,
            vacancyTitle: $next->vacancyTitle !== '' ? $next->vacancyTitle : $prev->vacancyTitle,
            companyName: $next->companyName !== '' ? $next->companyName : $prev->companyName,
            applicantState: 'DISCARD',
            statusLabel: $this->rejectionLabel($next, $prev),
            lastActivityAt: $this->maxIso($next->lastActivityAt, $prev->lastActivityAt),
            lastMessageAt: $this->maxIso($next->lastMessageAt, $prev->lastMessageAt),
            lastMessagePreview: $next->lastMessagePreview !== '' ? $next->lastMessagePreview : $prev->lastMessagePreview,
            unreadCount: 0,
            listPosition: $next->listPosition > 0 ? $next->listPosition : $prev->listPosition,
            syncedAt: $this->maxIso($next->syncedAt, $prev->syncedAt),
            resumeExternalId: $next->resumeExternalId !== '' ? $next->resumeExternalId : $prev->resumeExternalId,
            resumeTitle: $next->resumeTitle !== '' ? $next->resumeTitle : $prev->resumeTitle,
        ));
    }

    private function asDiscard(ChatSummary $chat): ChatSummary
    {
        return new ChatSummary(
            chatId: $chat->chatId,
            url: $chat->url,
            vacancyExternalId: $chat->vacancyExternalId,
            vacancyTitle: $chat->vacancyTitle,
            companyName: $chat->companyName,
            applicantState: 'DISCARD',
            statusLabel: $this->rejectionLabel($chat, null),
            lastActivityAt: $chat->lastActivityAt,
            lastMessageAt: $chat->lastMessageAt,
            lastMessagePreview: $chat->lastMessagePreview,
            unreadCount: 0,
            listPosition: $chat->listPosition,
            syncedAt: $chat->syncedAt,
            resumeExternalId: $chat->resumeExternalId,
            resumeTitle: $chat->resumeTitle,
        );
    }

    private function rejectionLabel(ChatSummary $next, ?ChatSummary $prev): string
    {
        if ($next->isVacancyClosed() || ($prev !== null && $prev->isVacancyClosed())) {
            return 'Закрыта';
        }
        $label = trim($next->statusLabel);
        if ($label === '') {
            $label = $prev !== null ? trim($prev->statusLabel) : '';
        }

        return $label !== '' ? $label : 'Отказ';
    }

    private function maxIso(string $a, string $b): string
    {
        if ($a === '') {
            return $b;
        }
        if ($b === '') {
            return $a;
        }

        return $a >= $b ? $a : $b;
    }

}
