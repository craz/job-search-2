<?php
declare(strict_types=1);

namespace JobSearch\Application\Browser;

use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Domain\Browser\CitySwitchResult;
use JobSearch\Domain\Applicant\ApplicantPages;

/**
 * Use case: переключить город в browser-сессии hh.ru и сохранить storageState.
 */
final class SwitchCity
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
    ) {}

    public function switchTo(string $storageStatePath, string $cityName): CitySwitchResult
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

            $before = $this->pages->readCity($page);
            if ($this->sameCity($before->regionLabel, $cityName)) {
                return new CitySwitchResult(
                    true,
                    $cityName,
                    $before,
                    'Город уже выбран, переключение не требуется.',
                );
            }

            $this->pages->switchCity($page, $cityName);
            $after = $this->pages->readCity($page);

            if (!$this->sameCity($after->regionLabel, $cityName)) {
                return new CitySwitchResult(
                    false,
                    $cityName,
                    $after,
                    'Не удалось подтвердить город после переключения.',
                );
            }

            $context->saveStorageState($storageStatePath);
            chmod($storageStatePath, 0600);

            return new CitySwitchResult(
                true,
                $cityName,
                $after,
                'Город переключён, сессия сохранена.',
            );
        } finally {
            $context->close();
        }
    }

    private function sameCity(?string $current, string $requested): bool
    {
        if ($current === null || $current === '') {
            return false;
        }

        return mb_strtolower(trim($current)) === mb_strtolower(trim($requested));
    }
}
