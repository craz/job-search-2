<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * Единый зелёный бейдж количества (как notification-dot).
 */
final class CountBadge
{
    public static function html(int|string $count, string $note = ''): string
    {
        $num = is_int($count) ? (string) $count : trim($count);
        if ($num === '') {
            $num = '0';
        }
        $html = '<span class="count-badge">' . self::e($num) . '</span>';
        $note = trim($note);
        if ($note !== '') {
            $html .= '<span class="count-badge-note">' . self::e($note) . '</span>';
        }

        return $html;
    }

    /** Заголовок страницы + бейдж в одной строке. */
    public static function title(string $title, int|string $count, string $note = ''): string
    {
        return '<div class="page-title-row">'
            . '<h1 class="page-title">' . self::e($title) . '</h1>'
            . self::html($count, $note)
            . '</div>';
    }

    /** Подпись фильтра + бейдж (без скобок в тексте). */
    public static function tabLabel(string $label, int $count): string
    {
        return self::e($label) . self::html($count);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
