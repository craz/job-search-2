<?php
declare(strict_types=1);

namespace JobSearch\Application\Resume;

use JobSearch\Domain\Applicant\ApplicantPages;
use JobSearch\Domain\Browser\BrowserGateway;

/**
 * Use case: скачать историю просмотров резюме HH → FileCache.
 */
final class SyncResumeViews
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
        private readonly ResumeViewsStore $store,
    ) {}

    /**
     * @param list<array{id: string, title?: string}> $resumes
     *
     * @return array{ok: bool, resumes: int, employers: int, message: string}
     */
    public function sync(string $storageStatePath, array $resumes): array
    {
        if (!is_readable($storageStatePath)) {
            return [
                'ok' => false,
                'resumes' => 0,
                'employers' => 0,
                'message' => 'Нет browser-сессии. Сначала login.',
            ];
        }

        $ids = [];
        foreach ($resumes as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? $row['external_id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [
                'ok' => false,
                'resumes' => 0,
                'employers' => 0,
                'message' => 'Нет резюме в кеше. Сначала refresh / resumes.',
            ];
        }

        $context = $this->browser->openChromium($storageStatePath);
        $syncedResumes = 0;
        $employerTotal = 0;
        try {
            $page = $context->newPage();
            foreach ($ids as $resumeId) {
                $events = $this->pages->listResumeViews($page, $resumeId);
                $employerTotal += $this->store->replaceForResume($resumeId, $events);
                $syncedResumes++;
            }
        } finally {
            $context->close();
        }

        return [
            'ok' => true,
            'resumes' => $syncedResumes,
            'employers' => $employerTotal,
            'message' => sprintf(
                'Просмотры: %d резюме, %d работодателей.',
                $syncedResumes,
                $employerTotal,
            ),
        ];
    }
}
