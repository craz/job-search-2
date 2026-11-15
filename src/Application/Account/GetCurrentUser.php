<?php
declare(strict_types=1);

namespace JobSearch\Application\Account;

use JobSearch\Domain\Account\HhApi;
use JobSearch\Domain\Account\Me;

/**
 * Use case сценария «текущий пользователь HH» (GET /me).
 * Связывает вход с Model; вывод делает View снаружи.
 */
final class GetCurrentUser
{
    public function __construct(private readonly HhApi $api) {}

    /** Запрашивает профиль текущего OAuth-пользователя. */
    public function currentUser(): Me
    {
        return $this->api->me();
    }
}
