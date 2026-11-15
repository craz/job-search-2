<?php
declare(strict_types=1);

namespace JobSearch\Domain\Applicant;

use Playwright\Page\PageInterface;

/**
 * Решение HH-капчи вне браузера оператора (скрин → ответ → ввод на странице).
 */
interface CaptchaSolver
{
    /** Включено ли решение (env + credentials). */
    public function enabled(): bool;

    /**
     * Скриншот → запрос кода → ввод. True = капча после этого пропала.
     * Не бросает наружу: при сбое возвращает false.
     */
    public function tryResolve(PageInterface $page, string $context = ''): bool;
}
