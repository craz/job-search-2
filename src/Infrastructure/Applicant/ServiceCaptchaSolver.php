<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Applicant;

use JobSearch\Domain\Applicant\CaptchaSolver;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\Telegram\TelegramDmClient;
use Playwright\Page\PageInterface;

/**
 * HH ImageToText-капча → RuCaptcha/2captcha createTask (с cost) → ввод.
 * При исчерпании попыток / сбое API — TelegramCaptchaSolver (если есть).
 */
final class ServiceCaptchaSolver implements CaptchaSolver
{
    private const DEFAULT_TIMEOUT_S = 120;
    private const DEFAULT_ATTEMPTS = 4;
    private const POLL_INTERVAL_S = 5;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $taskApiBase,
        private readonly string $providerLabel,
        private readonly string $shotDir,
        private readonly int $timeoutS = self::DEFAULT_TIMEOUT_S,
        private readonly int $maxAttempts = self::DEFAULT_ATTEMPTS,
        private readonly ?CaptchaSolver $fallback = null,
        private readonly ?TelegramDmClient $dm = null,
    ) {}

    public static function fromConfig(
        Config $config,
        string $shotDir,
        ?TelegramDmClient $dm = null,
        ?CaptchaSolver $fallback = null,
    ): ?self {
        $flag = $config->get('HH_CAPTCHA_AUTO');
        if ($flag !== null && trim($flag) !== '' && !self::envTruthy($flag)) {
            return null;
        }

        $twoKey = trim((string) ($config->get('TWOCAPTCHA_API_KEY') ?? ''));
        $ruKey = trim((string) ($config->get('RUCAPTCHA_API_KEY') ?? ''));
        if ($twoKey === '' && $ruKey === '') {
            return null;
        }

        // 2captcha key имеет приоритет; иначе RuCaptcha.
        if ($twoKey !== '') {
            $apiKey = $twoKey;
            $taskApiBase = 'https://api.2captcha.com';
            $label = '2captcha';
        } else {
            $apiKey = $ruKey;
            $taskApiBase = 'https://api.rucaptcha.com';
            $label = 'rucaptcha';
        }

        $timeoutRaw = trim((string) ($config->get('HH_CAPTCHA_AUTO_TIMEOUT_S') ?? ''));
        $timeout = self::DEFAULT_TIMEOUT_S;
        if ($timeoutRaw !== '' && ctype_digit($timeoutRaw)) {
            $timeout = max(30, (int) $timeoutRaw);
        }

        $attemptsRaw = trim((string) ($config->get('HH_CAPTCHA_AUTO_ATTEMPTS') ?? ''));
        $attempts = self::DEFAULT_ATTEMPTS;
        if ($attemptsRaw !== '' && ctype_digit($attemptsRaw)) {
            $attempts = max(1, min(10, (int) $attemptsRaw));
        }

        return new self(
            $apiKey,
            $taskApiBase,
            $label,
            $shotDir,
            $timeout,
            $attempts,
            $fallback,
            $dm,
        );
    }

    public function enabled(): bool
    {
        return true;
    }

    public function tryResolve(PageInterface $page, string $context = ''): bool
    {
        if (!CaptchaForm::pageHasCaptcha($page)) {
            return true;
        }

        try {
            if ($this->resolveAuto($page, $context)) {
                return true;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'CAPTCHA_AUTO fail: ' . $e->getMessage() . "\n");
            $this->logMeta('auto_fail', $context, ['error' => $e->getMessage()]);
        }

        if ($this->fallback !== null && $this->fallback->enabled()) {
            fwrite(STDERR, "CAPTCHA_AUTO → fallback TG\n");
            $this->logMeta('fallback_tg', $context, []);

            return $this->fallback->tryResolve($page, $context);
        }

        return false;
    }

    private function resolveAuto(PageInterface $page, string $context): bool
    {
        if (!is_dir($this->shotDir) && !mkdir($this->shotDir, 0775, true) && !is_dir($this->shotDir)) {
            throw new \RuntimeException('Не создать каталог скринов: ' . $this->shotDir);
        }

        $vacancyId = '';
        if (preg_match('/\b(\d{6,})\b/', $context, $m) === 1) {
            $vacancyId = $m[1];
        }

        $run = function () use ($page, $context, $vacancyId): bool {
            for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
                if (!CaptchaForm::pageHasCaptcha($page)) {
                    return true;
                }

                $shot = rtrim($this->shotDir, '/') . '/hh_captcha_auto_' . time() . '_' . $attempt . '.png';
                if (!CaptchaForm::cropImage($page, $shot)) {
                    CaptchaForm::refreshImage($page);
                    if (!CaptchaForm::cropImage($page, $shot)) {
                        throw new \RuntimeException('Не нашёл img капчи для crop (attempt ' . $attempt . ')');
                    }
                }

                fwrite(STDERR, "CAPTCHA_AUTO attempt {$attempt}/{$this->maxAttempts}: {$this->providerLabel} ImageToText\n");
                $this->logOut('solve_request', $context, $vacancyId, [
                    'attempt' => $attempt,
                    'path' => basename($shot),
                    'provider' => $this->providerLabel,
                ]);

                $solved = $this->solveImageToText($shot);
                $code = trim($solved['text']);
                if ($code === '') {
                    fwrite(STDERR, "CAPTCHA_AUTO empty code from API\n");
                    CaptchaForm::refreshImage($page);
                    continue;
                }

                fwrite(STDERR, 'CAPTCHA_AUTO code received (' . mb_strlen($code) . " chars), typing\n");
                $this->logIn($code, $context, $vacancyId, [
                    'attempt' => $attempt,
                    'provider' => $this->providerLabel,
                    'cost' => $solved['cost'],
                    'task_id' => $solved['task_id'],
                ]);
                CaptchaForm::typeAndSubmit($page, $code);
                usleep(2_500_000);

                if (!CaptchaForm::pageHasCaptcha($page)) {
                    $balance = $this->fetchBalance();
                    $this->logMeta('auto_ok', $context, [
                        'attempt' => $attempt,
                        'cost' => $solved['cost'],
                        'balance' => $balance,
                        'task_id' => $solved['task_id'],
                        'provider' => $this->providerLabel,
                    ]);
                    try {
                        $this->dm?->sendMessage($this->okMessage($solved['cost'], $balance));
                    } catch (\Throwable) {
                        // ignore
                    }

                    return true;
                }

                fwrite(STDERR, "CAPTCHA_AUTO code rejected by HH, retry\n");
                $this->logMeta('auto_rejected', $context, [
                    'attempt' => $attempt,
                    'cost' => $solved['cost'],
                    'task_id' => $solved['task_id'],
                ]);
                CaptchaForm::refreshImage($page);
                usleep(800_000);
            }

            return false;
        };

        if ($this->dm !== null) {
            return $this->dm->withConversation('captcha', fn () => $run(), $vacancyId);
        }

        return $run();
    }

    /**
     * createTask + getTaskResult — в ответе есть cost (в отличие от legacy normal()).
     *
     * @return array{text: string, cost: ?float, task_id: int|string|null}
     */
    private function solveImageToText(string $imagePath): array
    {
        $bytes = @file_get_contents($imagePath);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Не прочитать crop капчи: ' . $imagePath);
        }

        $create = $this->postJson('/createTask', [
            'clientKey' => $this->apiKey,
            'task' => [
                'type' => 'ImageToTextTask',
                'body' => base64_encode($bytes),
                'phrase' => true,
                'case' => true,
                'numeric' => 0,
                'math' => false,
                'minLength' => 1,
                'maxLength' => 0,
                'languagePool' => 'rn',
            ],
        ]);
        $errorId = (int) ($create['errorId'] ?? -1);
        if ($errorId !== 0) {
            throw new \RuntimeException(
                'createTask: ' . (string) ($create['errorCode'] ?? $create['errorDescription'] ?? json_encode($create, JSON_UNESCAPED_UNICODE)),
            );
        }
        $taskId = $create['taskId'] ?? null;
        if ($taskId === null || $taskId === '') {
            throw new \RuntimeException('createTask: пустой taskId');
        }

        $deadline = time() + $this->timeoutS;
        while (time() < $deadline) {
            sleep(self::POLL_INTERVAL_S);
            $res = $this->postJson('/getTaskResult', [
                'clientKey' => $this->apiKey,
                'taskId' => $taskId,
            ]);
            $err = (int) ($res['errorId'] ?? -1);
            if ($err !== 0) {
                throw new \RuntimeException(
                    'getTaskResult: ' . (string) ($res['errorCode'] ?? $res['errorDescription'] ?? json_encode($res, JSON_UNESCAPED_UNICODE)),
                );
            }
            $status = strtolower(trim((string) ($res['status'] ?? '')));
            if ($status === 'processing') {
                continue;
            }
            if ($status !== 'ready') {
                throw new \RuntimeException('getTaskResult: неожиданный status=' . $status);
            }
            $solution = is_array($res['solution'] ?? null) ? $res['solution'] : [];
            $text = trim((string) ($solution['text'] ?? $solution['request'] ?? ''));
            $costRaw = $res['cost'] ?? null;
            $cost = is_numeric($costRaw) ? round((float) $costRaw, 5) : null;

            return [
                'text' => $text,
                'cost' => $cost,
                'task_id' => is_int($taskId) || is_string($taskId) ? $taskId : (string) $taskId,
            ];
        }

        throw new \RuntimeException('Timeout ' . $this->timeoutS . 's waiting ImageToText taskId=' . $taskId);
    }

    /** @return array<string, mixed> */
    private function postJson(string $path, array $payload): array
    {
        $url = rtrim($this->taskApiBase, '/') . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            throw new \RuntimeException('JSON encode fail for ' . $path);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || !is_string($raw)) {
            throw new \RuntimeException('HTTP ' . $path . ': ' . ($err !== '' ? $err : 'empty response'));
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('HTTP ' . $status . ' ' . $path . ': ' . mb_substr($raw, 0, 300));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON from ' . $path . ': ' . mb_substr($raw, 0, 300));
        }

        return $decoded;
    }

    private function fetchBalance(): ?float
    {
        try {
            $res = $this->postJson('/getBalance', ['clientKey' => $this->apiKey]);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'CAPTCHA_AUTO balance: ' . $e->getMessage() . "\n");

            return null;
        }
        if ((int) ($res['errorId'] ?? -1) !== 0) {
            return null;
        }
        $bal = $res['balance'] ?? null;

        return is_numeric($bal) ? round((float) $bal, 4) : null;
    }

    private function okMessage(?float $cost, ?float $balance): string
    {
        $parts = ['✅ Капча пройдена (' . $this->providerLabel . ').'];
        if ($cost !== null) {
            $parts[] = 'Стоимость: ' . $this->fmtMoney($cost) . ' ₽';
        }
        if ($balance !== null) {
            $parts[] = 'Баланс: ' . $this->fmtMoney($balance) . ' ₽';
        }

        return implode(' ', $parts);
    }

    private function fmtMoney(float $value): string
    {
        return rtrim(rtrim(number_format($value, 5, '.', ''), '0'), '.') ?: '0';
    }

    /** @param array<string, mixed> $meta */
    private function logOut(string $text, string $context, string $vacancyId, array $meta): void
    {
        $this->dm?->journal()?->write('out', 'captcha', $text, $vacancyId, '', $meta);
    }

    /** @param array<string, mixed> $meta */
    private function logIn(string $text, string $context, string $vacancyId, array $meta): void
    {
        $this->dm?->journal()?->write('in', 'captcha', $text, $vacancyId, '', $meta);
    }

    /** @param array<string, mixed> $meta */
    private function logMeta(string $text, string $context, array $meta): void
    {
        $vacancyId = '';
        if (preg_match('/\b(\d{6,})\b/', $context, $m) === 1) {
            $vacancyId = $m[1];
        }
        $this->dm?->journal()?->write('meta', 'captcha', $text, $vacancyId, '', $meta);
    }

    private static function envTruthy(string $raw): bool
    {
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
