<?php
declare(strict_types=1);

namespace JobSearch\Application\Scoring;

/**
 * Достаёт verdict JSON из ответа chat completions.
 */
final class DevAiVerdictParser
{
    /**
     * @param array<string, mixed> $chatResponse
     *
     * @return array{
     *   verdict: string,
     *   reason: string,
     *   action: string,
     *   decisive_factors: list<string>
     * }
     */
    public function parse(array $chatResponse): array
    {
        $content = '';
        $choices = $chatResponse['choices'] ?? null;
        if (is_array($choices) && isset($choices[0]) && is_array($choices[0])) {
            $msg = $choices[0]['message'] ?? null;
            if (is_array($msg)) {
                $content = trim((string) ($msg['content'] ?? ''));
            }
        }
        if ($content === '') {
            throw new \RuntimeException('devai_empty_content');
        }

        $json = $this->extractJson($content);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('devai_invalid_json');
        }

        $verdict = strtolower(trim((string) ($data['verdict'] ?? '')));
        if (!in_array($verdict, ['apply', 'maybe', 'skip'], true)) {
            // нормализуем consider/ok → apply
            if (in_array($verdict, ['ok', 'yes', 'consider'], true)) {
                $verdict = 'apply';
            } elseif (in_array($verdict, ['reject', 'no'], true)) {
                $verdict = 'skip';
            } else {
                $verdict = 'maybe';
            }
        }

        $factors = [];
        foreach ($data['decisive_factors'] ?? [] as $f) {
            if (is_string($f) && trim($f) !== '') {
                $factors[] = trim($f);
            }
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        $action = trim((string) ($data['action'] ?? ''));
        if ($action === '') {
            $action = match ($verdict) {
                'apply' => 'Откликнуться',
                'skip' => 'Пропустить вакансию',
                default => 'Уточнить вручную',
            };
        }

        return [
            'verdict' => $verdict,
            'reason' => $reason !== '' ? $reason : 'Нет reason от модели.',
            'action' => $action,
            'decisive_factors' => $factors,
        ];
    }

    private function extractJson(string $content): string
    {
        if (preg_match('/\{[\s\S]*\}/', $content, $m) === 1) {
            return $m[0];
        }

        return $content;
    }
}
