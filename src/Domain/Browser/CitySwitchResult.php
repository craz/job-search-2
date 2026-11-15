<?php
declare(strict_types=1);

namespace JobSearch\Domain\Browser;

/** Итог переключения города в browser-сессии hh.ru. */
final class CitySwitchResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $requestedCity,
        public readonly BrowserCity $city,
        public readonly string $message,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'requested_city' => $this->requestedCity,
            'city' => $this->city->toArray(),
            'message' => $this->message,
        ];
    }

    public function isOk(): bool
    {
        return $this->ok;
    }
}
