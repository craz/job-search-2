<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * Контракт представления: только вывод уже подготовленных данных.
 */
interface View
{
    /** @param array<string, mixed> $data */
    public function render(array $data): void;
}
