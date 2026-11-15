<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Telegram;

/**
 * Межпроцессный flock на весь TG-диалог (send+wait).
 * Letter / captcha / screening не должны параллельно «спорить» за один chat.
 * Вложенные withLock в том же процессе — reentrant (depth).
 */
final class TelegramDmGate
{
    /** @var resource|null */
    private $fh = null;

    private int $depth = 0;

    public function __construct(
        private readonly string $lockPath,
        private readonly ?TelegramDmJournal $journal = null,
    ) {}

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withLock(string $kind, callable $fn, string $vacancyId = '', string $resumeId = '', string $turnId = ''): mixed
    {
        $acquiredHere = false;
        if ($this->depth === 0) {
            $this->acquire($kind, $vacancyId, $resumeId, $turnId);
            $acquiredHere = true;
        }
        $this->depth++;
        try {
            return $fn();
        } finally {
            $this->depth--;
            if ($acquiredHere && $this->depth === 0) {
                $this->release($kind, $vacancyId, $resumeId, $turnId);
            }
        }
    }

    private function acquire(string $kind, string $vacancyId, string $resumeId, string $turnId): void
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new TelegramDmException('Не создать каталог lock: ' . $dir);
        }
        $fh = fopen($this->lockPath, 'c+');
        if ($fh === false) {
            throw new TelegramDmException('Не открыть TG lock: ' . $this->lockPath);
        }
        $waitStart = microtime(true);
        $this->journal?->write(
            'meta',
            $kind,
            'lock_wait',
            $vacancyId,
            $resumeId,
            ['lock' => $this->lockPath],
            $turnId,
        );
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            throw new TelegramDmException('Не взять TG lock: ' . $this->lockPath);
        }
        $waitMs = (int) round((microtime(true) - $waitStart) * 1000);
        $this->fh = $fh;
        $this->journal?->write(
            'meta',
            $kind,
            'lock_acquired',
            $vacancyId,
            $resumeId,
            ['wait_ms' => $waitMs, 'pid' => getmypid() ?: 0],
            $turnId,
        );
        if ($waitMs >= 500) {
            fwrite(STDERR, "TG_DM_LOCK waited {$waitMs}ms (kind={$kind})\n");
        }
    }

    private function release(string $kind, string $vacancyId, string $resumeId, string $turnId): void
    {
        if (!is_resource($this->fh)) {
            $this->fh = null;

            return;
        }
        $this->journal?->write(
            'meta',
            $kind,
            'lock_released',
            $vacancyId,
            $resumeId,
            [],
            $turnId,
        );
        flock($this->fh, LOCK_UN);
        fclose($this->fh);
        $this->fh = null;
    }
}
