<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Telegram;

use JobSearch\Domain\Core\CoreApi;
use JobSearch\Infrastructure\Config\Config;

/**
 * Минимальный Telegram Bot API для DM: фото капчи + long-poll ответа.
 * Каналный бот не используется — только TELEGRAM_DM_*.
 * Диалоги сериализуются через TelegramDmGate; трафик пишется в TelegramDmJournal.
 */
final class TelegramDmClient
{
    private const API = 'https://api.telegram.org/bot%s/%s';
    private const LONG_POLL_S = 25;
    private const HTTP_SLACK_S = 15;

    private string $turnKind = 'other';
    private string $vacancyId = '';
    private string $resumeId = '';
    private string $turnId = '';
    private int $conversationDepth = 0;

    public function __construct(
        private readonly string $botToken,
        private readonly string $chatId,
        private readonly ?TelegramDmGate $gate = null,
        private readonly ?TelegramDmJournal $journal = null,
    ) {}

    public static function fromConfig(
        Config $config,
        string $dataDir,
        ?CoreApi $core = null,
    ): ?self {
        $token = trim((string) ($config->get('TELEGRAM_DM_BOT_TOKEN') ?? ''));
        $chat = trim((string) ($config->get('TELEGRAM_DM_CHAT_ID') ?? ''));
        if ($token === '' || $chat === '') {
            return null;
        }
        $dataDir = rtrim($dataDir, '/');
        $journal = new TelegramDmJournal($dataDir . '/telegram_dm.jsonl', $core);
        $gate = new TelegramDmGate($dataDir . '/locks/telegram_dm.lock', $journal);

        return new self($token, $chat, $gate, $journal);
    }

    public function journal(): ?TelegramDmJournal
    {
        return $this->journal;
    }

    /**
     * Весь цикл send→wait под одним flock (и вложенные вызовы — reentrant).
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function withConversation(
        string $kind,
        callable $fn,
        string $vacancyId = '',
        string $resumeId = '',
    ): mixed {
        $kind = trim($kind) !== '' ? trim($kind) : 'other';
        $turnId = $this->conversationDepth > 0 && $this->turnId !== ''
            ? $this->turnId
            : bin2hex(random_bytes(8));
        $run = function () use ($fn, $kind, $vacancyId, $resumeId, $turnId): mixed {
            $prev = [$this->turnKind, $this->vacancyId, $this->resumeId, $this->turnId];
            $this->turnKind = $kind;
            $this->vacancyId = trim($vacancyId);
            $this->resumeId = trim($resumeId);
            $this->turnId = $turnId;
            $this->conversationDepth++;
            try {
                return $fn($this);
            } finally {
                $this->conversationDepth--;
                if ($this->conversationDepth === 0) {
                    $this->turnKind = 'other';
                    $this->vacancyId = '';
                    $this->resumeId = '';
                    $this->turnId = '';
                } else {
                    [$this->turnKind, $this->vacancyId, $this->resumeId, $this->turnId] = $prev;
                }
            }
        };
        if ($this->gate === null) {
            return $run();
        }

        return $this->gate->withLock($kind, $run, $vacancyId, $resumeId, $turnId);
    }

    public function sendMessage(string $text, bool $html = true): void
    {
        $params = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'disable_web_page_preview' => 'true',
        ];
        if ($html) {
            $params['parse_mode'] = 'HTML';
        }
        $this->api('sendMessage', $params);
        $this->log('out', $text, ['html' => $html, 'method' => 'sendMessage']);
    }

    /**
     * Длинный plain-text (анкеты): режем по лимиту TG ~4096.
     *
     * @return int число отправленных кусков
     */
    public function sendLongMessage(string $text, int $chunk = 3500): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $chunk = max(500, $chunk);
        if (mb_strlen($text) <= $chunk) {
            $this->sendMessage($text, false);

            return 1;
        }
        $parts = [];
        $rest = $text;
        while ($rest !== '') {
            if (mb_strlen($rest) <= $chunk) {
                $parts[] = $rest;
                break;
            }
            $slice = mb_substr($rest, 0, $chunk);
            $break = mb_strrpos($slice, "\n");
            if ($break !== false && $break > (int) ($chunk * 0.5)) {
                $slice = mb_substr($slice, 0, $break);
            }
            $parts[] = $slice;
            $rest = ltrim(mb_substr($rest, mb_strlen($slice)));
        }
        $n = count($parts);
        foreach ($parts as $i => $part) {
            $prefix = $n > 1 ? '(' . ($i + 1) . '/' . $n . ")\n" : '';
            $this->sendMessage($prefix . $part, false);
            if ($i < $n - 1) {
                usleep(350_000);
            }
        }

        return $n;
    }

    public function sendPhoto(string $path, string $caption): void
    {
        if (!is_readable($path)) {
            throw new TelegramDmException('Файл фото недоступен: ' . $path);
        }

        $boundary = '----hhCaptcha' . (string) time();
        $content = (string) file_get_contents($path);
        $body = '';
        foreach (['chat_id' => $this->chatId, 'caption' => $caption, 'parse_mode' => 'HTML'] as $key => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$key}\"\r\n\r\n";
            $body .= $value . "\r\n";
        }
        $filename = basename($path);
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"photo\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: image/png\r\n\r\n";
        $body .= $content . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $url = sprintf(self::API, $this->botToken, 'sendPhoto');
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: multipart/form-data; boundary={$boundary}\r\n",
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new TelegramDmException('sendPhoto: сеть недоступна');
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['ok'])) {
            $desc = is_array($payload) ? (string) ($payload['description'] ?? 'sendPhoto failed') : 'sendPhoto failed';
            throw new TelegramDmException($desc);
        }
        $this->log('out', $caption, ['method' => 'sendPhoto', 'path' => $path]);
    }

    /**
     * Сбросить накопившиеся updates и вернуть offset для waitForText.
     * Вызывать до sendPhoto/sendMessage с вопросом — иначе быстрый ответ в TG
     * может попасть в «старые» и быть выброшен при старте wait.
     */
    public function discardPendingUpdates(): int
    {
        try {
            $this->api('deleteWebhook', ['drop_pending_updates' => 'false']);
        } catch (TelegramDmException) {
            // webhook мог уже быть снят
        }

        $pending = $this->getUpdates(null, 0);
        if ($pending === []) {
            return 0;
        }
        $max = 0;
        foreach ($pending as $upd) {
            $max = max($max, (int) ($upd['update_id'] ?? 0));
        }

        return $max + 1;
    }

    /**
     * Ждать текстовый ответ из TELEGRAM_DM_CHAT_ID (не команды /…).
     *
     * @param int|null $fromOffset offset после discardPendingUpdates(); null = discard здесь
     */
    public function waitForText(int $timeoutS = 600, ?int $fromOffset = null): string
    {
        $timeoutS = max(30, $timeoutS);
        $deadline = microtime(true) + $timeoutS;
        $offset = $fromOffset ?? $this->discardPendingUpdates();
        if ($offset <= 0) {
            $offset = null;
        }
        $this->log('meta', 'wait_start', ['timeout_s' => $timeoutS]);

        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $longPoll = min(self::LONG_POLL_S, 50, max(1, (int) $remaining));
            try {
                $updates = $this->getUpdates($offset, $longPoll);
            } catch (TelegramDmException) {
                usleep((int) (min(2.0, max(0.0, $deadline - microtime(true))) * 1_000_000));
                continue;
            }
            foreach ($updates as $upd) {
                $uid = $upd['update_id'] ?? null;
                if ($uid !== null) {
                    $offset = (int) $uid + 1;
                }
                $msg = $upd['message'] ?? $upd['edited_message'] ?? [];
                if (!is_array($msg)) {
                    continue;
                }
                $chat = $msg['chat'] ?? [];
                $chatId = (string) ($chat['id'] ?? '');
                $text = trim((string) ($msg['text'] ?? ''));
                if ($chatId !== $this->chatId || $text === '') {
                    continue;
                }
                if (str_starts_with($text, '/')) {
                    continue;
                }
                $this->log('in', $text, ['method' => 'waitForText']);

                return $text;
            }
        }

        $this->log('meta', 'wait_timeout', ['timeout_s' => $timeoutS]);
        throw new TelegramDmException("Таймаут ожидания кода в TG DM ({$timeoutS} с)");
    }

    /** @return list<array<string, mixed>> */
    private function getUpdates(?int $offset, int $timeout): array
    {
        $params = ['limit' => 100, 'timeout' => $timeout];
        if ($offset !== null) {
            $params['offset'] = $offset;
        }
        $httpTimeout = $timeout > 0 ? (float) ($timeout + self::HTTP_SLACK_S) : 30.0;
        $result = $this->api('getUpdates', $params, $httpTimeout);

        return is_array($result) ? array_values($result) : [];
    }

    /**
     * @param array<string, scalar> $params
     * @return mixed
     */
    private function api(string $method, array $params, float $httpTimeout = 30.0): mixed
    {
        $url = sprintf(self::API, $this->botToken, $method);
        $body = http_build_query($params);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => $httpTimeout,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new TelegramDmException("Telegram {$method}: сеть недоступна");
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['ok'])) {
            $desc = is_array($payload)
                ? (string) ($payload['description'] ?? "Telegram {$method} error")
                : "Telegram {$method} error";
            throw new TelegramDmException($desc);
        }

        return $payload['result'] ?? null;
    }

    /** @param array<string, mixed> $meta */
    private function log(string $direction, string $text, array $meta = []): void
    {
        $this->journal?->write(
            $direction,
            $this->turnKind !== '' ? $this->turnKind : 'other',
            $text,
            $this->vacancyId,
            $this->resumeId,
            $meta,
            $this->turnId,
        );
    }
}
