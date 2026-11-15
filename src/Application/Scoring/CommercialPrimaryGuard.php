<?php
declare(strict_types=1);

namespace JobSearch\Application\Scoring;

/**
 * Post-guard: не давать ложный FIT.commercial_primary на product-economy (CPO/HoP/PO).
 * Sales-primary и личный leadgen у «продуктового» title оставляем hard SKIP.
 */
final class CommercialPrimaryGuard
{
    private const FACTOR = 'FIT.commercial_primary';

    private const PRODUCT_TITLE = '~(?:'
        . '\bcpo\b|'
        . 'chief\s+product\s+officer|'
        . 'head\s+of\s+product|'
        . 'product\s+owner|'
        . 'product\s+manager|'
        . 'руководитель\w*\s+по\s+продукт|'
        . 'руководител\w*\s+продукт|'
        . 'директор\w*\s+по\s+продукт'
        . ')~iu';

    private const SALES_TITLE = '~(?:'
        . 'head\s+of\s+sales|'
        . 'sales\s+director|'
        . 'директор\s+по\s+продажам|'
        . 'коммерческ\w*\s+директор|'
        . 'руководитель\s+отдела\s+продаж|'
        . 'начальник\s+отдела\s+продаж|'
        . 'руководитель\s+продаж\b|'
        . '\bbdm\b|'
        . 'business\s+development|'
        . 'key\s+account|'
        . '\bkam\b|'
        . 'account\s+manager|'
        . 'менеджер\s+по\s+продажам|'
        . 'sales\s+manager'
        . ')~iu';

    /** Личная коммерческая охота как основная обязанность (даже у CPO). */
    private const SALES_PRIMARY_DUTY = '~(?:'
        . 'самостоятельн\w*\s+лидогенерац|'
        . 'активн\w*\s+(?:поиск|привлечен\w*)\s+(?:новых\s+)?(?:клиент|селлер|продавц)|'
        . 'привлечен\w*\s+новых\s+клиент|'
        . 'план\s+продаж|выполнен\w*\s+план\w*\s+продаж|'
        . 'квот\w*\s+продаж|sales\s+quota|own\s+(?:the\s+)?sales\s+pipeline|'
        . 'закрыт\w*\s+сдел|'
        . 'hunt(?:ing)?\s+new\s+clients|'
        . 'generate\s+leads|'
        . 'личн\w*\s+привлечен\w*\s+(?:клиент|селлер|продавц)'
        . ')~iu';

    /**
     * @param array{verdict: string, reason: string, action: string, decisive_factors: list<string>} $parsed
     * @param array{title?: string, description?: string} $vacancy
     *
     * @return array{verdict: string, reason: string, action: string, decisive_factors: list<string>}
     */
    public function apply(array $parsed, array $vacancy): array
    {
        $factors = array_values(array_filter(
            $parsed['decisive_factors'],
            static fn (string $f): bool => $f !== '',
        ));
        if (!$this->hasCommercialFactor($factors) && strtolower($parsed['verdict']) !== 'skip') {
            return $parsed;
        }
        // Если skip без commercial factor — не трогаем (другие hard rules).
        if (!$this->hasCommercialFactor($factors)) {
            return $parsed;
        }

        $title = trim((string) ($vacancy['title'] ?? ''));
        $description = trim((string) ($vacancy['description'] ?? ''));
        $blob = $title . "\n" . $description;

        if ($this->isSalesTitle($title) || $this->isSalesPrimaryDuty($blob)) {
            // Настоящий sales / CPO-с-охотой — SKIP остаётся.
            return $parsed;
        }

        if ($this->isProductTitle($title) || $this->isProductPrimaryWithoutSalesDuty($blob)) {
            return $this->demoteCommercialSkip($parsed, $factors);
        }

        // Неоднозначно: нет явного sales-primary, но LLM повесил commercial → maybe.
        return $this->demoteCommercialSkip($parsed, $factors);
    }

    /**
     * Для unit-тестов: нужно ли снять commercial hard-skip.
     *
     * @param array{title?: string, description?: string} $vacancy
     */
    public function shouldDemoteCommercialSkip(array $vacancy): bool
    {
        $title = trim((string) ($vacancy['title'] ?? ''));
        $description = trim((string) ($vacancy['description'] ?? ''));
        $blob = $title . "\n" . $description;
        if ($this->isSalesTitle($title) || $this->isSalesPrimaryDuty($blob)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $factors
     */
    private function hasCommercialFactor(array $factors): bool
    {
        foreach ($factors as $f) {
            if (strcasecmp($f, self::FACTOR) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isProductTitle(string $title): bool
    {
        return preg_match(self::PRODUCT_TITLE, $title) === 1;
    }

    private function isSalesTitle(string $title): bool
    {
        return preg_match(self::SALES_TITLE, $title) === 1;
    }

    private function isSalesPrimaryDuty(string $blob): bool
    {
        return preg_match(self::SALES_PRIMARY_DUTY, $blob) === 1;
    }

    /** Продуктовая суть без sales-primary duty (по тексту). */
    private function isProductPrimaryWithoutSalesDuty(string $blob): bool
    {
        if ($this->isSalesPrimaryDuty($blob)) {
            return false;
        }

        return preg_match(
            '~(?:продукт|платформ|marketplace|маркетплейс|gmv|unit.?econom|юнит.?эконом|'
            . 'монетизац|roadmap|backlog|customer\s+journey)~iu',
            $blob,
        ) === 1;
    }

    /**
     * @param array{verdict: string, reason: string, action: string, decisive_factors: list<string>} $parsed
     * @param list<string> $factors
     *
     * @return array{verdict: string, reason: string, action: string, decisive_factors: list<string>}
     */
    private function demoteCommercialSkip(array $parsed, array $factors): array
    {
        $filtered = array_values(array_filter(
            $factors,
            static fn (string $f): bool => strcasecmp($f, self::FACTOR) !== 0,
        ));
        $verdict = strtolower($parsed['verdict']);
        // Нельзя ронять skip→maybe, если остаются другие hard-factors (leadgen/1С/…).
        $otherHard = $this->hasOtherHardSkipFactor($filtered);
        if ($verdict === 'skip' && !$otherHard) {
            $verdict = 'maybe';
        }
        $reason = trim($parsed['reason']);
        $note = 'Снят ложный FIT.commercial_primary: коммерческие метрики продукта ≠ sales-профессия.';
        if ($reason === '') {
            $reason = $note;
        } elseif (!str_contains($reason, 'ложный FIT.commercial_primary')) {
            $reason .= ' (' . $note . ')';
        }
        $action = $verdict === 'maybe' ? 'Уточнить вручную' : $parsed['action'];

        return [
            'verdict' => $verdict,
            'reason' => $reason,
            'action' => $action,
            'decisive_factors' => $filtered,
        ];
    }

    /**
     * @param list<string> $factors
     */
    private function hasOtherHardSkipFactor(array $factors): bool
    {
        foreach ($factors as $f) {
            $f = trim($f);
            if ($f === '' || strcasecmp($f, self::FACTOR) === 0) {
                continue;
            }
            if (preg_match('/^(FIT\.|DESIRABILITY\.)/i', $f) === 1) {
                return true;
            }
        }

        return false;
    }
}
