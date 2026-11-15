<?php
declare(strict_types=1);

namespace JobSearch\Application\Resume;

use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Resume\ResumeList;
use JobSearch\Domain\Resume\ResumeListKind;

/**
 * Use case: список своих резюме через UI hh.ru и сохранённую browser-сессию.
 */
final class ListMine
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
    ) {}

    public function listMine(string $storageStatePath): ResumeList
    {
        if (!is_readable($storageStatePath)) {
            return new ResumeList(
                ResumeListKind::LoginRequired,
                [],
                '',
            );
        }

        $context = $this->browser->openChromium($storageStatePath);
        try {
            $page = $context->newPage();
            $page->goto(ApplicantPages::RESUMES_URL, ['waitUntil' => 'domcontentloaded']);
            // Дать дорисовать карточки.
            usleep(1_500_000);

            return $this->pages->readResumes($page);
        } finally {
            $context->close();
        }
    }
}
