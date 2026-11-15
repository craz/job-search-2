<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\JobApplication\ListPage;

/** Presentation: страница откликов. */
final class ApplicationsController
{
    public function __construct(private readonly ListPage $useCase) {}

    /** @return array<string, mixed> */
    public function listPage(?string $bucket = null): array
    {
        return $this->useCase->listPage($bucket);
    }
}
