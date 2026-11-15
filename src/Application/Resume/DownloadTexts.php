<?php
declare(strict_types=1);

namespace JobSearch\Application\Resume;

use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Resume\ResumeSummary;
use JobSearch\Domain\Resume\ResumeText;

/**
 * Use case: скачать тексты резюме через browser-сессию (конвертер TXT).
 */
final class DownloadTexts
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
    ) {}

    /**
     * @param list<ResumeSummary|array{external_id?: string, title?: string}> $items
     * @return list<ResumeText>
     */
    public function downloadMany(string $storageStatePath, array $items): array
    {
        if (!is_readable($storageStatePath)) {
            throw new \RuntimeException('login_required');
        }

        $context = $this->browser->openChromium($storageStatePath);
        try {
            $page = $context->newPage();
            $out = [];
            foreach ($items as $item) {
                if ($item instanceof ResumeSummary) {
                    $id = $item->externalId;
                    $title = $item->title;
                } elseif (is_array($item)) {
                    $id = isset($item['external_id']) ? (string) $item['external_id'] : '';
                    $title = isset($item['title']) ? (string) $item['title'] : '';
                } else {
                    continue;
                }
                if ($id === '' || $title === '') {
                    continue;
                }
                $text = $this->pages->readResumeTxt($page, $id, $title);
                $out[] = new ResumeText($id, $title, $text);
            }

            return $out;
        } finally {
            $context->close();
        }
    }
}
