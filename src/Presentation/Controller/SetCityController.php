<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Browser\SwitchCity;
use JobSearch\Domain\Browser\CitySwitchResult;

/** Presentation: смена города в browser-сессии. */
final class SetCityController
{
    public function __construct(private readonly SwitchCity $useCase) {}

    public function switchTo(string $storageStatePath, string $cityName): CitySwitchResult
    {
        return $this->useCase->switchTo($storageStatePath, $cityName);
    }
}
