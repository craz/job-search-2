<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Error\ListAppErrors;

/** Presentation: журнал ошибок UI/jobs. */
final class ErrorsController
{
    public function __construct(private readonly ListAppErrors $useCase) {}

    /**
     * @return array{
     *   total: int,
     *   filtered: int,
     *   retryable: int,
     *   filter: string,
     *   counts: array<string, int>,
     *   items: list<array<string, mixed>>
     * }
     */
    public function page(string $filter = 'open'): array
    {
        return $this->useCase->page($filter);
    }

    public function clear(): void
    {
        $this->useCase->clear();
    }

    public function resolveById(string $id, string $note = 'Закрыто вручную.'): bool
    {
        return $this->useCase->resolveById($id, $note);
    }

    public function skipById(string $id, string $note = 'Пропуск — вручную из журнала ошибок.'): bool
    {
        return $this->useCase->skipById($id, $note);
    }
}
