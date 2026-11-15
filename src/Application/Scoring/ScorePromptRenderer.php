<?php
declare(strict_types=1);

namespace JobSearch\Application\Scoring;

/**
 * Сборка user-prompt из config/scoring/prompts/score_vacancy.txt.
 */
final class ScorePromptRenderer
{
    public function __construct(
        private readonly string $templatePath,
        private readonly ScoringProfile $profile,
    ) {}

    public static function fromProjectRoot(string $projectRoot, ?ScoringProfile $profile = null): self
    {
        $root = rtrim($projectRoot, '/');

        return new self(
            $root . '/config/scoring/prompts/score_vacancy.txt',
            $profile ?? ScoringProfile::load($root),
        );
    }

    /**
     * @param array<string, mixed> $vacancy Core VacancyRead
     * @param array<string, mixed> $resumeContext краткий контекст резюме
     */
    public function render(array $vacancy, array $resumeContext): string
    {
        $template = is_readable($this->templatePath)
            ? (string) file_get_contents($this->templatePath)
            : '';
        if ($template === '') {
            throw new \RuntimeException('Missing scoring prompt: ' . $this->templatePath);
        }

        $company = is_array($vacancy['company'] ?? null) ? $vacancy['company'] : [];
        $map = [
            '{{POLICY}}' => $this->truncate($this->profile->policyText, 6000),
            '{{RESUME}}' => $this->truncate($this->resumeBlock($resumeContext), 8000),
            '{{TITLE}}' => trim((string) ($vacancy['title'] ?? '')),
            '{{COMPANY}}' => trim((string) ($company['name'] ?? '')),
            '{{URL}}' => trim((string) ($vacancy['url'] ?? '')),
            '{{EMPLOYMENT}}' => trim((string) ($vacancy['employment_text'] ?? '')),
            '{{WORK_FORMAT}}' => trim((string) ($vacancy['work_format_text'] ?? '')),
            '{{SALARY}}' => trim((string) ($vacancy['salary_text'] ?? '')),
            '{{AREA}}' => trim((string) ($vacancy['area_text'] ?? '')),
            '{{DESCRIPTION}}' => $this->truncate(trim((string) ($vacancy['description'] ?? '')), 14000),
        ];

        return strtr($template, $map);
    }

    /**
     * @param array<string, mixed> $resumeContext
     */
    private function resumeBlock(array $resumeContext): string
    {
        $title = trim((string) ($resumeContext['title'] ?? $resumeContext['resume_title'] ?? ''));
        $id = trim((string) ($resumeContext['external_id'] ?? $resumeContext['hh_resume_external_id'] ?? ''));
        $text = trim((string) ($resumeContext['text'] ?? $resumeContext['summary'] ?? ''));
        $lines = [];
        if ($title !== '') {
            $lines[] = 'Resume title: ' . $title;
        }
        if ($id !== '') {
            $lines[] = 'Resume id: ' . $id;
        }
        if ($text !== '') {
            $lines[] = $text;
        }
        if ($lines === []) {
            $lines[] = json_encode($resumeContext, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
        }

        return implode("\n", $lines);
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . "\n…[truncated]";
    }
}
