<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Application\JobApplication\AppliedByVacancyStore;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;

/**
 * История откликов по резюме: Core applications + FileCache + hh_chats (без suitable-run).
 */
final class AppliedHistoryForResume
{
    public function __construct(
        private readonly CoreApi $core,
        private readonly ?AppliedByVacancyStore $appliedResumes = null,
    ) {}

    /**
     * Карточки в форме списка вакансий (origin=applied). Дедуп по external_id.
     *
     * @return list<array<string, mixed>>
     */
    public function forResume(string $resumeId): array
    {
        $resumeId = trim($resumeId);
        if ($resumeId === '') {
            return [];
        }

        $chats = $this->chatsByVacancy();
        /** @var array<string, array<string, mixed>> $byExt */
        $byExt = [];

        try {
            $apps = $this->core->listApplications()['items'] ?? [];
        } catch (CoreException) {
            $apps = [];
        }
        if (!is_array($apps)) {
            $apps = [];
        }

        foreach ($apps as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['resume_version'] ?? '')) !== $resumeId) {
                continue;
            }
            $vacancy = is_array($row['vacancy'] ?? null) ? $row['vacancy'] : [];
            $extId = trim((string) ($vacancy['external_id'] ?? ''));
            if ($extId === '') {
                $extId = $this->externalIdFromAppExternal((string) ($row['external_id'] ?? ''));
            }
            if ($extId === '') {
                continue;
            }
            $appliedAt = trim((string) ($row['applied_at'] ?? $row['created_at'] ?? ''));
            $chat = $chats[$extId] ?? $chats[(int) $extId] ?? null;
            $byExt[$extId] = $this->row(
                externalId: $extId,
                coreId: trim((string) ($vacancy['id'] ?? '')),
                title: trim((string) ($vacancy['title'] ?? '')),
                company: trim((string) ($vacancy['company_name'] ?? '')),
                url: trim((string) ($vacancy['url'] ?? '')),
                appliedAt: $appliedAt,
                resumeId: $resumeId,
                resumeTitle: '',
                chat: is_array($chat) ? $chat : null,
            );
        }

        $map = $this->appliedResumes?->all() ?? [];
        foreach ($map as $vacancyId => $meta) {
            $vacancyId = trim((string) $vacancyId);
            if ($vacancyId === '' || !is_array($meta)) {
                continue;
            }
            if (trim((string) ($meta['resume_external_id'] ?? '')) !== $resumeId) {
                continue;
            }
            $chat = $chats[$vacancyId] ?? $chats[(int) $vacancyId] ?? null;
            $prev = $byExt[$vacancyId] ?? null;
            if (is_array($prev)) {
                $byExt[$vacancyId] = $this->enrichFromMapAndChat($prev, $meta, is_array($chat) ? $chat : null);
                continue;
            }
            $title = '';
            $company = '';
            $url = 'https://hh.ru/vacancy/' . rawurlencode($vacancyId);
            $coreId = '';
            $appliedAt = trim((string) ($meta['applied_at'] ?? ''));
            if (is_array($chat)) {
                $title = trim((string) ($chat['vacancy_title'] ?? ''));
                $company = trim((string) ($chat['company_name'] ?? ''));
                $chatUrl = trim((string) ($chat['url'] ?? ''));
                if ($chatUrl !== '' && str_contains($chatUrl, 'vacancy')) {
                    // url чата — chat URL; вакансия отдельно
                }
                if ($appliedAt === '') {
                    $appliedAt = trim((string) ($chat['last_message_at'] ?? $chat['last_activity_at'] ?? ''));
                }
            }
            $byExt[$vacancyId] = $this->row(
                externalId: $vacancyId,
                coreId: $coreId,
                title: $title,
                company: $company,
                url: $url,
                appliedAt: $appliedAt,
                resumeId: $resumeId,
                resumeTitle: trim((string) ($meta['resume_title'] ?? '')),
                chat: is_array($chat) ? $chat : null,
            );
        }

        // Чаты с известным резюме только через map уже покрыты; чаты без map не привяжем к резюме.

        $list = array_values($byExt);
        usort(
            $list,
            static function (array $a, array $b): int {
                $ta = (string) ($a['activity_at'] ?? $a['discovered_at'] ?? '');
                $tb = (string) ($b['activity_at'] ?? $b['discovered_at'] ?? '');

                return $tb <=> $ta;
            },
        );

        return $list;
    }

    /**
     * @return array<string, array<string, mixed>> vacancy_external_id → chat row
     */
    private function chatsByVacancy(): array
    {
        try {
            $items = $this->core->listHhChats()['items'] ?? [];
        } catch (CoreException) {
            return [];
        }
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ext = trim((string) ($row['vacancy_external_id'] ?? ''));
            if ($ext === '') {
                continue;
            }
            $prev = $out[$ext] ?? null;
            $prevTime = is_array($prev)
                ? (string) ($prev['last_message_at'] ?? $prev['last_activity_at'] ?? '')
                : '';
            $curTime = (string) ($row['last_message_at'] ?? $row['last_activity_at'] ?? '');
            if ($prev === null || $curTime > $prevTime) {
                $out[$ext] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $prev
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $chat
     *
     * @return array<string, mixed>
     */
    private function enrichFromMapAndChat(array $prev, array $meta, ?array $chat): array
    {
        $title = trim((string) ($meta['resume_title'] ?? ''));
        if ($title !== '' && trim((string) ($prev['applied_resume_title'] ?? '')) === '') {
            $prev['applied_resume_title'] = $title;
        }
        if ($chat !== null) {
            $prev = $this->applyChat($prev, $chat);
        }

        return $prev;
    }

    /**
     * @param array<string, mixed>|null $chat
     *
     * @return array<string, mixed>
     */
    private function row(
        string $externalId,
        string $coreId,
        string $title,
        string $company,
        string $url,
        string $appliedAt,
        string $resumeId,
        string $resumeTitle,
        ?array $chat,
    ): array {
        if ($url === '' && $externalId !== '') {
            $url = 'https://hh.ru/vacancy/' . rawurlencode($externalId);
        }
        $row = [
            'core_id' => $coreId,
            'external_id' => $externalId,
            'title' => $title,
            'url' => $url,
            'company_name' => $company,
            'description' => '',
            'key_skills' => [],
            'salary_text' => '',
            'area_text' => '',
            'outcome' => '',
            'verdict' => '',
            'reason' => '',
            'action' => '',
            'assessed_at' => '',
            'discovered_at' => $appliedAt,
            'search_run_id' => '',
            'origin' => 'applied',
            'applied' => true,
            'applied_resume_id' => $resumeId,
            'applied_resume_title' => $resumeTitle,
            'applied_at' => $appliedAt,
            'activity_at' => $appliedAt,
            'chat_status' => '',
            'chat_status_label' => '',
            'error' => '',
        ];
        if ($chat !== null) {
            $row = $this->applyChat($row, $chat);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $chat
     *
     * @return array<string, mixed>
     */
    private function applyChat(array $row, array $chat): array
    {
        if (trim((string) ($row['title'] ?? '')) === '') {
            $row['title'] = trim((string) ($chat['vacancy_title'] ?? ''));
        }
        if (trim((string) ($row['company_name'] ?? '')) === '') {
            $row['company_name'] = trim((string) ($chat['company_name'] ?? ''));
        }
        $state = trim((string) ($chat['applicant_state'] ?? ''));
        $label = trim((string) ($chat['status_label'] ?? ''));
        $preview = trim((string) ($chat['last_message_preview'] ?? ''));
        if ($this->isClosedVacancyPreview($preview) || mb_strtolower($label, 'UTF-8') === 'закрыта') {
            $state = 'DISCARD';
            $label = 'Закрыта';
        } elseif ($label === '' && $state !== '') {
            $label = $this->statusLabel($state);
        }
        $row['chat_status'] = $state;
        $row['chat_status_label'] = $label;
        $msgAt = trim((string) ($chat['last_message_at'] ?? ''));
        $actAt = trim((string) ($chat['last_activity_at'] ?? ''));
        $best = $msgAt !== '' ? $msgAt : $actAt;
        if ($best !== '') {
            $row['activity_at'] = $best;
            if (trim((string) ($row['discovered_at'] ?? '')) === '') {
                $row['discovered_at'] = $best;
            }
            if (trim((string) ($row['applied_at'] ?? '')) === '') {
                $row['applied_at'] = $best;
            }
        }

        return $row;
    }

    private function statusLabel(string $state): string
    {
        return match (strtoupper($state)) {
            'RESPONSE' => 'Отклик на вакансию',
            'INVITATION' => 'Приглашение',
            'DISCARD', 'DISCARDED' => 'Отказ',
            'INTERVIEW' => 'Собеседование',
            'CONSIDER' => 'Резюме на рассмотрении',
            'PHONE_INTERVIEW' => 'Телефонное интервью',
            '' => '',
            default => $state,
        };
    }

    private function isClosedVacancyPreview(string $preview): bool
    {
        $preview = mb_strtolower(str_replace("\u{00A0}", ' ', $preview), 'UTF-8');
        $preview = preg_replace('/\s+/u', ' ', $preview) ?? $preview;

        return str_contains($preview, 'закрыли эту позицию');
    }

    /** `vacancy-page-applied:139154219` → `139154219`. */
    private function externalIdFromAppExternal(string $externalId): string
    {
        $externalId = trim($externalId);
        if (preg_match('~(?:^|:)(\d{5,})$~', $externalId, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
