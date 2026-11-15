<?php
declare(strict_types=1);

namespace JobSearch\Domain\Vacancy;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Дата публикации вакансии HH («Вакансия опубликована 10 ноября 2026 в Москве»).
 */
final class VacancyPublishedAt
{
    /** @var array<string, int> */
    private const MONTHS = [
        'января' => 1,
        'февраля' => 2,
        'марта' => 3,
        'апреля' => 4,
        'мая' => 5,
        'июня' => 6,
        'июля' => 7,
        'августа' => 8,
        'сентября' => 9,
        'октября' => 10,
        'ноября' => 11,
        'декабря' => 12,
    ];

    /**
     * @param string $raw фрагмент вроде «10 ноября 2026» или полный текст страницы
     */
    public static function parse(string $raw): ?DateTimeImmutable
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match(
            '/(?:вакансия\s+опубликована\s+)?(\d{1,2})\s+([а-яё]+)\s+(\d{4})/ui',
            $raw,
            $m,
        ) !== 1) {
            return null;
        }

        $day = (int) $m[1];
        $monthName = mb_strtolower(trim((string) $m[2]), 'UTF-8');
        $year = (int) $m[3];
        $month = self::MONTHS[$monthName] ?? 0;
        if ($month < 1 || $day < 1 || $day > 31 || $year < 2000) {
            return null;
        }

        $tz = new DateTimeZone('Europe/Moscow');
        $dt = DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', $year, $month, $day), $tz);
        if ($dt === false) {
            return null;
        }

        return $dt;
    }

    /** Свежая ли публикация относительно «сегодня» в Europe/Moscow (включительно день maxAgeDays назад). */
    public static function isFresh(
        DateTimeImmutable $published,
        int $maxAgeDays = 7,
        ?DateTimeImmutable $now = null,
    ): bool {
        $maxAgeDays = max(1, $maxAgeDays);
        $tz = new DateTimeZone('Europe/Moscow');
        $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
        $published = $published->setTimezone($tz)->setTime(0, 0, 0);
        $oldest = $now->setTime(0, 0, 0)->modify('-' . ($maxAgeDays - 1) . ' days');

        return $published >= $oldest;
    }
}
