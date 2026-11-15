<?php
declare(strict_types=1);

namespace JobSearch\Domain\DevAi;

/**
 * Контракт публичного DevAI router (chat completions).
 */
interface DevAi
{
    /**
     * POST /v1/chat/completions
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @return array<string, mixed> raw OpenAI-compatible response
     *
     * @throws DevAiException
     */
    public function chatCompletions(string $model, array $messages): array;
}
