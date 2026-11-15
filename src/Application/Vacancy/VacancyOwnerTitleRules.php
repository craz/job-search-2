<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

/**
 * Жёсткие правила title вакансии → resume_id (без DevAI).
 * Активные роли: TPM / PM / PHP(Bitrix).
 */
final class VacancyOwnerTitleRules
{
    /**
     * @param array{title?: string, description?: string|null} $vacancy
     * @param list<string> $candidates
     * @param array<string, string> $resumeTitles
     *
     * @return array{resume_id: string, resume_title: string, reason: string}|null
     */
    public function pick(array $vacancy, array $candidates, array $resumeTitles): ?array
    {
        $byRole = $this->indexByRole($candidates, $resumeTitles);
        if ($byRole === []) {
            return null;
        }

        $title = mb_strtolower(trim((string) ($vacancy['title'] ?? '')), 'UTF-8');
        $desc = mb_strtolower(trim((string) ($vacancy['description'] ?? '')), 'UTF-8');
        $blob = $title . "\n" . mb_substr($desc, 0, 1500);
        if ($title === '') {
            return null;
        }

        $isDeliveryTitle = preg_match(
            '/\bdelivery\b|delivery\s*manager|technical\s*project\s*manager|техническ\w*\s+project\s*manager|\btpm\b|sdm\b|менеджер\s+изменений|change\s*manager|потока?\s+поставк|head\s+of\s+delivery|release\s*manager|launch\s*manager/u',
            $title,
        ) === 1;
        $isBitrixTitle = preg_match(
            '/bitrix|битрикс|1[сc][-\s]?битрикс|\bphp\b|разработчик|developer|программист/u',
            $title,
        ) === 1;
        $isProductTitle = preg_match(
            '/product\s*owner|\bpo\b|\bcpo\b|product\s*manager|head\s+of\s+product|продакт|менеджер\s+(?:по\s+)?(?:\S+\s+)?продукт|руководитель\s+продукт|\bproduct\b/u',
            $title,
        ) === 1;
        // RU: «Проектный менеджер», «менеджер проектов», «директор проектов» — не только EN project manager.
        $isPmTitle = preg_match(
            '/project\s*manager|проектн\w*\s+менеджер|менеджер\s+(?:по\s+)?проект|руководитель\s+(?:it|ит)[-\s]?проект|менеджер\s+(?:it|ит)[-\s]?проект|руководитель\s+(?:портфеля\s+)?проектов|директор\s+проектов|администратор\s+проект/u',
            $title,
        ) === 1;
        // Scrum/HIT-резюме сняты: facilitation → ближайший TPM/Delivery.
        $isScrumTitle = preg_match(
            '/scrum\s*master|скрам[-\s]?мастер|agile\s*coach|agile[-\s]?коуч|scrum[-\s]?мастер/u',
            $title,
        ) === 1;

        if ($isDeliveryTitle && isset($byRole['tpm'])) {
            return $this->pickRow($byRole['tpm'], $resumeTitles, 'Title про Delivery/TPM.');
        }
        if ($isScrumTitle && isset($byRole['tpm'])) {
            return $this->pickRow($byRole['tpm'], $resumeTitles, 'Scrum/Agile facilitation → TPM (SM-резюме нет).');
        }
        if ($isBitrixTitle && !$isPmTitle && !$isDeliveryTitle && isset($byRole['bitrix'])) {
            return $this->pickRow($byRole['bitrix'], $resumeTitles, 'Title про разработку Bitrix/PHP.');
        }
        if (($isPmTitle || $isProductTitle) && isset($byRole['pm'])) {
            return $this->pickRow(
                $byRole['pm'],
                $resumeTitles,
                'Title про PM/проект/продукт.',
            );
        }
        if (
            isset($byRole['tpm'])
            && preg_match('/delivery\s*manager|потока?\s+поставк|менеджер\s+изменений/u', $blob) === 1
        ) {
            return $this->pickRow($byRole['tpm'], $resumeTitles, 'В тексте роль Delivery — TPM.');
        }

        return null;
    }

    /**
     * @param list<string> $candidates
     * @param array<string, string> $resumeTitles
     *
     * @return array<string, string> role → resume_id
     */
    private function indexByRole(array $candidates, array $resumeTitles): array
    {
        $out = [];
        foreach ($candidates as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $t = mb_strtolower(trim((string) ($resumeTitles[$id] ?? '')), 'UTF-8');
            if ($t === '') {
                continue;
            }
            if (preg_match('/delivery|technical\s*project\s*manager|\btpm\b/u', $t) === 1) {
                $out['tpm'] = $id;
            } elseif (preg_match('/bitrix|битрикс|\bphp\b/u', $t) === 1) {
                $out['bitrix'] = $id;
            } elseif (preg_match('/project\s*manager|руководитель\s+it-проект|руководитель\s+проект/u', $t) === 1) {
                $out['pm'] = $id;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $resumeTitles
     *
     * @return array{resume_id: string, resume_title: string, reason: string}
     */
    private function pickRow(string $resumeId, array $resumeTitles, string $reason): array
    {
        return [
            'resume_id' => $resumeId,
            'resume_title' => trim((string) ($resumeTitles[$resumeId] ?? '')),
            'reason' => $reason,
        ];
    }
}
