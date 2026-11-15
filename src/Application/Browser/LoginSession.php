<?php
declare(strict_types=1);

namespace JobSearch\Application\Browser;

use JobSearch\Domain\Browser\BrowserGateway;
use JobSearch\Domain\Browser\BrowserLoginResult;
use JobSearch\Domain\Applicant\ApplicantPages;
use Playwright\Page\PageInterface;

/**
 * Use case: открыть login hh.ru, дождаться сессии, сохранить storageState.
 *
 * Не дёргает goto в цикле — пока вы на форме входа, страница не перезагружается.
 * Проверка /applicant/resumes — один раз после ухода с login (или редко, с паузой).
 */
final class LoginSession
{
    public function __construct(
        private readonly BrowserGateway $browser,
        private readonly ApplicantPages $pages,
    ) {}

    public function loginAndSaveSession(
        string $storageStatePath,
        int $timeoutSeconds = 300,
        ?string $login = null,
        ?string $password = null,
    ): BrowserLoginResult {
        $dir = dirname($storageStatePath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }

        $context = $this->browser->openChromium(null);
        try {
            $page = $context->newPage();
            $page->goto(ApplicantPages::LOGIN_URL, ['waitUntil' => 'domcontentloaded']);

            if ($login !== null && $login !== '' && $password !== null && $password !== '') {
                $this->tryFillCredentials($page, $login, $password);
            }

            $deadline = time() + max(30, $timeoutSeconds);
            $lastResumeCheckAt = 0;

            while (time() < $deadline) {
                if ($this->isStillOnLogin($page)) {
                    // Даём спокойно ввести пароль / пройти капчу — без reload.
                    usleep(1_500_000);
                    continue;
                }

                // Ушли с login — не чаще чем раз в 10 с проверяем резюме.
                if (time() - $lastResumeCheckAt < 10) {
                    usleep(1_000_000);
                    continue;
                }
                $lastResumeCheckAt = time();

                $page->goto(ApplicantPages::RESUMES_URL, ['waitUntil' => 'domcontentloaded']);
                usleep(800_000);

                if ($this->pages->looksLoggedIn($page)) {
                    $context->saveStorageState($storageStatePath);
                    chmod($storageStatePath, 0600);

                    return new BrowserLoginResult(
                        true,
                        $storageStatePath,
                        $page->url(),
                        'Сессия сохранена. Дальше: php bin/hh.php resumes',
                    );
                }

                // Ещё не вошли (редирект обратно) — снова ждём без спама goto.
                usleep(3_000_000);
            }

            return new BrowserLoginResult(
                false,
                $storageStatePath,
                $page->url(),
                'Таймаут ожидания входа. Залогиньтесь в открытом окне и повторите.',
            );
        } finally {
            $context->close();
        }
    }

    private function isStillOnLogin(PageInterface $page): bool
    {
        $path = (string) (parse_url($page->url(), PHP_URL_PATH) ?? '');

        return (bool) preg_match('#/(account/)?login#i', $path);
    }

    private function tryFillCredentials(PageInterface $page, string $login, string $password): void
    {
        try {
            $user = $page->locator(
                'input[data-qa="login-input-username"], input[name="username"], input[type="email"]',
            )->first();
            $pass = $page->locator(
                'input[data-qa="login-input-password"], input[name="password"], input[type="password"]',
            )->first();
            $user->fill($login);
            $pass->fill($password);
            $page->locator(
                'button[data-qa="account-login-submit"], button[type="submit"]',
            )->first()->click();
        } catch (\Throwable) {
            // Форма могла измениться / капча — пользователь дологинится руками.
        }
    }
}
