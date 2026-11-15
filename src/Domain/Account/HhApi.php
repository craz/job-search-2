<?php
declare(strict_types=1);

namespace JobSearch\Domain\Account;

/**
 * Контракт официального API HeadHunter для нужд приложения.
 * Вызывающий код зависит от этого интерфейса, не от curl-клиента.
 */
interface HhApi
{
    /** Текущий пользователь по OAuth: GET /me. */
    public function me(): Me;
}
