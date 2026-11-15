<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Text;

/**
 * Чистка битого UTF-8 (HH/Core иногда отдают invalid sequences → json_encode THROW).
 */
final class Utf8
{
    public static function scrub(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false) {
            $decoded = json_decode($json);
            if (is_string($decoded)) {
                return $decoded;
            }
        }
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return is_string($fixed) ? $fixed : '';
    }

    /**
     * Рекурсивно по массиву/скалярам перед JSON.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function scrubMixed(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::scrub($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = is_string($k) ? self::scrub($k) : $k;
                $out[$key] = self::scrubMixed($v);
            }

            return $out;
        }

        return $value;
    }

    /** Флаги для исходящего JSON (не падать на битом UTF-8). */
    public static function jsonFlags(int $extra = 0): int
    {
        return JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | $extra;
    }
}
