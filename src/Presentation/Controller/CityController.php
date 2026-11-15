<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Browser\ReadCity;
use JobSearch\Domain\Browser\BrowserCity;

/** Presentation: текущий город browser-сессии. */
final class CityController
{
    public function __construct(private readonly ReadCity $useCase) {}

    public function current(string $storageStatePath): BrowserCity
    {
        return $this->useCase->current($storageStatePath);
    }
}
