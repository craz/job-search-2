<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;
use JobSearch\Domain\Vacancy\HhBotWall;

/**
 * Список очереди bot_wall_rehydrate для UI.
 */
final class ListBotWallRehydrate
{
    public function __construct(
        private readonly BotWallRehydrateStore $store,
        private readonly CoreApi $core,
        private readonly VacancyResumeOwnerStore $owners,
    ) {}

    /**
     * @return array{
     *   count: int,
     *   items: list<array{
     *     external_id: string,
     *     title: string,
     *     company: string,
     *     resume_title: string,
     *     still_bot: bool,
     *     url: string,
     *     queued_at: string
     *   }>
     * }
     */
    public function page(int $limit = 80): array
    {
        $limit = max(1, min(200, $limit));
        $byId = $this->store->externalIdSet();
        $ids = array_map(static fn (string|int $id): string => (string) $id, array_keys($byId));
        sort($ids, SORT_STRING);
        $slice = array_slice($ids, 0, $limit);

        $map = [];
        if ($slice !== []) {
            try {
                $map = $this->core->lookupVacancyIdsByExternalIds($slice);
            } catch (CoreException) {
                $map = [];
            }
        }

        $items = [];
        foreach ($slice as $ext) {
            $title = '';
            $company = '';
            $stillBot = true;
            $coreId = isset($map[$ext]) ? trim((string) $map[$ext]) : '';
            if ($coreId !== '') {
                try {
                    $v = $this->core->getVacancy($coreId);
                    $title = trim((string) ($v['title'] ?? ''));
                    $company = '';
                    if (is_array($v['company'] ?? null)) {
                        $company = trim((string) ($v['company']['name'] ?? ''));
                    }
                    if ($company === '') {
                        $company = trim((string) ($v['company_name'] ?? ''));
                    }
                    $stillBot = HhBotWall::looksLike($title, (string) ($v['description'] ?? ''));
                } catch (CoreException) {
                }
            }
            $owner = $this->owners->get($ext);
            $items[] = [
                'external_id' => $ext,
                'title' => $title !== '' ? $title : '—',
                'company' => $company !== '' ? $company : '—',
                'resume_title' => trim((string) ($owner['resume_title'] ?? '')),
                'still_bot' => $stillBot,
                'url' => 'https://hh.ru/vacancy/' . rawurlencode($ext),
                'queued_at' => '',
            ];
        }

        return [
            'count' => count($ids),
            'items' => $items,
        ];
    }
}
