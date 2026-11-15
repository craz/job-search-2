<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * Единый показ дат в UI: русская нотация, Europe/Moscow.
 *
 * Дата-время события: {@see self::FORMAT_DATETIME} (`dd.mm.YYYY HH:mm:ss`).
 * Календарный день (метрики): {@see self::FORMAT_DAY} (`dd.mm.YYYY`).
 */
final class RuDate
{
    public const TZ = 'Europe/Moscow';
    public const FORMAT_DATETIME = 'd.m.Y H:i:s';
    public const FORMAT_DAY = 'd.m.Y';

    /**
     * ISO / unix / «Y-m-d» → `dd.mm.YYYY HH:mm:ss` (МСК).
     * Пустое → пустая строка (View сам решит «—»).
     */
    public static function dateTime(mixed $value): string
    {
        $dt = self::parse($value);
        if ($dt === null) {
            return '';
        }

        return $dt->setTimezone(new \DateTimeZone(self::TZ))->format(self::FORMAT_DATETIME);
    }

    /**
     * Календарный день → `dd.mm.YYYY` (МСК). Для дневных метрик без ложного времени.
     */
    public static function day(mixed $value): string
    {
        $dt = self::parse($value);
        if ($dt === null) {
            return '';
        }

        return $dt->setTimezone(new \DateTimeZone(self::TZ))->format(self::FORMAT_DAY);
    }

    private static function parse(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $ts = (int) $value;
            if ($ts <= 0) {
                return null;
            }

            return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(self::TZ));
        }
        if (!is_string($value)) {
            return null;
        }
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
