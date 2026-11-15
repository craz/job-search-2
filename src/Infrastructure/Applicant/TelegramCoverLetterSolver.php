<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Applicant;

use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\Telegram\TelegramDmClient;
use JobSearch\Infrastructure\Telegram\TelegramDmException;

/**
 * Черновик сопроводительного → Telegram DM: ok / правка / skip.
 */
final class TelegramCoverLetterSolver
{
    private const DEFAULT_TIMEOUT_S = 900;

    public function __construct(
        private readonly TelegramDmClient $dm,
        private readonly int $timeoutS = self::DEFAULT_TIMEOUT_S,
    ) {}

    public static function fromConfig(Config $config, ?TelegramDmClient $dm = null): ?self
    {
        $flag = $config->get('HH_LETTER_TG');
        if ($flag !== null && trim($flag) !== '' && !self::envTruthy($flag)) {
            return null;
        }

        $dm ??= TelegramDmClient::fromConfig($config, dirname(__DIR__, 3) . '/data');
        if ($dm === null) {
            return null;
        }

        $timeoutRaw = trim((string) ($config->get('HH_LETTER_TG_TIMEOUT_S') ?? ''));
        $timeout = self::DEFAULT_TIMEOUT_S;
        if ($timeoutRaw !== '' && ctype_digit($timeoutRaw)) {
            $timeout = max(60, (int) $timeoutRaw);
        }

        return new self($dm, $timeout);
    }

    /**
     * Весь TG-цикл письма (gaps + ask) под одним flock — капча другого процесса ждёт.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function exclusive(callable $fn, string $vacancyId = '', string $resumeId = ''): mixed
    {
        return $this->dm->withConversation('letter', fn () => $fn($this), $vacancyId, $resumeId);
    }

    /**
     * @return array{ok: bool, skip?: bool, timeout?: bool, letter?: string, error?: string}
     */
    public function ask(string $draft, string $vacancyId = '', string $title = '', string $vacancyExcerpt = ''): array
    {
        $draft = trim($draft);
        if ($draft === '') {
            return ['ok' => false, 'error' => 'пустой черновик письма'];
        }

        try {
            return $this->dm->withConversation(
                'letter',
                function (TelegramDmClient $dm) use ($draft, $vacancyId, $title, $vacancyExcerpt): array {
                    $dm->sendLongMessage(self::formatAskMessage($draft, $vacancyId, $title, $vacancyExcerpt));
                    $raw = $dm->waitForText($this->timeoutS);

                    return self::parseOwnerReply($raw, $draft);
                },
                $vacancyId,
            );
        } catch (TelegramDmException $e) {
            return ['ok' => false, 'timeout' => true, 'error' => $e->getMessage()];
        }
    }

    /**
     * DevAI вернул NEEDS_INPUT — спросить недостающий факт.
     *
     * @return array{ok: bool, skip?: bool, timeout?: bool, fact?: string, error?: string}
     */
    public function askMissingFact(
        string $missing,
        string $vacancyId = '',
        string $title = '',
    ): array {
        $missing = trim($missing);
        if ($missing === '') {
            $missing = 'не указан недостающий факт';
        }

        $body = "Сопроводительное HH — нужен твой факт для письма.\n\n"
            . "Чего не хватает (персонально про тебя / опыт / цифры):\n"
            . "«{$missing}»\n\n"
            . "Ответь одним фактом о себе — его вставим в письмо.\n"
            . "Не присылай текст вакансии и не ссылку на hh.\n"
            . "skip — пропустить вакансию\n";
        if ($title !== '' && !preg_match('/^отклик на вакансию$/ui', $title)) {
            $body .= 'Вакансия: ' . mb_substr($title, 0, 160) . "\n";
        }
        if ($vacancyId !== '') {
            $body .= 'https://hh.ru/vacancy/' . rawurlencode($vacancyId) . "\n";
        }

        return $this->askFactReply($body, $vacancyId);
    }

    /**
     * DevAI вернул DRAFT_WITH_GAPS — показать черновики и спросить только пробелы.
     *
     * @param list<array{question?: string, status?: string, draft?: string, missing?: string}> $questions
     * @return array{ok: bool, skip?: bool, timeout?: bool, fact?: string, error?: string}
     */
    public function askGaps(
        array $questions,
        string $missingSummary = '',
        string $vacancyId = '',
        string $title = '',
    ): array {
        $body = "Сопроводительное HH — DRAFT_WITH_GAPS (не готово к отправке).\n"
            . "Ниже черновики ответов из подтверждённых данных и пробелы.\n"
            . "Ответь текстом недостающих фактов (можно списком).\n"
            . "skip — пропустить вакансию\n";
        if ($title !== '' && !preg_match('/^отклик на вакансию$/ui', $title)) {
            $body .= 'Вакансия: ' . mb_substr($title, 0, 160) . "\n";
        }
        if ($vacancyId !== '') {
            $body .= 'https://hh.ru/vacancy/' . rawurlencode($vacancyId) . "\n";
        }

        $n = 0;
        foreach ($questions as $q) {
            if (!is_array($q)) {
                continue;
            }
            $status = strtoupper(trim((string) ($q['status'] ?? '')));
            // CONFIRMED / CONFIRMED_GAP уже закрыты — в TG не переспрашиваем.
            if (in_array($status, ['CONFIRMED', 'CONFIRMED_GAP'], true)) {
                continue;
            }
            $n++;
            $question = trim((string) ($q['question'] ?? ''));
            $draft = trim((string) ($q['draft'] ?? '-'));
            $missing = trim((string) ($q['missing'] ?? '-'));
            $body .= "\n--- Q{$n} [{$status}] ---\n";
            if ($question !== '') {
                $body .= $question . "\n";
            }
            $body .= 'draft: ' . mb_substr($draft !== '' ? $draft : '-', 0, 800) . "\n";
            if ($missing !== '' && $missing !== '-') {
                $body .= 'missing: ' . mb_substr($missing, 0, 400) . "\n";
            }
        }

        $missingSummary = trim($missingSummary);
        if ($missingSummary !== '') {
            $body .= "\nНужно дополнить:\n" . mb_substr($missingSummary, 0, 1200) . "\n";
        }

        return $this->askFactReply($body, $vacancyId);
    }

    /**
     * @return array{ok: bool, skip?: bool, timeout?: bool, fact?: string, error?: string}
     */
    private function askFactReply(string $body, string $vacancyId = ''): array
    {
        try {
            return $this->dm->withConversation(
                'letter',
                function (TelegramDmClient $dm) use ($body): array {
                    $dm->sendLongMessage($body);
                    $raw = trim($dm->waitForText($this->timeoutS));
                    if ($raw === '') {
                        return ['ok' => false, 'error' => 'пустой ответ в TG'];
                    }
                    $low = mb_strtolower($raw);
                    if (in_array($low, ['skip', 'пропуск', 'пропустить', 'pass'], true)) {
                        return ['ok' => false, 'skip' => true];
                    }

                    return ['ok' => true, 'fact' => $raw];
                },
                $vacancyId,
            );
        } catch (TelegramDmException $e) {
            return ['ok' => false, 'timeout' => true, 'error' => $e->getMessage()];
        }
    }

    public static function formatAskMessage(
        string $draft,
        string $vacancyId = '',
        string $title = '',
        string $vacancyExcerpt = '',
    ): string {
        $head = "Сопроводительное HH — проверь черновик.\n"
            . "ok — отправить как есть\n"
            . "skip — пропустить вакансию\n"
            . "любой другой текст — использовать как письмо вместо черновика\n";
        if ($title !== '') {
            $head .= 'Вакансия: ' . mb_substr($title, 0, 160) . "\n";
        }
        if ($vacancyId !== '') {
            $head .= 'https://hh.ru/vacancy/' . rawurlencode($vacancyId) . "\n";
        }
        $excerpt = trim($vacancyExcerpt);
        if ($excerpt !== '') {
            $head .= "\n--- фрагмент вакансии ---\n"
                . mb_substr($excerpt, 0, 1200)
                . "\n";
        }
        $head .= "\n--- черновик письма ---\n" . $draft;

        return $head;
    }

    /**
     * @return array{ok: bool, skip?: bool, letter?: string, error?: string}
     */
    public static function parseOwnerReply(string $raw, string $draft): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => false, 'error' => 'пустой ответ в TG'];
        }
        $low = mb_strtolower($raw);
        if (in_array($low, ['skip', 'пропуск', 'пропустить', 'pass'], true)) {
            return ['ok' => false, 'skip' => true];
        }
        if (in_array($low, ['ok', 'ок', 'да', '+', 'yes', 'y', 'lgtm'], true)) {
            return ['ok' => true, 'letter' => $draft];
        }

        return ['ok' => true, 'letter' => $raw];
    }

    private static function envTruthy(string $raw): bool
    {
        $v = strtolower(trim($raw));

        return in_array($v, ['1', 'true', 'yes', 'on', 'y'], true);
    }
}
