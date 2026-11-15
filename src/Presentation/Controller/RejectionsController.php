<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Chat\ListRejections;

/** Presentation: журнал отказов из hh_chats. */
final class RejectionsController
{
    public function __construct(private readonly ListRejections $useCase) {}

    /** @return array<string, mixed> */
    public function page(string $query = ''): array
    {
        return $this->useCase->page($query);
    }
}
