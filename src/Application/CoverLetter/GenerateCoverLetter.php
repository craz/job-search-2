<?php
declare(strict_types=1);

namespace JobSearch\Application\CoverLetter;

use JobSearch\Domain\DevAi\DevAi;
use JobSearch\Domain\DevAi\DevAiException;

/**
 * Собирает prompt cover_letter и просит DevAI текст сопроводительного.
 * Может вернуть needs_input / draft_with_gaps, если не хватает фактов.
 */
final class GenerateCoverLetter
{
    public function __construct(
        private readonly DevAi $devAi,
        private readonly string $templatePath,
        private readonly string $verifiedContextPath,
        private readonly string $promptVersion,
        private readonly string $model,
        private readonly int $maxChars = 2500,
    ) {}

    public static function fromProjectRoot(
        string $projectRoot,
        DevAi $devAi,
        string $model = 'devai/auto',
    ): self {
        $root = rtrim($projectRoot, '/');
        $profilePath = $root . '/config/cover_letter/profile.json';
        $version = 'cover_v12';
        $maxChars = 2500;
        if (is_readable($profilePath)) {
            $raw = json_decode((string) file_get_contents($profilePath), true);
            if (is_array($raw)) {
                $version = trim((string) ($raw['prompt_version'] ?? $version)) ?: $version;
                $maxChars = max(400, (int) ($raw['max_chars'] ?? $maxChars));
            }
        }
        $model = trim($model) !== '' ? trim($model) : 'devai/auto';

        return new self(
            $devAi,
            $root . '/config/cover_letter/prompt.txt',
            $root . '/config/cover_letter/verified_context.txt',
            $version,
            $model,
            $maxChars,
        );
    }

    /**
     * @return array{
     *   status: 'ok'|'needs_input'|'draft_with_gaps',
     *   letter: string,
     *   missing: string,
     *   draft_questions: list<array{question: string, status: string, draft: string, missing: string}>,
     *   prompt: string,
     *   prompt_version: string,
     *   model: string
     * }
     */
    public function generate(
        string $resumeText,
        string $vacancyText,
        string $resumeTitle = '',
        string $vacancyTitle = '',
        string $verifiedContext = '',
    ): array {
        $resumeText = trim($resumeText);
        $vacancyText = trim($vacancyText);
        if ($resumeText === '') {
            throw new DevAiException('cover_letter: empty resume text');
        }
        if ($vacancyText === '') {
            throw new DevAiException('cover_letter: empty vacancy text');
        }

        $template = is_readable($this->templatePath)
            ? (string) file_get_contents($this->templatePath)
            : '';
        if ($template === '') {
            throw new DevAiException('cover_letter: missing prompt ' . $this->templatePath);
        }

        $baseContext = $this->loadVerifiedContext();
        $extra = trim($verifiedContext);
        $context = trim($baseContext . ($extra !== '' ? "\n\n" . $extra : ''));
        if ($context === '') {
            $context = '(нет дополнительных подтверждённых фактов)';
        }

        $prompt = strtr($template, [
            '{{resume}}' => self::truncate($resumeText, 10000),
            '{{vacancy}}' => self::truncate($vacancyText, 12000),
            '{{resume_title}}' => self::truncate(trim($resumeTitle), 200),
            '{{vacancy_title}}' => self::truncate(trim($vacancyTitle), 200),
            '{{verified_context}}' => self::truncate($context, 8000),
        ]);

        $chat = $this->devAi->chatCompletions($this->model, [
            [
                'role' => 'system',
                'content' => 'Ты пишешь сопроводительные на русском для HeadHunter от лица IT-руководителя. '
                    . 'При обязательных вопросах работодателя с пробелами в фактах ответь блоком DRAFT_WITH_GAPS по формату user-prompt. '
                    . 'CONFIRMED_GAP в контексте — закрытый честный ответ (граница опыта); не NEEDS_INPUT и не MISSING. '
                    . 'Если все пункты CONFIRMED/CONFIRMED_GAP — только готовый текст письма/ответов. '
                    . 'Если нужен один недостающий персональный факт без списка вопросов — одной строкой NEEDS_INPUT: факт. '
                    . 'Если фактов достаточно — только готовый текст письма/ответов, без Markdown.',
            ],
            ['role' => 'user', 'content' => $prompt],
        ]);

        $raw = self::cleanLetter(self::extractContent($chat));
        if ($raw === '') {
            throw new DevAiException('cover_letter: empty model response');
        }

        $gaps = self::parseDraftWithGaps($raw);
        if ($gaps !== null) {
            // Все Qi закрыты (CONFIRMED / CONFIRMED_GAP) — не гоняем в TG повторно.
            if (self::gapsFullyResolved($gaps)) {
                $letter = self::letterFromResolvedGaps($gaps['questions']);
                if ($letter !== '') {
                    if (mb_strlen($letter) > $this->maxChars + 200) {
                        $letter = self::truncate($letter, $this->maxChars);
                    }

                    return [
                        'status' => 'ok',
                        'letter' => $letter,
                        'missing' => '',
                        'draft_questions' => [],
                        'prompt' => $prompt,
                        'prompt_version' => $this->promptVersion,
                        'model' => $this->model,
                    ];
                }
            }

            return [
                'status' => 'draft_with_gaps',
                'letter' => '',
                'missing' => $gaps['missing'],
                'draft_questions' => $gaps['questions'],
                'prompt' => $prompt,
                'prompt_version' => $this->promptVersion,
                'model' => $this->model,
            ];
        }

        $needs = self::parseNeedsInput($raw);
        if ($needs !== null) {
            return [
                'status' => 'needs_input',
                'letter' => '',
                'missing' => $needs,
                'draft_questions' => [],
                'prompt' => $prompt,
                'prompt_version' => $this->promptVersion,
                'model' => $this->model,
            ];
        }

        // Защита: модель иногда пишет NEEDS_INPUT/DRAFT_WITH_GAPS «как письмо».
        if (self::looksLikeGapPayload($raw)) {
            $fallback = self::parseNeedsInput('NEEDS_INPUT: ' . self::extractGapFallback($raw));

            return [
                'status' => 'needs_input',
                'letter' => '',
                'missing' => $fallback ?? 'не хватает подтверждённых фактов для обязательных вопросов',
                'draft_questions' => [],
                'prompt' => $prompt,
                'prompt_version' => $this->promptVersion,
                'model' => $this->model,
            ];
        }

        $letter = $raw;
        if (mb_strlen($letter) > $this->maxChars + 200) {
            $letter = self::truncate($letter, $this->maxChars);
        }

        return [
            'status' => 'ok',
            'letter' => $letter,
            'missing' => '',
            'draft_questions' => [],
            'prompt' => $prompt,
            'prompt_version' => $this->promptVersion,
            'model' => $this->model,
        ];
    }

    public static function looksLikeGapPayload(string $text): bool
    {
        $text = trim($text);

        return preg_match('/^(NEEDS_INPUT|DRAFT_WITH_GAPS)\b/ui', $text) === 1
            || preg_match('/^---\s*черновик.*\n\s*(NEEDS_INPUT|DRAFT_WITH_GAPS)\b/uis', $text) === 1;
    }

    private static function extractGapFallback(string $text): string
    {
        $text = trim($text);
        if (preg_match('/NEEDS_INPUT\s*:\s*(.+)$/uis', $text, $m) === 1) {
            $lines = preg_split('/\R/u', trim((string) $m[1])) ?: [];

            return trim((string) ($lines[0] ?? $m[1]));
        }

        return 'не хватает подтверждённых фактов для обязательных вопросов';
    }

    /**
     * @return array{
     *   questions: list<array{question: string, status: string, draft: string, missing: string}>,
     *   missing: string
     * }|null
     */
    public static function parseDraftWithGaps(string $text): ?array
    {
        $text = trim($text);
        if (!preg_match('/^DRAFT_WITH_GAPS\b/ui', $text)) {
            return null;
        }

        $body = trim((string) preg_replace('/^DRAFT_WITH_GAPS\s*/ui', '', $text));
        $missingAll = [];
        if (preg_match('/\nMISSING_ALL\s*:\s*(.*)$/uis', $body, $mAll) === 1) {
            $tail = trim((string) $mAll[1]);
            $body = trim((string) preg_replace('/\nMISSING_ALL\s*:.*$/uis', '', $body));
            foreach (preg_split('/\R/u', $tail) ?: [] as $line) {
                $line = trim($line);
                $line = preg_replace('/^[-*•]\s*/u', '', $line) ?? $line;
                $line = trim($line);
                if ($line !== '' && $line !== '-') {
                    $missingAll[] = $line;
                }
            }
        }

        $questions = [];
        if (preg_match_all(
            '/^Q(\d+)\s*:\s*([\s\S]+?)(?=^Q\d+\s*:|\z)/um',
            $body,
            $blocks,
            PREG_SET_ORDER,
        ) === false || $blocks === []) {
            return null;
        }

        foreach ($blocks as $block) {
            $chunk = trim((string) ($block[0] ?? ''));
            if ($chunk === '') {
                continue;
            }
            $question = '';
            if (preg_match('/^Q\d+\s*:\s*(.+)$/um', $chunk, $qm) === 1) {
                $question = trim((string) $qm[1]);
            }
            $status = 'MISSING';
            if (preg_match('/^status\s*:\s*(CONFIRMED_GAP|CONFIRMED|PARTIAL|MISSING)\s*$/uim', $chunk, $sm) === 1) {
                $status = strtoupper(trim((string) $sm[1]));
            }
            $draft = '-';
            if (preg_match('/^draft\s*:\s*(.+)$/uim', $chunk, $dm) === 1) {
                $draft = trim((string) $dm[1]);
            }
            $missing = '-';
            if (preg_match('/^missing\s*:\s*(.+)$/uim', $chunk, $mm) === 1) {
                $missing = trim((string) $mm[1]);
            }
            if ($question === '') {
                continue;
            }
            $questions[] = [
                'question' => $question,
                'status' => $status,
                'draft' => $draft !== '' ? $draft : '-',
                'missing' => $missing !== '' ? $missing : '-',
            ];
            if (
                in_array($status, ['PARTIAL', 'MISSING'], true)
                && $missing !== '-'
                && $missing !== ''
            ) {
                $missingAll[] = $missing;
            }
        }

        if ($questions === []) {
            return null;
        }

        $missingAll = array_values(array_unique(array_filter(
            array_map(static fn (string $s): string => trim($s), $missingAll),
            static fn (string $s): bool => $s !== '' && $s !== '-',
        )));

        return [
            'questions' => $questions,
            'missing' => $missingAll !== []
                ? implode('; ', $missingAll)
                : 'не хватает подтверждённых фактов для обязательных вопросов',
        ];
    }

    public static function parseNeedsInput(string $text): ?string
    {
        $text = trim($text);
        // Вся первая строка — NEEDS_INPUT (не принимать как готовое письмо).
        if (preg_match('/^NEEDS_INPUT\s*:\s*(.+)$/ui', $text, $m) === 1) {
            $missing = trim((string) $m[1]);
            $lines = preg_split('/\R/u', $missing) ?: [];
            $missing = trim((string) ($lines[0] ?? $missing));

            return $missing !== '' ? $missing : 'не указан недостающий факт';
        }

        return null;
    }

    /**
     * @param array{
     *   questions: list<array{question: string, status: string, draft: string, missing: string}>,
     *   missing: string
     * } $gaps
     */
    public static function gapsFullyResolved(array $gaps): bool
    {
        $questions = $gaps['questions'] ?? [];
        if (!is_array($questions) || $questions === []) {
            return false;
        }
        foreach ($questions as $q) {
            if (!is_array($q)) {
                return false;
            }
            $status = strtoupper(trim((string) ($q['status'] ?? '')));
            if (!in_array($status, ['CONFIRMED', 'CONFIRMED_GAP'], true)) {
                return false;
            }
            $draft = trim((string) ($q['draft'] ?? ''));
            if ($draft === '' || $draft === '-') {
                return false;
            }
        }
        $missing = trim((string) ($gaps['missing'] ?? ''));

        return $missing === ''
            || $missing === '-'
            || $missing === 'не хватает подтверждённых фактов для обязательных вопросов';
    }

    /**
     * @param list<array{question?: string, status?: string, draft?: string, missing?: string}> $questions
     */
    public static function letterFromResolvedGaps(array $questions): string
    {
        $parts = [];
        foreach ($questions as $q) {
            if (!is_array($q)) {
                continue;
            }
            $draft = trim((string) ($q['draft'] ?? ''));
            if ($draft === '' || $draft === '-') {
                continue;
            }
            $question = trim((string) ($q['question'] ?? ''));
            if ($question !== '' && count($questions) > 1) {
                $parts[] = $question . "\n" . $draft;
            } else {
                $parts[] = $draft;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /** @param array<string, mixed> $chat */
    private static function extractContent(array $chat): string
    {
        $choices = $chat['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            return '';
        }
        $first = $choices[0] ?? null;
        if (!is_array($first)) {
            return '';
        }
        $message = $first['message'] ?? null;
        if (is_array($message)) {
            return trim((string) ($message['content'] ?? ''));
        }

        return trim((string) ($first['text'] ?? ''));
    }

    private static function cleanLetter(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:\w+)?\s*/u', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/u', '', $text) ?? $text;
        $cut = preg_split('/\n---\s*\n\s*\[devai route\]/u', $text, 2);
        if (is_array($cut) && isset($cut[0]) && trim($cut[0]) !== '') {
            $text = $cut[0];
        }
        $text = preg_replace('/\n\[devai route\].*$/us', '', $text) ?? $text;
        $text = trim($text);
        if (
            (str_starts_with($text, '"') && str_ends_with($text, '"'))
            || (str_starts_with($text, '«') && str_ends_with($text, '»'))
        ) {
            $text = trim(mb_substr($text, 1, mb_strlen($text) - 2));
        }

        return trim($text);
    }

    private function loadVerifiedContext(): string
    {
        if (!is_readable($this->verifiedContextPath)) {
            return '';
        }

        return trim((string) file_get_contents($this->verifiedContextPath));
    }

    private static function truncate(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
