<?php
declare(strict_types=1);

namespace JobSearch\Application\JobApplication;

/**
 * Пауза между откликами в пакете (антибот): 30–60 с случайно.
 */
final class ApplyPace
{
    public const MIN_DELAY_S = 30;
    public const MAX_DELAY_S = 60;

    /** Секунды паузы перед попыткой ($index 0-based); 0 = первая, без паузы. */
    public static function delaySecondsBefore(int $index): int
    {
        if ($index <= 0) {
            return 0;
        }

        return random_int(self::MIN_DELAY_S, self::MAX_DELAY_S);
    }

    public static function sleepSeconds(int $sec): void
    {
        $sec = max(0, $sec);
        if ($sec <= 0) {
            return;
        }
        fwrite(STDERR, "APPLY_PACE sleep {$sec}s\n");
        sleep($sec);
    }
}
