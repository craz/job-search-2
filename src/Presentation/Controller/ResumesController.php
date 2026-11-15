<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Resume\ListMine;
use JobSearch\Domain\Resume\ResumeList;

/** Presentation: список резюме. */
final class ResumesController
{
    public function __construct(private readonly ListMine $useCase) {}

    public function listMine(string $storageStatePath): ResumeList
    {
        return $this->useCase->listMine($storageStatePath);
    }
}
