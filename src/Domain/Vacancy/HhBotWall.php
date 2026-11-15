<?php
declare(strict_types=1);

namespace JobSearch\Domain\Vacancy;

/**
 * Страница HH «Подтвердите, что вы не робот» / DDoS-Guard — не вакансия.
 * Если проглотить как title/description, DevAI рисует мусорный maybe.
 */
final class HhBotWall
{
    public static function looksLike(?string $title, ?string $body = null): bool
    {
        $title = self::norm($title);
        $body = self::norm($body);
        if ($title !== '' && preg_match(
            '/подтвердите.*не\s*робот|checking your browser|verify your browser|ddos[\s_-]*guard|^я\s+не\s+робот$/ui',
            $title,
        ) === 1) {
            return true;
        }
        $slice = mb_substr($body, 0, 2000);
        if ($slice !== '' && preg_match(
            '/подтвердите,\s*что вы не робот|checking your browser|verify your browser|ddos[\s_-]*guard/ui',
            $slice,
        ) === 1) {
            return true;
        }

        return false;
    }

    private static function norm(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    }
}
