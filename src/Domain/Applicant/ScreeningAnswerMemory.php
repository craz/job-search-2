<?php
declare(strict_types=1);

namespace JobSearch\Domain\Applicant;

/**
 * Память ответов на анкеты HH (learned): поиск и сохранение.
 */
interface ScreeningAnswerMemory
{
    /** Найти ответ по тексту вопроса (exact / containment). */
    public function find(string $question): ?string;

    /**
     * Запомнить пары вопрос→ответ.
     *
     * @param list<array{0: string, 1: string}> $pairs
     */
    public function remember(
        array $pairs,
        string $source = 'tg',
        ?string $vacancyId = null,
        ?string $title = null,
    ): int;
}
