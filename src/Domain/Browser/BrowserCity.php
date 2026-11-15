<?php
declare(strict_types=1);

namespace JobSearch\Domain\Browser;

/**
 * Город/регион, который сейчас выбран в browser-сессии hh.ru.
 * Берём из хоста (поддомен) и UI-переключателя региона.
 */
final class BrowserCity
{
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly ?string $subdomain,
        public readonly ?string $regionLabel,
        public readonly string $title,
    ) {}

    public function isOk(): bool
    {
        return $this->host !== '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'host' => $this->host,
            'subdomain' => $this->subdomain,
            'region_label' => $this->regionLabel,
            'title' => $this->title,
        ];
    }
}
