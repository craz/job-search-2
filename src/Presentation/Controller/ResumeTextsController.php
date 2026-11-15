<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Resume\DownloadTexts;
use JobSearch\Domain\Resume\ResumeSummary;
use JobSearch\Domain\Resume\ResumeText;

/** Presentation: скачать тексты резюме. */
final class ResumeTextsController
{
    public function __construct(private readonly DownloadTexts $useCase) {}

    /**
     * @param list<ResumeSummary|array{external_id?: string, title?: string}> $items
     * @return list<ResumeText>
     */
    public function downloadMany(string $storageStatePath, array $items): array
    {
        return $this->useCase->downloadMany($storageStatePath, $items);
    }
}
