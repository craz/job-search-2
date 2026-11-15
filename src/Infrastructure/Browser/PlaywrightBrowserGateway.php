<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Browser;


use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Infrastructure\Config\Config;
use Playwright\Browser\BrowserContextInterface;
use Playwright\PlaywrightClient;
use Playwright\PlaywrightFactory;

/**
 * Запуск Chromium через playwright-php + опциональный storageState (сессия HH).
 *
 * PlaywrightClient держим в свойстве, пока жив контекст — иначе JSON-RPC падает.
 */
final class PlaywrightBrowserGateway implements BrowserGateway
{
    private ?PlaywrightClient $client = null;

    public function __construct(
        private readonly string $proxy = '',
        private readonly bool $headless = true,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $headlessRaw = $config->get('HH_BROWSER_HEADLESS', '1') ?? '1';

        return new self(
            proxy: $config->get('HH_PROXY', '') ?? '',
            headless: !in_array(strtolower($headlessRaw), ['0', 'false', 'no'], true),
        );
    }

    public function openChromium(?string $storageStatePath = null): BrowserContextInterface
    {
        $this->client ??= PlaywrightFactory::create();
        $builder = $this->client->chromium()->withHeadless($this->headless);

        if ($this->proxy !== '') {
            $server = preg_replace('#^socks5h://#', 'socks5://', $this->proxy) ?? $this->proxy;
            $builder->withProxy(['server' => $server]);
        }

        $browser = $builder->launch();

        if ($storageStatePath !== null && is_readable($storageStatePath)) {
            $context = $browser->newContext(['storageState' => $storageStatePath]);
        } else {
            $context = $browser->context();
        }

        return $context;
    }
}
