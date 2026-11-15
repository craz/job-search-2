<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Applicant;

use JobSearch\Domain\Applicant\ScreeningAnswerMemory;
use JobSearch\Domain\Applicant\ScreeningTask;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\Telegram\TelegramDmClient;
use JobSearch\Infrastructure\Telegram\TelegramDmException;
use Playwright\Page\PageInterface;

/**
 * Неизвестные вопросы анкеты → Telegram DM → fill + remember (как resume/hh_screening_tg).
 */
final class TelegramScreeningSolver
{
    private const DEFAULT_TIMEOUT_S = 900;

    public function __construct(
        private readonly TelegramDmClient $dm,
        private readonly HhScreeningForm $form,
        private readonly ?ScreeningAnswerMemory $memory = null,
        private readonly int $timeoutS = self::DEFAULT_TIMEOUT_S,
    ) {}

    public static function fromConfig(
        Config $config,
        HhScreeningForm $form,
        ?ScreeningAnswerMemory $memory = null,
        ?TelegramDmClient $dm = null,
    ): ?self {
        $flag = $config->get('HH_SCREENING_TG');
        if ($flag !== null && trim($flag) !== '' && !self::envTruthy($flag)) {
            return null;
        }
        // Default on when captcha TG / DM credentials present.
        if ($flag === null || trim((string) $flag) === '') {
            $captchaFlag = $config->get('HH_CAPTCHA_TG');
            if ($captchaFlag !== null && trim($captchaFlag) !== '' && !self::envTruthy($captchaFlag)) {
                // captcha explicitly off — still allow screening if HH_SCREENING_TG unset? Plan: on if TELEGRAM_DM_*
            }
        }

        $dm ??= TelegramDmClient::fromConfig($config, dirname(__DIR__, 3) . '/data');
        if ($dm === null) {
            return null;
        }

        $timeoutRaw = trim((string) ($config->get('HH_SCREENING_TG_TIMEOUT_S') ?? ''));
        $timeout = self::DEFAULT_TIMEOUT_S;
        if ($timeoutRaw !== '' && ctype_digit($timeoutRaw)) {
            $timeout = max(60, (int) $timeoutRaw);
        }

        return new self($dm, $form, $memory, $timeout);
    }

    /**
     * Спросить неизвестные вопросы; заполнить; сохранить в memory.
     *
     * @param list<ScreeningTask> $tasks
     *
     * @return array{ok: bool, skip?: bool, timeout?: bool, filled?: int, error?: string}
     */
    public function askAndFill(
        PageInterface $page,
        array $tasks,
        string $vacancyId = '',
        string $title = '',
    ): array {
        if ($tasks === []) {
            return ['ok' => true, 'filled' => 0];
        }

        $body = self::formatAskMessage($tasks, $vacancyId, $title);

        try {
            $parsed = $this->dm->withConversation(
                'screening',
                function (TelegramDmClient $dm) use ($body, $tasks): array {
                    $dm->sendLongMessage($body);
                    $raw = $dm->waitForText($this->timeoutS);

                    return self::parseOwnerAnswers($raw, count($tasks));
                },
                $vacancyId,
            );
        } catch (TelegramDmException $e) {
            return ['ok' => false, 'timeout' => true, 'error' => $e->getMessage()];
        }

        if (!($parsed['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($parsed['error'] ?? 'не разобрал ответы')];
        }
        if (!empty($parsed['skip'])) {
            return ['ok' => false, 'skip' => true];
        }

        /** @var array<int, string> $answers */
        $answers = is_array($parsed['answers'] ?? null) ? $parsed['answers'] : [];
        $pairs = [];
        $filled = 0;
        foreach ($tasks as $i => $task) {
            $num = $i + 1;
            $ans = trim((string) ($answers[$num] ?? ''));
            if ($ans === '' || in_array($ans, ['-', '—', '–', '.'], true)) {
                continue;
            }
            // «b: пояснение» / «d: свой текст» — клик по букве + textarea.
            $choice = $ans;
            $extra = '';
            if (preg_match('/^([a-zа-яё])\s*[:\-]\s*(.+)$/ui', $ans, $m) === 1) {
                $choice = $m[1];
                $extra = trim($m[2]);
            }
            $text = self::resolveOptionAnswer($choice, $task);
            if (!$this->form->applyAnswer($page, $task, $text)) {
                return [
                    'ok' => false,
                    'error' => "не удалось заполнить вопрос {$num}",
                    'filled' => $filled,
                ];
            }
            if ($extra !== '') {
                $this->form->fillText($page, $task->name, $extra);
                $text = $text . ': ' . $extra;
            }
            $pairs[] = [$task->question, $text];
            $filled++;
        }

        if ($this->memory !== null && $pairs !== []) {
            $this->memory->remember($pairs, 'tg', $vacancyId !== '' ? $vacancyId : null, $title !== '' ? $title : null);
        }

        return ['ok' => true, 'filled' => $filled];
    }

    /**
     * @param list<ScreeningTask> $tasks
     */
    public static function formatAskMessage(array $tasks, string $vacancyId = '', string $title = ''): string
    {
        $head = "Анкета HH — ответь буквами вариантов (один ответ на вопрос).\n"
            . "Пример:\n1: b\n2: c\n3: a\n"
            . "Свой вариант: 6: d: короткий текст\n"
            . "Пропуск вакансии: skip\n";
        if ($title !== '') {
            $head .= 'Вакансия: ' . mb_substr($title, 0, 120) . "\n";
        }
        if ($vacancyId !== '') {
            $head .= 'https://hh.ru/vacancy/' . rawurlencode($vacancyId) . "\n";
        }
        $head .= "\n";

        return $head . self::formatQuestions($tasks);
    }

    /**
     * @param list<ScreeningTask> $tasks
     */
    public static function formatQuestions(array $tasks): string
    {
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $lines = [];
        foreach ($tasks as $i => $task) {
            $n = $i + 1;
            $q = trim($task->question) !== '' ? $task->question : "вопрос {$n}";
            $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
            $lines[] = $n . '. ' . mb_substr($q, 0, 400);
            $opts = $task->options;
            if ($opts === []) {
                $lines[] = '   → свободный текст';
                continue;
            }
            foreach (array_slice($opts, 0, 12) as $j => $opt) {
                $label = trim($opt->label);
                if ($label === '') {
                    continue;
                }
                $letter = $j < strlen($letters) ? $letters[$j] : (string) ($j + 1);
                $lines[] = '   ' . $letter . ') ' . mb_substr($label, 0, 220);
            }
            if (preg_match('/кратко поясните|свой вариант/ui', $q . ' ' . implode(' ', array_map(
                static fn ($o) => $o->label,
                $opts,
            ))) === 1) {
                $lines[] = '   (если нужно пояснение: ' . $n . ': b: ваш текст)';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{ok: bool, skip?: bool, answers?: array<int, string>, error?: string}
     */
    public static function parseOwnerAnswers(string $text, int $n): array
    {
        $raw = trim($text);
        if ($raw === '') {
            return ['ok' => false, 'error' => 'empty'];
        }
        $low = mb_strtolower($raw);
        if (in_array($low, ['skip', 'пропуск', 'пропустить', 'pass'], true)) {
            return ['ok' => true, 'skip' => true];
        }

        /** @var array<int, string> $answers */
        $answers = [];
        $current = null;
        $emptyMarks = ['-', '—', '–', '.', 'нет ответа', 'n/a', 'na'];

        $set = static function (int $num, string $val) use (&$answers, &$current, $emptyMarks): void {
            $val = trim($val);
            $answers[$num] = in_array(mb_strtolower($val), $emptyMarks, true) ? '' : $val;
            $current = $num;
        };

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(\d+)\s*[:.)]\s*(.*)$/u', $line, $m) === 1) {
                $set((int) $m[1], $m[2]);
                continue;
            }
            if (preg_match('/^(\d+)\s*-\s+(.*)$/u', $line, $m) === 1) {
                $set((int) $m[1], $m[2]);
                continue;
            }
            if (preg_match('/^(\d+)\s*-$/u', $line, $m) === 1) {
                $set((int) $m[1], '');
                continue;
            }
            if (preg_match('/^(\d+)\s+(.+)$/u', $line, $m) === 1) {
                $set((int) $m[1], $m[2]);
                continue;
            }
            if (preg_match('/^(\d+)([a-zA-Zа-яА-ЯёЁ].*)$/u', $line, $m) === 1) {
                $set((int) $m[1], $m[2]);
                continue;
            }
            if (preg_match('/^(\d+)$/u', $line, $m) === 1) {
                $set((int) $m[1], '');
                continue;
            }
            if ($current !== null) {
                $prev = $answers[$current] ?? '';
                $answers[$current] = $prev !== '' ? trim($prev . ' ' . $line) : $line;
                continue;
            }
            if ($n === 1 && $answers === []) {
                $answers[1] = $line;
            }
        }

        if ($answers === []) {
            if ($n === 1) {
                $answers[1] = $raw;
            } else {
                return ['ok' => false, 'error' => 'не разобрал ответы; формат: 1 a   или  1: текст'];
            }
        }

        return ['ok' => true, 'skip' => false, 'answers' => $answers];
    }

    public static function resolveOptionAnswer(string $answer, ScreeningTask $task): string
    {
        $ans = trim($answer);
        if ($ans === '') {
            return $ans;
        }
        $labels = array_map(static fn ($o) => $o->label, $task->options);
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        if (mb_strlen($ans) === 1 && str_contains($letters, mb_strtolower($ans)) && $labels !== []) {
            $idx = strpos($letters, mb_strtolower($ans));
            if ($idx !== false && isset($labels[$idx]) && $labels[$idx] !== '') {
                return $labels[$idx];
            }
        }
        if (ctype_digit($ans) && $labels !== []) {
            $idx = (int) $ans - 1;
            if (isset($labels[$idx]) && $labels[$idx] !== '') {
                return $labels[$idx];
            }
        }

        return $ans;
    }

    private static function envTruthy(string $raw): bool
    {
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
