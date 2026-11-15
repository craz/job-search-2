<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Applicant;

use JobSearch\Domain\Applicant\CaptchaSolver;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\Telegram\TelegramDmClient;
use JobSearch\Infrastructure\Telegram\TelegramDmException;
use Playwright\Page\PageInterface;

/**
 * Капча HH → скрин в TG DM → код → ввод (fallback, если авто-сервис не справился).
 * Неверный код → новая картинка в тот же таймаут, без отдельного «Повторить».
 */
final class TelegramCaptchaSolver implements CaptchaSolver
{
    private const DEFAULT_TIMEOUT_S = 600;

    public function __construct(
        private readonly TelegramDmClient $dm,
        private readonly string $shotDir,
        private readonly int $timeoutS = self::DEFAULT_TIMEOUT_S,
    ) {}

    /**
     * Вкл., если есть TELEGRAM_DM_* и HH_CAPTCHA_TG не выключен явно.
     */
    public static function fromConfig(
        Config $config,
        string $shotDir,
        ?TelegramDmClient $dm = null,
    ): ?self {
        $flag = $config->get('HH_CAPTCHA_TG');
        if ($flag !== null && trim($flag) !== '' && !self::envTruthy($flag)) {
            return null;
        }

        $dm ??= TelegramDmClient::fromConfig($config, dirname($shotDir));
        if ($dm === null) {
            return null;
        }

        $timeoutRaw = trim((string) ($config->get('HH_CAPTCHA_TG_TIMEOUT_S') ?? ''));
        $timeout = self::DEFAULT_TIMEOUT_S;
        if ($timeoutRaw !== '' && ctype_digit($timeoutRaw)) {
            $timeout = max(30, (int) $timeoutRaw);
        }

        return new self($dm, $shotDir, $timeout);
    }

    public function enabled(): bool
    {
        return true;
    }

    public function tryResolve(PageInterface $page, string $context = ''): bool
    {
        try {
            $this->resolve($page, $context);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'CAPTCHA_TG fail: ' . $e->getMessage() . "\n");

            return false;
        }

        usleep(1_000_000);

        return !CaptchaForm::pageHasCaptcha($page);
    }

    public function resolve(PageInterface $page, string $context = ''): void
    {
        if (!is_dir($this->shotDir) && !mkdir($this->shotDir, 0775, true) && !is_dir($this->shotDir)) {
            throw new TelegramDmException('Не создать каталог скринов: ' . $this->shotDir);
        }

        $vacancyId = '';
        if (preg_match('/\b(\d{6,})\b/', $context, $m) === 1) {
            $vacancyId = $m[1];
        }

        $this->dm->withConversation(
            'captcha',
            function (TelegramDmClient $dm) use ($page, $context): void {
                $deadline = microtime(true) + $this->timeoutS;
                $attempt = 0;
                while (microtime(true) < $deadline) {
                    $attempt++;
                    $remaining = (int) max(30, ceil($deadline - microtime(true)));
                    $shot = rtrim($this->shotDir, '/') . '/hh_captcha_tg_' . time() . '_' . $attempt . '.png';
                    // Предпочитаем crop img; иначе полный viewport (как раньше).
                    if (!CaptchaForm::cropImage($page, $shot)) {
                        try {
                            $page->screenshot($shot);
                        } catch (\Throwable $e) {
                            throw new TelegramDmException('screenshot failed: ' . $e->getMessage(), 0, $e);
                        }
                    }

                    $titleBit = $context !== ''
                        ? ' · <i>' . htmlspecialchars($context, ENT_QUOTES | ENT_SUBSTITUTE) . '</i>'
                        : '';
                    $waitMin = max(1, intdiv($remaining, 60));
                    if ($attempt === 1) {
                        $caption = "🚨 <b>Капча на HH</b>{$titleBit}\n"
                            . 'Ответьте <b>кодом с картинки</b> в этот чат '
                            . "(слова через пробел). Жду до {$waitMin} мин.";
                    } else {
                        $caption = "🔄 <b>Код не принят</b> — новая капча{$titleBit}\n"
                            . "Ответьте кодом с <b>этой</b> картинки. Осталось ~{$waitMin} мин.";
                    }

                    $offset = $dm->discardPendingUpdates();
                    fwrite(STDERR, "CAPTCHA_TG attempt {$attempt}: send photo → chat, wait up to {$remaining}s\n");
                    $dm->sendPhoto($shot, $caption);
                    $code = trim($dm->waitForText($remaining, $offset));
                    if ($code === '') {
                        throw new TelegramDmException('Пустой код капчи из Telegram');
                    }

                    fwrite(STDERR, "CAPTCHA_TG code received, typing\n");
                    CaptchaForm::typeAndSubmit($page, $code);

                    usleep(2_500_000);
                    if (!CaptchaForm::pageHasCaptcha($page)) {
                        try {
                            $dm->sendMessage('✅ Капча пройдена.');
                        } catch (TelegramDmException) {
                            // ignore
                        }

                        return;
                    }

                    try {
                        $dm->sendMessage('⚠️ Код не принят HH — пришлю новую картинку.');
                    } catch (TelegramDmException) {
                        // ignore
                    }
                    CaptchaForm::refreshImage($page);
                    usleep(1_000_000);
                }

                throw new TelegramDmException(
                    'Капча не снята за ' . $this->timeoutS . ' с (неверный код / таймаут).',
                );
            },
            $vacancyId,
        );
    }

    /** @deprecated use CaptchaForm::pageHasCaptcha */
    public static function pageHasCaptcha(PageInterface $page): bool
    {
        return CaptchaForm::pageHasCaptcha($page);
    }

    private static function envTruthy(string $raw): bool
    {
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
