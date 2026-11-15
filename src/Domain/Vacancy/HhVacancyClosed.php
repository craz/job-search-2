<?php
declare(strict_types=1);

namespace JobSearch\Domain\Vacancy;

/**
 * Закрыта / в архиве / снята — не кандидат в «Смотрим» и не для ingest как active.
 * HH пишет «В архиве с 13 ноября 2026» (без слова «вакансия») — старый regex это пропускал.
 */
final class HhVacancyClosed
{
    public static function looksLike(?string $title, ?string $body = null): bool
    {
        $hay = self::norm($title);
        $bodyNorm = self::norm($body);
        if ($bodyNorm !== '') {
            $hay = $hay === '' ? $bodyNorm : ($hay . "\n" . mb_substr($bodyNorm, 0, 4000));
        }
        if ($hay === '') {
            return false;
        }

        return preg_match(
            '/вам недоступна эта вакансия'
            . '|вакансия\s+(не найдена|снята с публикации|в архиве)'
            . '|в архиве с\s+\d{1,2}/ui',
            $hay,
        ) === 1;
    }

    private static function norm(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    }
}
