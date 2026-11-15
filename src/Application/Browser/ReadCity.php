<?php
declare(strict_types=1);

namespace JobSearch\Application\Browser;

use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Domain\Browser\BrowserCity;
use JobSearch\Domain\Applicant\ApplicantPages;

/**
 * Use case: какой город сейчас в browser-сессии hh.ru.
 */
final class ReadCity
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
    ) {}

    public function current(string $storageStatePath): BrowserCity
    {
        if (!is_readable($storageStatePath)) {
            throw new \RuntimeException(
                'Нет сессии. Сначала: php bin/hh.php login',
            );
        }

        $context = $this->browser->openChromium($storageStatePath);
        try {
            $page = $context->newPage();
            $page->goto(ApplicantPages::HOME_URL, ['waitUntil' => 'domcontentloaded']);
            usleep(700_000);

            return $this->pages->readCity($page);
        } finally {
            $context->close();
        }
    }
}
