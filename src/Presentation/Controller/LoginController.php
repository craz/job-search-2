<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Browser\LoginSession;
use JobSearch\Domain\Browser\BrowserLoginResult;

/** Presentation: browser login + storageState. */
final class LoginController
{
    public function __construct(private readonly LoginSession $useCase) {}

    public function loginAndSaveSession(
        string $storageStatePath,
        int $timeoutSeconds = 300,
        ?string $login = null,
        ?string $password = null,
    ): BrowserLoginResult {
        return $this->useCase->loginAndSaveSession($storageStatePath, $timeoutSeconds, $login, $password);
    }
}
