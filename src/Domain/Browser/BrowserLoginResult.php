<?php
declare(strict_types=1);

namespace JobSearch\Domain\Browser;

/** Итог сценария входа: сессия сохранена в storageState или нет. */
final class BrowserLoginResult
{
    public function __construct(
        public readonly bool $loggedIn,
        public readonly string $storageStatePath,
        public readonly string $url,
        public readonly string $message,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'logged_in' => $this->loggedIn,
            'storage_state' => $this->storageStatePath,
            'url' => $this->url,
            'message' => $this->message,
        ];
    }

    public function isOk(): bool
    {
        return $this->loggedIn;
    }
}
