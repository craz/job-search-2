<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Applicant;

use JobSearch\Application\CoverLetter\GenerateCoverLetter;
use JobSearch\Domain\Applicant\CoverLetterFillResult;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;
use JobSearch\Domain\DevAi\DevAi;
use JobSearch\Domain\DevAi\DevAiException;
use JobSearch\Infrastructure\Cache\Cache;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\DevAi\CurlDevAiClient;
use JobSearch\Infrastructure\Telegram\TelegramDmClient;
use Playwright\Page\PageInterface;

/**
 * letter_required → DevAI → Core draft → TG ok/edit/skip → fill textarea.
 */
final class CoverLetterFiller
{
    public function __construct(
        private readonly GenerateCoverLetter $generator,
        private readonly CoreApi $core,
        private readonly Cache $cache,
        private readonly ?TelegramCoverLetterSolver $telegram = null,
    ) {}

    public static function fromConfig(
        Config $config,
        string $projectRoot,
        CoreApi $core,
        Cache $cache,
        ?DevAi $devAi = null,
        ?TelegramDmClient $dm = null,
    ): ?self {
        $flag = $config->get('HH_LETTER_TG');
        if ($flag !== null && trim($flag) !== '' && !self::envTruthy($flag)) {
            return null;
        }
        $tg = TelegramCoverLetterSolver::fromConfig($config, $dm);
        if ($tg === null) {
            return null;
        }
        $model = trim((string) ($config->get('DEVAI_MODEL', 'devai/auto') ?? 'devai/auto'));
        $generator = GenerateCoverLetter::fromProjectRoot(
            $projectRoot,
            $devAi ?? CurlDevAiClient::fromConfig($config),
            $model !== '' ? $model : 'devai/auto',
        );

        return new self($generator, $core, $cache, $tg);
    }

    /**
     * Сгенерировать, утвердить в TG и вставить письмо в открытую форму отклика.
     */
    public function fill(
        PageInterface $page,
        string $vacancyExternalId,
        string $resumeId,
    ): CoverLetterFillResult {
        $vacancyExternalId = trim($vacancyExternalId);
        $resumeId = trim($resumeId);
        if ($vacancyExternalId === '' || $resumeId === '') {
            return CoverLetterFillResult::failed('Нет vacancy/resume id для сопроводительного.');
        }

        $resume = $this->resumeFromCache($resumeId);
        if ($resume['text'] === '') {
            return CoverLetterFillResult::failed(
                'HH требует сопроводительное письмо — нет текста резюме в кеше (php bin/hh.php resume-text).',
            );
        }

        $vacancy = $this->vacancyFromPage($page, $vacancyExternalId);
        $vacancy = $this->enrichVacancyFromCore($vacancyExternalId, $vacancy);
        if ($this->isWeakVacancyText($vacancy)) {
            return CoverLetterFillResult::failed(
                'HH требует сопроводительное письмо — нет описания вакансии '
                . '(форма «Отклик» без текста, Core тоже пуст). Не твой факт — системный сбой чтения.',
            );
        }

        if ($this->telegram === null) {
            return CoverLetterFillResult::failed(
                'HH требует сопроводительное письмо — TG отключён (HH_LETTER_TG / TELEGRAM_DM_*).',
            );
        }

        try {
            $generated = $this->generator->generate(
                $resume['text'],
                $vacancy['text'],
                $resume['title'],
                $vacancy['title'],
            );
        } catch (DevAiException $e) {
            return CoverLetterFillResult::failed(
                'HH требует сопроводительное письмо — DevAI: ' . $e->getMessage(),
            );
        }

        // Весь TG-цикл письма под одним flock — gaps+ask не рвутся капчей другого apply.
        return $this->telegram->exclusive(
            function (TelegramCoverLetterSolver $tg) use (
                $generated,
                $resume,
                $vacancy,
                $vacancyExternalId,
                $resumeId,
                $page,
            ): CoverLetterFillResult {
                $gapStatus = (string) ($generated['status'] ?? '');
                if ($gapStatus === 'draft_with_gaps' || $gapStatus === 'needs_input') {
                    $resolved = $this->resolveGapsViaTelegram(
                        $generated,
                        $resume,
                        $vacancy,
                        $vacancyExternalId,
                        $tg,
                    );
                    if ($resolved instanceof CoverLetterFillResult) {
                        return $resolved;
                    }
                    $generated = $resolved;
                }

                $draftLetter = trim((string) ($generated['letter'] ?? ''));
                if ($draftLetter === '') {
                    return CoverLetterFillResult::failed(
                        'HH требует сопроводительное письмо — пустой черновик DevAI.',
                    );
                }
                if (GenerateCoverLetter::looksLikeGapPayload($draftLetter)) {
                    $missing = GenerateCoverLetter::parseNeedsInput($draftLetter)
                        ?? trim((string) ($generated['missing'] ?? 'не хватает подтверждённых фактов'));

                    return CoverLetterFillResult::needsInput($missing);
                }
                if (($generated['status'] ?? '') !== 'ok') {
                    return CoverLetterFillResult::failed(
                        'HH требует сопроводительное письмо — неожиданный статус генерации: '
                        . (string) ($generated['status'] ?? ''),
                    );
                }

                $coverId = null;
                try {
                    $created = $this->persistDraft(
                        (string) $vacancyExternalId,
                        (string) $resumeId,
                        $generated,
                        $resume['text'],
                        $vacancy['text'],
                        $draftLetter,
                    );
                    $coverId = isset($created['id']) ? (string) $created['id'] : null;
                } catch (CoreException $e) {
                    return CoverLetterFillResult::failed(
                        'HH требует сопроводительное письмо — Core: ' . $e->getMessage(),
                    );
                }

                $asked = $tg->ask(
                    $draftLetter,
                    $vacancyExternalId,
                    $vacancy['title'],
                    $vacancy['text'],
                );
                if (!empty($asked['timeout'])) {
                    $this->patchStatus($coverId, 'failed');

                    return CoverLetterFillResult::tgTimeout($coverId);
                }
                if (!empty($asked['skip'])) {
                    $this->patchStatus($coverId, 'skipped');

                    return CoverLetterFillResult::skipped(
                        'Сопроводительное пропущено (skip в TG).',
                        $coverId,
                    );
                }
                if (!($asked['ok'] ?? false)) {
                    $this->patchStatus($coverId, 'failed');

                    return CoverLetterFillResult::failed(
                        'HH требует сопроводительное письмо — '
                        . (string) ($asked['error'] ?? 'TG-ответ не принят'),
                        $coverId,
                    );
                }

                $final = trim((string) ($asked['letter'] ?? ''));
                if ($final === '') {
                    $this->patchStatus($coverId, 'failed');

                    return CoverLetterFillResult::failed(
                        'HH требует сопроводительное письмо — пустой финальный текст.',
                        $coverId,
                    );
                }

                try {
                    if ($coverId !== null && $coverId !== '') {
                        $this->core->updateCoverLetter($coverId, [
                            'status' => 'accepted',
                            'letter' => $final,
                        ]);
                    }
                } catch (CoreException) {
                    // Письмо уже утверждено владельцем — всё равно пробуем отклик.
                }

                if (!$this->fillCoverLetter($page, $final)) {
                    return CoverLetterFillResult::failed(
                        'HH требует сопроводительное письмо — не нашёл поле letter на форме.',
                        $coverId,
                    );
                }

                return CoverLetterFillResult::filled($final, $coverId);
            },
            $vacancyExternalId,
            $resumeId,
        );
    }

    public function fillCoverLetter(PageInterface $page, string $text): bool
    {
        $ok = $page->evaluate(
            <<<'JS'
({ text }) => {
  const root = document.querySelector('[data-qa="modal-overlay"]')
    || document.querySelector('[data-qa="vacancy-response-popup"]')
    || document.body;
  const selectors = [
    '[data-qa="vacancy-response-popup-form-letter-input"]',
    'textarea[name="letter"]',
    'textarea[data-qa*="letter"]',
  ];
  const setValue = (el, value) => {
    el.focus();
    const proto = el.tagName === 'TEXTAREA'
      ? window.HTMLTextAreaElement.prototype
      : window.HTMLInputElement.prototype;
    const desc = Object.getOwnPropertyDescriptor(proto, 'value');
    if (desc && desc.set) {
      desc.set.call(el, value);
    } else {
      el.value = value;
    }
    el.dispatchEvent(new InputEvent('input', { bubbles: true, data: value, inputType: 'insertText' }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  };
  for (const sel of selectors) {
    const el = root.querySelector(sel) || document.querySelector(sel);
    if (!el) continue;
    setValue(el, text);
    return String(el.value || '').trim().length > 0;
  }
  return false;
}
JS,
            ['text' => $text],
        );

        return $ok === true;
    }

    /**
     * DRAFT_WITH_GAPS / NEEDS_INPUT → TG → повторная генерация.
     *
     * @param array<string, mixed> $generated
     * @param array{title: string, text: string} $resume
     * @param array{title: string, text: string} $vacancy
     * @return array<string, mixed>|CoverLetterFillResult
     */
    private function resolveGapsViaTelegram(
        array $generated,
        array $resume,
        array $vacancy,
        string $vacancyExternalId,
        ?TelegramCoverLetterSolver $tg = null,
    ): array|CoverLetterFillResult {
        $tg ??= $this->telegram;
        if ($tg === null) {
            return CoverLetterFillResult::failed(
                'HH требует сопроводительное письмо — TG отключён (HH_LETTER_TG / TELEGRAM_DM_*).',
            );
        }

        $status = (string) ($generated['status'] ?? '');
        $missing = trim((string) ($generated['missing'] ?? ''));
        if (self::isVacancyMetaMissing($missing)) {
            return CoverLetterFillResult::failed(
                'HH требует сопроводительное письмо — DevAI не получил текст вакансии '
                . '(просил «' . mb_substr($missing, 0, 120) . '»). Это не твой факт; '
                . 'повтори отклик — описание подтянется из Core.',
            );
        }
        /** @var list<array{question?: string, status?: string, draft?: string, missing?: string}> $questions */
        $questions = [];
        if (is_array($generated['draft_questions'] ?? null)) {
            foreach ($generated['draft_questions'] as $q) {
                if (is_array($q)) {
                    $questions[] = $q;
                }
            }
        }

        if ($status === 'draft_with_gaps') {
            $factAsk = $tg->askGaps(
                $questions,
                $missing,
                $vacancyExternalId,
                $vacancy['title'],
            );
            $failKind = 'draft_with_gaps';
        } else {
            $factAsk = $tg->askMissingFact($missing, $vacancyExternalId, $vacancy['title']);
            $failKind = 'needs_input';
        }

        if (!empty($factAsk['timeout'])) {
            return CoverLetterFillResult::tgTimeout();
        }
        if (!empty($factAsk['skip'])) {
            return CoverLetterFillResult::skipped(
                'Сопроводительное пропущено (skip в TG при ' . strtoupper($failKind) . ').',
            );
        }
        if (!($factAsk['ok'] ?? false) || trim((string) ($factAsk['fact'] ?? '')) === '') {
            return $failKind === 'draft_with_gaps'
                ? CoverLetterFillResult::draftWithGaps($missing !== '' ? $missing : 'не указаны недостающие факты')
                : CoverLetterFillResult::needsInput($missing !== '' ? $missing : 'не указан недостающий факт');
        }

        $extraContext = $status === 'draft_with_gaps'
            ? "Ответы владельца на DRAFT_WITH_GAPS (дополнить только пробелы, не выдумывать остальное):\n"
                . trim((string) $factAsk['fact'])
            : "Ответ владельца на NEEDS_INPUT («{$missing}»):\n" . trim((string) $factAsk['fact']);

        try {
            $retry = $this->generator->generate(
                $resume['text'],
                $vacancy['text'],
                $resume['title'],
                $vacancy['title'],
                $extraContext,
            );
        } catch (DevAiException $e) {
            return CoverLetterFillResult::failed(
                'HH требует сопроводительное письмо — DevAI (после gaps): ' . $e->getMessage(),
            );
        }

        $retryStatus = (string) ($retry['status'] ?? '');
        if ($retryStatus === 'draft_with_gaps') {
            return CoverLetterFillResult::draftWithGaps(
                trim((string) ($retry['missing'] ?? $missing)),
            );
        }
        if ($retryStatus === 'needs_input') {
            return CoverLetterFillResult::needsInput(
                trim((string) ($retry['missing'] ?? $missing)),
            );
        }

        return $retry;
    }

    /** @return array{title: string, text: string} */
    private function resumeFromCache(string $resumeId): array
    {
        $entry = $this->cache->get('resume_texts');
        $payload = is_array($entry?->payload) ? $entry->payload : [];
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = trim((string) ($it['external_id'] ?? $it['id'] ?? ''));
            if ($id !== $resumeId) {
                continue;
            }

            return [
                'title' => trim((string) ($it['title'] ?? '')),
                'text' => trim((string) ($it['text'] ?? $it['body'] ?? '')),
            ];
        }

        return ['title' => '', 'text' => ''];
    }

    /** @return array{title: string, text: string} */
    private function vacancyFromPage(PageInterface $page, string $vacancyExternalId): array
    {
        try {
            $payload = $page->evaluate(<<<'JS'
() => {
  const normalize = (raw) => (raw || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
  const titleEl = document.querySelector('[data-qa="vacancy-title"], h1[data-qa="bloko-header-1"], h1');
  const descEl = document.querySelector('[data-qa="vacancy-description"], .vacancy-description, [data-qa="vacancy-description-content"]');
  const companyEl = document.querySelector('[data-qa="vacancy-company-name"], [data-qa="bloko-header-2"] a');
  let title = normalize(titleEl && titleEl.textContent);
  // Форма отклика: h1 = «Отклик на вакансию» — это не название.
  if (/^отклик на вакансию$/i.test(title)) {
    title = '';
  }
  const company = normalize(companyEl && companyEl.textContent);
  const description = normalize(descEl && (descEl.innerText || descEl.textContent));
  return { title, company, description };
}
JS);
        } catch (\Throwable) {
            $payload = null;
        }

        $title = '';
        $text = '';
        if (is_array($payload)) {
            $title = trim((string) ($payload['title'] ?? ''));
            $company = trim((string) ($payload['company'] ?? ''));
            $description = trim((string) ($payload['description'] ?? ''));
            $parts = [];
            if ($title !== '') {
                $parts[] = $title;
            }
            if ($company !== '') {
                $parts[] = 'Компания: ' . $company;
            }
            if ($description !== '') {
                $parts[] = $description;
            }
            $text = implode("\n\n", $parts);
        }

        if ($text === '') {
            $text = 'Вакансия HH https://hh.ru/vacancy/' . $vacancyExternalId;
            if ($title === '') {
                $title = 'Вакансия ' . $vacancyExternalId;
            }
        }

        return ['title' => $title, 'text' => $text];
    }

    /**
     * Форма отклика часто без description — берём title/текст из Core.
     *
     * @param array{title: string, text: string} $scraped
     * @return array{title: string, text: string}
     */
    private function enrichVacancyFromCore(string $vacancyExternalId, array $scraped): array
    {
        if (!$this->isWeakVacancyText($scraped)) {
            return $scraped;
        }
        try {
            $map = $this->core->lookupVacancyIdsByExternalIds([$vacancyExternalId]);
            $coreId = trim((string) ($map[$vacancyExternalId] ?? ''));
            if ($coreId === '') {
                return $scraped;
            }
            $row = $this->core->getVacancy($coreId);
        } catch (CoreException) {
            return $scraped;
        }

        $title = trim((string) ($row['title'] ?? ''));
        $companyRow = is_array($row['company'] ?? null) ? $row['company'] : [];
        $company = trim((string) ($companyRow['name'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        if ($description === '') {
            return $scraped;
        }
        $parts = [];
        if ($title !== '') {
            $parts[] = $title;
        } elseif (trim($scraped['title']) !== '') {
            $title = trim($scraped['title']);
            $parts[] = $title;
        }
        if ($company !== '') {
            $parts[] = 'Компания: ' . $company;
        }
        $parts[] = $description;

        return [
            'title' => $title !== '' ? $title : trim($scraped['title']),
            'text' => implode("\n\n", $parts),
        ];
    }

    /** @param array{title?: string, text?: string} $vacancy */
    private function isWeakVacancyText(array $vacancy): bool
    {
        $title = trim((string) ($vacancy['title'] ?? ''));
        $text = trim((string) ($vacancy['text'] ?? ''));
        if ($text === '' || preg_match('/^Вакансия HH https:\/\/hh\.ru\/vacancy\//u', $text) === 1) {
            return true;
        }
        if (preg_match('/^отклик на вакансию$/ui', $title) === 1) {
            return true;
        }
        // Один заголовок без нормального description.
        return mb_strlen($text) < 400;
    }

    /** DevAI просит текст вакансии вместо факта кандидата — в TG не спрашиваем. */
    public static function isVacancyMetaMissing(string $missing): bool
    {
        return preg_match(
            '/полный\s+текст\s+вакансии|текст\s+вакансии|описани[ея]\s+вакансии|'
            . 'задач\w*\s+и\s+требования|требования\s+работодателя\s+из\s+вакансии|'
            . 'фрагмент\s+вакансии|url\s*вакансии|ссылк\w+\s+на\s+вакансию/ui',
            $missing,
        ) === 1;
    }

    /**
     * POST draft в Core. Idempotency-Key = fingerprint всего body:
     * повтор с тем же письмом → replay; новое письмо после retry → новая запись.
     * Старый ключ cover:vac:res:sha1(prompt) давал 409 при другом letter — лечим PATCH/новым ключом.
     *
     * @param array<string, mixed> $generated
     * @return array<string, mixed>
     */
    private function persistDraft(
        string $vacancyExternalId,
        string $resumeId,
        array $generated,
        string $resumeText,
        string $vacancyText,
        string $draftLetter,
    ): array {
        $payload = [
            'vacancy_external_id' => $vacancyExternalId,
            'resume_external_id' => $resumeId,
            'prompt' => (string) ($generated['prompt'] ?? ''),
            'prompt_version' => (string) ($generated['prompt_version'] ?? ''),
            'model' => (string) ($generated['model'] ?? ''),
            'resume_excerpt' => mb_substr($resumeText, 0, 12000),
            'vacancy_excerpt' => mb_substr($vacancyText, 0, 12000),
            'letter' => $draftLetter,
            'status' => 'draft',
        ];
        $fingerprint = hash(
            'sha256',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $key = 'cover:' . $vacancyExternalId . ':' . $resumeId . ':' . $fingerprint;

        try {
            return $this->core->createCoverLetter($payload, $key);
        } catch (CoreException $e) {
            if (!str_contains($e->getMessage(), 'HTTP 409')) {
                throw $e;
            }
        }

        $listed = $this->core->listCoverLetters($vacancyExternalId, $resumeId, null, 10);
        $items = is_array($listed['items'] ?? null) ? $listed['items'] : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            return $this->core->updateCoverLetter($id, [
                'letter' => $draftLetter,
                'status' => 'draft',
            ]);
        }

        return $this->core->createCoverLetter(
            $payload,
            $key . ':' . bin2hex(random_bytes(4)),
        );
    }

    private function patchStatus(?string $coverId, string $status): void
    {
        if ($coverId === null || $coverId === '') {
            return;
        }
        try {
            $this->core->updateCoverLetter($coverId, ['status' => $status]);
        } catch (CoreException) {
        }
    }

    private function markFailed(?string $coverId, string $reason): void
    {
        unset($reason);
        $this->patchStatus($coverId, 'failed');
    }

    private static function envTruthy(string $raw): bool
    {
        $v = strtolower(trim($raw));

        return in_array($v, ['1', 'true', 'yes', 'on', 'y'], true);
    }
}
