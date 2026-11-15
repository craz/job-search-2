<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Application\JobApplication\AppliedByVacancyStore;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;

/**
 * HH external_id вакансий, по которым уже был отклик (чат / applications / локальная карта резюме).
 */
final class AppliedVacancyIndex
{
    public function __construct(
        private readonly CoreApi $core,
        private readonly ?AppliedByVacancyStore $appliedResumes = null,
    ) {}

    /**
     * @return array<string, array{
     *   applied: true,
     *   resume_external_id: string,
     *   resume_title: string
     * }>
     */
    public function byExternalId(): array
    {
        $out = [];

        $mark = static function (array &$out, string $id, string $resumeId = '', string $resumeTitle = ''): void {
            $id = trim($id);
            if ($id === '') {
                return;
            }
            $prev = $out[$id] ?? null;
            $out[$id] = [
                'applied' => true,
                'resume_external_id' => $resumeId !== ''
                    ? $resumeId
                    : (string) ($prev['resume_external_id'] ?? ''),
                'resume_title' => $resumeTitle !== ''
                    ? $resumeTitle
                    : (string) ($prev['resume_title'] ?? ''),
            ];
        };

        if ($this->appliedResumes !== null) {
            foreach ($this->appliedResumes->all() as $vacancyId => $row) {
                $mark(
                    $out,
                    (string) $vacancyId,
                    (string) ($row['resume_external_id'] ?? ''),
                    (string) ($row['resume_title'] ?? ''),
                );
            }
        }

        try {
            foreach ($this->core->listHhChats()['items'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $mark($out, trim((string) ($row['vacancy_external_id'] ?? '')));
            }
        } catch (CoreException) {
            // чаты недоступны
        }

        try {
            foreach ($this->core->listApplications()['items'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $vacancy = is_array($row['vacancy'] ?? null) ? $row['vacancy'] : [];
                $resumeVersion = trim((string) ($row['resume_version'] ?? ''));
                $mark(
                    $out,
                    trim((string) ($vacancy['external_id'] ?? '')),
                    $resumeVersion,
                );
            }
        } catch (CoreException) {
            // applications недоступны
        }

        return $out;
    }
}
