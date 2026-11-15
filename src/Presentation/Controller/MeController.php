<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Account\GetCurrentUser;
use JobSearch\Domain\Account\Me;

/** Presentation: GET /me. */
final class MeController
{
    public function __construct(private readonly GetCurrentUser $useCase) {}

    public function currentUser(): Me
    {
        return $this->useCase->currentUser();
    }
}
