<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Applicant;

use Playwright\Page\PageInterface;

/**
 * Общие DOM-операции HH-капчи: детект, crop img, ввод кода, submit.
 */
final class CaptchaForm
{
    /** @var list<string> */
    private const INPUT_SELECTORS = [
        'input[placeholder*="картинки" i]',
        'input[placeholder*="Текст с картинки" i]',
        'input[aria-label*="картинки" i]',
        'input[data-qa="account-login-captcha"]',
        'input[data-qa*="captcha" i]',
        'input[name="captcha-text"]',
        'input[name="captcha"]',
        '[data-qa="captcha"] input[type="text"]',
        '[class*="captcha" i] input[type="text"]',
        'dialog input[type="text"]',
        '[role="dialog"] input[type="text"]',
        '.bloko-modal input[type="text"]',
    ];

    /** @var list<string> */
    private const SUBMIT_SELECTORS = [
        // account/captcha: data-qa*=captcha ловит renew/language/submit → strict mode.
        'button[data-qa="account-captcha-submit"]',
        'button[type="submit"][data-qa="account-captcha-submit"]',
        '[role="dialog"] button:has-text("Отправить")',
        'dialog button:has-text("Отправить")',
        '.bloko-modal button:has-text("Отправить")',
        '[class*="captcha" i] button:has-text("Отправить")',
        'button[data-qa="account-login-submit"]',
        '[role="dialog"] button[type="submit"]',
    ];

    /** @var list<string> */
    private const IMAGE_SELECTORS = [
        '[role="dialog"] img[src*="captcha" i]',
        'dialog img[src*="captcha" i]',
        '.bloko-modal img[src*="captcha" i]',
        '[data-qa*="captcha" i] img',
        '[class*="captcha" i] img',
        '[role="dialog"] img',
        'dialog img',
        '.bloko-modal img',
    ];

    /** @var list<string> */
    private const REFRESH_SELECTORS = [
        'button[data-qa="captcha-renew-text"]',
        '[role="dialog"] button[aria-label*="Другой" i]',
        '[role="dialog"] button:has-text("Другой текст")',
        'dialog button:has-text("Другой текст")',
        '.bloko-modal button:has-text("Другой текст")',
        '[role="dialog"] button[title*="обнов" i]',
        '[class*="captcha" i] button[aria-label*="refresh" i]',
    ];

    public static function pageHasCaptcha(PageInterface $page): bool
    {
        $hit = $page->evaluate(<<<'JS'
() => {
  const u = location.href || '';
  if (/\/account\/captcha/i.test(u)) return true;
  const body = (document.body && document.body.innerText || '').slice(0, 4000);
  const input = document.querySelector(
    'input[placeholder*="картинки" i], input[name*="captcha" i], input[data-qa*="captcha" i], [role="dialog"] input[type="text"], .bloko-modal input[type="text"]'
  );
  if (input && input.offsetParent !== null) return true;
  if (/пройдите\s+капчу/i.test(body)) return true;
  if (document.querySelector('[data-qa="account-captcha"], [data-qa="captcha"], .bloko-captcha, iframe[src*="captcha"]')) {
    return true;
  }
  return false;
}
JS);

        return $hit === true;
    }

    /**
     * Скрин только img капчи. False = не нашли элемент.
     */
    public static function cropImage(PageInterface $page, string $path): bool
    {
        $sel = self::firstVisibleSelector($page, self::IMAGE_SELECTORS);
        if ($sel === null) {
            return false;
        }
        try {
            $page->locator($sel)->first()->screenshot($path);
        } catch (\Throwable) {
            return false;
        }

        return is_readable($path) && filesize($path) > 100;
    }

    /** Ввести код и нажать Отправить / Enter. */
    public static function typeAndSubmit(PageInterface $page, string $code): void
    {
        $code = trim($code);
        if ($code === '') {
            throw new \InvalidArgumentException('Пустой код капчи');
        }
        $fieldSel = self::firstVisibleSelector($page, self::INPUT_SELECTORS);
        if ($fieldSel === null) {
            throw new \RuntimeException('Поле ввода капчи на странице не найдено');
        }
        $loc = $page->locator($fieldSel);
        try {
            $loc->click();
            usleep(random_int(200_000, 500_000));
            $loc->fill('');
        } catch (\Throwable) {
            // best-effort clear
        }
        $loc->pressSequentially($code, ['delay' => 180]);
        usleep(random_int(200_000, 600_000));

        $submitSel = self::firstVisibleSelector($page, self::SUBMIT_SELECTORS);
        if ($submitSel !== null) {
            // Всегда first(): даже точный qa иногда дублируется в DOM.
            $page->locator($submitSel)->first()->click();
        } else {
            try {
                $loc->press('Enter');
            } catch (\Throwable) {
                $page->keyboard()->press('Enter');
            }
        }
    }

    /** Запросить другую картинку (если кнопка есть). */
    public static function refreshImage(PageInterface $page): void
    {
        $sel = self::firstVisibleSelector($page, self::REFRESH_SELECTORS);
        if ($sel === null) {
            return;
        }
        try {
            $page->locator($sel)->click();
            usleep(1_200_000);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * @param list<string> $selectors
     */
    public static function firstVisibleSelector(PageInterface $page, array $selectors): ?string
    {
        foreach ($selectors as $sel) {
            try {
                $loc = $page->locator($sel);
                if ($loc->count() > 0 && $loc->first()->isVisible()) {
                    return $sel;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
