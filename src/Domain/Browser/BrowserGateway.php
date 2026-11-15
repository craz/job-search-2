<?php
declare(strict_types=1);

namespace JobSearch\Domain\Browser;

use Playwright\Browser\BrowserContextInterface;

/**
 * Контракт запуска браузера для сценариев HH (прокси, headless, cookies).
 */
interface BrowserGateway
{
    /**
     * Открывает Chromium. Если $storageStatePath задан и файл есть —
     * поднимает контекст уже с сохранённой сессией (cookies).
     * Контекст обязан закрыть вызывающий.
     */
    public function openChromium(?string $storageStatePath = null): BrowserContextInterface;
}
