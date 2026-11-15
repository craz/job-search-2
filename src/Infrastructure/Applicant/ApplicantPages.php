<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Applicant;


use JobSearch\Domain\Applicant\ApplicantPages as ApplicantPagesPort;
use JobSearch\Domain\Applicant\ApplyResult;
use JobSearch\Domain\Applicant\CaptchaSolver;
use JobSearch\Domain\Browser\BrowserCity;
use JobSearch\Domain\Chat\ChatSummary;
use JobSearch\Domain\Resume\ResumeList;
use JobSearch\Domain\Resume\ResumeListKind;
use JobSearch\Domain\Resume\ResumeSummary;
use JobSearch\Domain\Resume\ResumeViewEvent;
use JobSearch\Domain\Vacancy\HhBotWall;
use JobSearch\Domain\Vacancy\HhVacancyClosed;
use JobSearch\Domain\Vacancy\VacancyCard;
use JobSearch\Domain\Vacancy\VacancyDetail;
use JobSearch\Domain\Vacancy\VacancyPublishedAt;
use Playwright\Page\PageInterface;

/**
 * Playwright-реализация порта ApplicantPages.
 */
final class ApplicantPages implements ApplicantPagesPort
{
    public function __construct(
        private readonly ?CaptchaSolver $captcha = null,
        private readonly ?ScreeningFormFiller $screening = null,
        private readonly ?CoverLetterFiller $coverLetter = null,
    ) {}

    public function suitableSerpUrl(string $resumeId, int $page = 0): string
    {
        // Без order_by HH даёт relevance — тогда «стоп на первой старой» режет свежие ниже.
        // items_on_page=50: меньше листов до maxIngest / окна возраста.
        $url = self::VACANCY_SERP_BASE
            . '?resume=' . rawurlencode(trim($resumeId))
            . '&order_by=publication_time'
            . '&items_on_page=50';
        $page = max(0, $page);
        if ($page > 0) {
            $url .= '&page=' . $page;
        }

        return $url;
    }

    /**
     * До $limit новых карточек suitable: уже известные id пропускает, листает page=0….
     *
     * @param list<string> $excludeExternalIds
     *
     * @return list<VacancyCard>
     */
    public function readSuitableSerp(
        PageInterface $page,
        string $resumeId,
        int $limit = 10,
        array $excludeExternalIds = [],
        int $maxPages = 12,
    ): array {
        $resumeId = trim($resumeId);
        if ($resumeId === '') {
            throw new \InvalidArgumentException('Resume id is empty');
        }
        $limit = max(1, min(50, $limit));
        $maxPages = max(1, min(30, $maxPages));

        /** @var array<string, true> $exclude */
        $exclude = [];
        foreach ($excludeExternalIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $exclude[$id] = true;
            }
        }

        $out = [];
        for ($serpPage = 0; $serpPage < $maxPages && count($out) < $limit; $serpPage++) {
            $pageCards = $this->readSuitableSerpPage($page, $resumeId, $serpPage);
            if ($pageCards === []) {
                break;
            }
            foreach ($pageCards as $card) {
                $id = trim($card->externalId);
                if ($id === '' || isset($exclude[$id])) {
                    continue;
                }
                $exclude[$id] = true;
                $out[] = $card;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Все карточки одной страницы suitable-SERP (без фильтра «уже скачано»).
     *
     * @return list<VacancyCard>
     */
    public function readSuitableSerpPage(PageInterface $page, string $resumeId, int $serpPage): array
    {
        $page->goto($this->suitableSerpUrl($resumeId, $serpPage), ['waitUntil' => 'domcontentloaded']);
        usleep($serpPage === 0 ? 1_800_000 : 1_200_000);

        $payload = $page->evaluate(
            <<<'JS'
() => {
  const bodyText = ((document.body && document.body.innerText) || '').slice(0, 2000);
  const title = document.title || '';
  if (/ddos[\s_-]*guard/i.test(title) || /checking your browser/i.test(bodyText)) {
    return { kind: 'captcha_or_action_required', items: [] };
  }
  if (document.querySelector('[data-qa="account-login-form"], [data-qa="applicant-login-card"], [data-qa="login"]')) {
    return { kind: 'login_required', items: [] };
  }
  const cards = Array.from(document.querySelectorAll('[data-qa="vacancy-serp__vacancy"]'));
  const items = [];
  const seen = new Set();
  for (const card of cards) {
    const link = card.querySelector('a[data-qa="serp-item__title"]')
      || card.querySelector('a[href*="/vacancy/"]');
    const href = link ? (link.getAttribute('href') || '') : '';
    const m = href.match(/\/vacancy\/(\d+)/);
    if (!m || seen.has(m[1])) continue;
    seen.add(m[1]);
    const titleText = (link.textContent || '').trim().replace(/\s+/g, ' ');
    if (!titleText) continue;
    const companyNode = card.querySelector('[data-qa="vacancy-serp__vacancy-employer"]')
      || card.querySelector('a[data-qa="vacancy-serp__vacancy-employer"]')
      || card.querySelector('[class*="company"] a');
    const companyName = companyNode
      ? (companyNode.textContent || '').trim().replace(/\s+/g, ' ')
      : 'Неизвестный работодатель';
    const companyHref = companyNode ? (companyNode.getAttribute('href') || '') : '';
    const companyMatch = companyHref.match(/\/employer\/(\d+)/);
    const companyId = companyMatch ? companyMatch[1] : ('name:' + companyName.slice(0, 80));
    const salaryNode = card.querySelector('[data-qa="vacancy-serp__vacancy-compensation"]');
    const salary = salaryNode ? (salaryNode.textContent || '').trim().replace(/\s+/g, ' ') : null;
    const areaNode = card.querySelector('[data-qa="vacancy-serp__vacancy-address"]')
      || card.querySelector('[data-qa="vacancy-serp__meta-address"]');
    const area = areaNode ? (areaNode.textContent || '').trim().replace(/\s+/g, ' ') : null;
    const absUrl = href.startsWith('http') ? href.split('?')[0] : ('https://hh.ru' + href.split('?')[0]);
    items.push({
      external_id: m[1],
      title: titleText,
      url: absUrl,
      company_name: companyName || 'Неизвестный работодатель',
      company_external_id: companyId,
      salary_text: salary || null,
      area_text: area || null,
    });
  }
  return { kind: 'ok', items };
}
JS
        );

        if (!is_array($payload)) {
            throw new \RuntimeException('SERP returned invalid payload');
        }
        $kind = isset($payload['kind']) && is_string($payload['kind']) ? $payload['kind'] : 'invalid';
        if ($kind === 'login_required') {
            throw new \RuntimeException('login_required');
        }
        if ($kind === 'captcha_or_action_required') {
            throw new \RuntimeException('captcha_or_action_required');
        }

        $out = [];
        $rawItems = $payload['items'] ?? [];
        if (!is_array($rawItems)) {
            return [];
        }
        foreach ($rawItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['external_id']) ? (string) $row['external_id'] : '';
            $title = isset($row['title']) ? (string) $row['title'] : '';
            $url = isset($row['url']) ? (string) $row['url'] : '';
            $company = isset($row['company_name']) ? (string) $row['company_name'] : '';
            $companyId = isset($row['company_external_id']) ? (string) $row['company_external_id'] : '';
            if ($id === '' || $title === '' || $url === '' || $company === '' || $companyId === '') {
                continue;
            }
            $out[] = new VacancyCard(
                externalId: $id,
                title: $title,
                url: $url,
                companyName: $company,
                companyExternalId: $companyId,
                salaryText: isset($row['salary_text']) && is_string($row['salary_text']) ? $row['salary_text'] : null,
                areaText: isset($row['area_text']) && is_string($row['area_text']) ? $row['area_text'] : null,
            );
        }

        return $out;
    }

    /** Полное описание вакансии со страницы /vacancy/{id}. */
    public function readVacancyDetail(PageInterface $page, VacancyCard $card): VacancyDetail
    {
        $page->goto($card->url, ['waitUntil' => 'domcontentloaded']);
        usleep(900_000);

        $payload = $this->evaluateVacancyDetailPayload($page);
        // Не зовём trySolveCaptcha здесь: на батче vacancies это → десятки TG «ответь кодом» по 10 мин.
        // Bot-wall → ошибка → bot_wall_rehydrate, без DM. TG-капча только в apply.
        if ($this->detailIsBotWall($payload)) {
            throw new \RuntimeException('captcha_or_action_required');
        }
        // Закрыта/скрыта: HH рисует login-форму, но сессия жива — не login_required.
        if (($payload['unavailable'] ?? false) === true) {
            throw new \RuntimeException('vacancy_unavailable');
        }

        return $this->vacancyDetailFromPayload($card, $payload);
    }

    /** @return array<string, mixed> */
    private function evaluateVacancyDetailPayload(PageInterface $page): array
    {
        $payload = $page->evaluate(<<<'JS'
() => {
  const normalize = (raw) => (raw || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
  const uniqSkills = (items) => {
    const out = [];
    const seen = new Set();
    for (const item of items) {
      const text = normalize(item);
      if (!text || seen.has(text)) continue;
      seen.add(text);
      out.push(text);
    }
    return out;
  };
  const skillsFromLux = () => {
    const tpl = document.getElementById('HH-Lux-InitialState')
      || document.querySelector('template#HH-Lux-InitialState')
      || document.querySelector('[id="HH-Lux-InitialState"]');
    if (!tpl) return [];
    const raw = (tpl.textContent || tpl.innerHTML || '').trim();
    if (!raw) return [];
    try {
      const state = JSON.parse(raw);
      const view = state && state.vacancyView;
      const rawSkills = view && (view.keySkills || view.key_skills);
      if (!rawSkills) return [];
      if (Array.isArray(rawSkills)) {
        return uniqSkills(rawSkills.map((s) => {
          if (typeof s === 'string') return s;
          if (s && typeof s === 'object') return s.name || s.title || s.text || '';
          return '';
        }));
      }
      if (typeof rawSkills === 'object') {
        const nested = rawSkills.keySkill || rawSkills.keySkills || rawSkills.items || [];
        return Array.isArray(nested) ? uniqSkills(nested.map((s) => (typeof s === 'string' ? s : (s && s.name) || ''))) : [];
      }
    } catch (e) {
      return [];
    }
    return [];
  };
  const skillsFromDom = () => {
    const nodes = [
      ...document.querySelectorAll('[data-qa="skills-element"]'),
      ...document.querySelectorAll('[data-qa="bloko-tag__text"]'),
      ...document.querySelectorAll('.bloko-tag_inline .bloko-tag__section_text'),
    ];
    return uniqSkills(nodes.map((n) => n.textContent || ''));
  };

  const desc = document.querySelector('[data-qa="vacancy-description"]')
    || document.querySelector('.vacancy-description')
    || document.querySelector('[data-qa="vacancy-page"]');
  let text = desc ? (desc.innerText || '').trim() : '';
  text = text.replace(/\n{3,}/g, '\n\n');
  if (text.length > 50000) {
    text = text.slice(0, 49999) + '…';
  }
  const titleNode = document.querySelector('[data-qa="vacancy-title"]')
    || document.querySelector('h1');
  const title = titleNode ? (titleNode.textContent || '').trim().replace(/\s+/g, ' ') : '';
  const employer = document.querySelector('[data-qa="vacancy-company-name"]')
    || document.querySelector('[data-qa="bloko-header-2"] a');
  const companyName = employer ? (employer.textContent || '').trim().replace(/\s+/g, ' ') : '';
  const companyHref = employer ? (employer.getAttribute('href') || '') : '';
  const companyMatch = companyHref.match(/\/employer\/(\d+)/);
  const salaryNode = document.querySelector('[data-qa="vacancy-salary"]')
    || document.querySelector('[data-qa="vacancy-salary-compensation"]');
  const expNode = document.querySelector('[data-qa="vacancy-experience"]');
  const empNode = document.querySelector('[data-qa="vacancy-employment"]')
    || document.querySelector('[data-qa="common-employment-text"]');
  const scheduleNode = document.querySelector('[data-qa="vacancy-work-schedule"]')
    || document.querySelector('[data-qa="work-schedule"]');
  let keySkills = skillsFromLux();
  if (keySkills.length === 0) {
    keySkills = skillsFromDom();
  }
  const bodySlice = ((document.body && document.body.innerText) || '').slice(0, 12000);
  const pubMatch = bodySlice.match(/Вакансия опубликована\s+(\d{1,2}\s+[А-Яа-яЁё]+\s+\d{4})/i);
  // Закрыта/архив: «В архиве с 13 ноября…» часто без слова «вакансия»; иногда влипает в h1.
  const unavailable = /вам недоступна эта вакансия|вакансия\s+(не найдена|снята с публикации|в архиве)|в архиве с\s+\d{1,2}/i
    .test(bodySlice + '\n' + title);
  return {
    title,
    company_name: companyName,
    company_external_id: companyMatch ? companyMatch[1] : null,
    description: text || null,
    body_text: bodySlice,
    key_skills: keySkills,
    salary_text: salaryNode ? (salaryNode.textContent || '').trim().replace(/\s+/g, ' ') : null,
    experience_text: expNode ? (expNode.textContent || '').trim().replace(/\s+/g, ' ') : null,
    employment_text: empNode ? (empNode.textContent || '').trim().replace(/\s+/g, ' ') : null,
    schedule_text: scheduleNode ? (scheduleNode.textContent || '').trim().replace(/\s+/g, ' ') : null,
    published_text: pubMatch ? pubMatch[1] : null,
    unavailable,
    // [data-qa="login"] слишком широкий (ссылки в шапке) — как в inspectVacancyApplyState.
    looksLogin: !unavailable && !!document.querySelector(
      '[data-qa="account-login-form"], [data-qa="applicant-login-card"]'
    ),
  };
}
JS);

        return is_array($payload) ? $payload : [];
    }

    /** @param array<string, mixed> $payload */
    private function detailIsBotWall(array $payload): bool
    {
        $title = isset($payload['title']) && is_string($payload['title']) ? $payload['title'] : '';
        $description = isset($payload['description']) && is_string($payload['description'])
            ? $payload['description']
            : '';
        $body = isset($payload['body_text']) && is_string($payload['body_text'])
            ? $payload['body_text']
            : $description;

        return HhBotWall::looksLike($title, $body);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function vacancyDetailFromPayload(VacancyCard $card, array $payload): VacancyDetail
    {
        if ($payload === []) {
            throw new \RuntimeException('Vacancy detail invalid payload for ' . $card->externalId);
        }
        if (($payload['looksLogin'] ?? false) === true) {
            throw new \RuntimeException('login_required');
        }

        $title = isset($payload['title']) && is_string($payload['title']) && $payload['title'] !== ''
            ? $payload['title']
            : $card->title;
        $bodyText = isset($payload['body_text']) && is_string($payload['body_text'])
            ? $payload['body_text']
            : (isset($payload['description']) && is_string($payload['description']) ? $payload['description'] : '');
        if (HhVacancyClosed::looksLike($title, $bodyText)) {
            throw new \RuntimeException('vacancy_unavailable');
        }
        if (HhBotWall::looksLike($title)) {
            throw new \RuntimeException('captcha_or_action_required');
        }
        $companyName = isset($payload['company_name']) && is_string($payload['company_name']) && $payload['company_name'] !== ''
            ? $payload['company_name']
            : $card->companyName;
        $companyId = isset($payload['company_external_id']) && is_string($payload['company_external_id']) && $payload['company_external_id'] !== ''
            ? $payload['company_external_id']
            : $card->companyExternalId;
        $description = isset($payload['description']) && is_string($payload['description']) && $payload['description'] !== ''
            ? $payload['description']
            : null;

        $keySkills = [];
        if (isset($payload['key_skills']) && is_array($payload['key_skills'])) {
            foreach ($payload['key_skills'] as $skill) {
                if (!is_string($skill)) {
                    continue;
                }
                $skill = trim($skill);
                if ($skill === '' || in_array($skill, $keySkills, true)) {
                    continue;
                }
                $keySkills[] = $skill;
            }
        }

        $publishedRaw = isset($payload['published_text']) && is_string($payload['published_text'])
            ? trim($payload['published_text'])
            : '';
        $publishedAt = $publishedRaw !== '' ? VacancyPublishedAt::parse($publishedRaw) : null;

        return new VacancyDetail(
            externalId: $card->externalId,
            title: $title,
            url: $card->url,
            companyName: $companyName,
            companyExternalId: $companyId,
            description: $description,
            salaryText: isset($payload['salary_text']) && is_string($payload['salary_text'])
                ? $payload['salary_text']
                : $card->salaryText,
            areaText: $card->areaText,
            employmentText: isset($payload['employment_text']) && is_string($payload['employment_text'])
                ? $payload['employment_text']
                : null,
            scheduleText: isset($payload['schedule_text']) && is_string($payload['schedule_text'])
                ? $payload['schedule_text']
                : null,
            experienceText: isset($payload['experience_text']) && is_string($payload['experience_text'])
                ? $payload['experience_text']
                : null,
            keySkills: $keySkills,
            publishedAt: $publishedAt,
        );
    }

    /**
     * Текст резюме через UI-конвертер (тот же поток, что «Скачать → txt»).
     * OAuth /resumes/{id} у приложения часто 403 — поэтому browser-сессия.
     */
    public function readResumeTxt(PageInterface $page, string $externalId, string $title): string
    {
        $externalId = trim($externalId);
        $title = trim($title);
        if ($externalId === '' || $title === '') {
            throw new \InvalidArgumentException('Resume id/title is empty');
        }

        $url = 'https://hh.ru/resume_converter/' . rawurlencode($title)
            . '.txt?hash=' . rawurlencode($externalId)
            . '&type=txt&hhtmSource=profile';

        $page->goto($url, ['waitUntil' => 'domcontentloaded']);
        usleep(700_000);

        $payload = $page->evaluate(<<<'JS'
() => {
  const root = document.querySelector('.resume__body')
    || document.querySelector('.resume')
    || document.body;
  const text = ((root && root.innerText) || '').replace(/\n{3,}/g, '\n\n').trim();
  return {
    text,
    title: document.title || '',
    looksLogin: !!document.querySelector(
      '[data-qa="account-login-form"], [data-qa="applicant-login-card"], [data-qa="login"]'
    ),
  };
}
JS);

        if (!is_array($payload)) {
            throw new \RuntimeException('Resume converter returned invalid payload');
        }
        if (($payload['looksLogin'] ?? false) === true) {
            throw new \RuntimeException('login_required');
        }

        $text = isset($payload['text']) && is_string($payload['text']) ? $payload['text'] : '';
        if ($text === '' || mb_strlen($text) < 40) {
            throw new \RuntimeException('Resume text is empty or too short for ' . $externalId);
        }

        return $text;
    }

    /** Снимок списка резюме (или сигнал login/captcha). */
    public function readResumes(PageInterface $page): ResumeList
    {
        $payload = $page->evaluate(<<<'JS'
() => {
  const bodyText = ((document.body && document.body.innerText) || '').slice(0, 2000);
  const title = (document.title || '');
  if (
    /ddos[\s_-]*guard/i.test(title) ||
    /checking your browser/i.test(bodyText) ||
    /verify your browser/i.test(bodyText)
  ) {
    return { kind: 'captcha_or_action_required', items: [] };
  }
  const login = document.querySelector(
    '[data-qa="account-login-form"], [data-qa="applicant-login-card"], [data-qa="login"]'
  );
  if (login) {
    return { kind: 'login_required', items: [] };
  }
  const captcha = document.querySelector(
    '[data-qa="account-captcha"], [data-qa="captcha"], .bloko-captcha, iframe[src*="captcha"]'
  );
  if (captcha) {
    return { kind: 'captcha_or_action_required', items: [] };
  }
  const items = [];
  const seen = new Set();
  const parseVacancies = (text) => {
    const m = String(text || '').replace(/\u00a0/g, ' ').match(/(\d[\d\s]*)\s*ваканс/i);
    if (!m) return null;
    const n = parseInt(m[1].replace(/\s+/g, ''), 10);
    return Number.isFinite(n) ? n : null;
  };
  const cards = Array.from(document.querySelectorAll('[data-qa="resume"]'));
  const roots = cards.length
    ? cards
    : Array.from(document.querySelectorAll('[data-qa="compact-resume-list"] [data-qa*="resume"]'));
  for (const root of roots) {
    const rec = root.querySelector('a[data-qa="resume-recommendations-button"]');
    const href = rec ? (rec.getAttribute('href') || '') : '';
    let id = null;
    const fromQuery = href.match(/[?&]resume=([0-9A-Za-z_-]{6,})/);
    if (fromQuery) id = fromQuery[1];
    if (!id) {
      const link = root.querySelector('a[href*="/resume/"]');
      const fromPath = link && (link.getAttribute('href') || '').match(/\/resume\/([0-9A-Za-z_-]{6,})/);
      if (fromPath) id = fromPath[1];
    }
    if (!id || seen.has(id)) continue;
    const titleNode =
      root.querySelector('[data-qa="resume-title"]') ||
      root.querySelector('h3[data-qa="title"], [data-qa="title"], a[data-qa^="resume-card-link-"]');
    let itemTitle = titleNode
      ? (titleNode.textContent || '').trim().replace(/\s+/g, ' ')
      : '';
    if (!itemTitle || itemTitle.length < 2) continue;
    if (/cookie|footer|политик|blog|expert|^дополнить резюме$/i.test(itemTitle)) continue;
    const vacanciesCount = rec ? parseVacancies(rec.textContent || '') : null;
    seen.add(id);
    items.push({ external_id: id, title: itemTitle, vacancies_count: vacanciesCount });
  }
  // Fallback: только кнопки рекомендаций, если карточки не размечены.
  if (!items.length) {
    for (const rec of document.querySelectorAll('a[data-qa="resume-recommendations-button"]')) {
      const href = rec.getAttribute('href') || '';
      const m = href.match(/[?&]resume=([0-9A-Za-z_-]{6,})/);
      if (!m || seen.has(m[1])) continue;
      seen.add(m[1]);
      items.push({
        external_id: m[1],
        title: m[1],
        vacancies_count: parseVacancies(rec.textContent || ''),
      });
    }
  }
  return { kind: 'ok', items };
}
JS);

        if (!is_array($payload)) {
            return new ResumeList(ResumeListKind::Invalid, [], $page->url());
        }

        $kindRaw = isset($payload['kind']) && is_string($payload['kind'])
            ? $payload['kind']
            : 'invalid';
        $kind = ResumeListKind::tryFrom($kindRaw) ?? ResumeListKind::Invalid;
        $rawItems = $payload['items'] ?? [];
        /** @var array<string, array{created_at: ?string, updated_at: ?string}> $datesByHash */
        $datesByHash = $kind === ResumeListKind::Ok
            ? $this->readResumeDatesFromApplicantJson($page)
            : [];
        $items = [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = isset($row['external_id']) ? (string) $row['external_id'] : '';
                $title = isset($row['title']) ? (string) $row['title'] : '';
                if ($id === '' || $title === '') {
                    continue;
                }
                $vacancies = null;
                if (array_key_exists('vacancies_count', $row) && $row['vacancies_count'] !== null) {
                    $vacancies = (int) $row['vacancies_count'];
                }
                $dates = $datesByHash[$id] ?? ['created_at' => null, 'updated_at' => null];
                $items[] = new ResumeSummary(
                    $id,
                    $title,
                    $vacancies,
                    $dates['created_at'],
                    $dates['updated_at'],
                );
            }
        }

        return new ResumeList($kind, $items, $page->url());
    }

    /**
     * Даты создания/обновления из JSON `/applicant/resumes` (OAuth /resumes — 403).
     *
     * @return array<string, array{created_at: ?string, updated_at: ?string}>
     */
    private function readResumeDatesFromApplicantJson(PageInterface $page): array
    {
        $raw = $page->evaluate(<<<'JS'
async () => {
  try {
    const r = await fetch('/applicant/resumes', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    });
    if (!r.ok) return [];
    const d = await r.json();
    const list = Array.isArray(d.applicantResumes) ? d.applicantResumes : [];
    const out = [];
    for (const it of list) {
      const attrs = it && it._attributes ? it._attributes : null;
      if (!attrs || !attrs.hash) continue;
      out.push({
        hash: String(attrs.hash),
        created_ms: typeof attrs.created === 'number' ? attrs.created : null,
        updated_ms: typeof attrs.updated === 'number' ? attrs.updated : null,
      });
    }
    return out;
  } catch (e) {
    return [];
  }
}
JS);

        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hash = trim((string) ($row['hash'] ?? ''));
            if ($hash === '') {
                continue;
            }
            $out[$hash] = [
                'created_at' => $this->hhMillisToIso($row['created_ms'] ?? null),
                'updated_at' => $this->hhMillisToIso($row['updated_ms'] ?? null),
            ];
        }

        return $out;
    }

    private function hhMillisToIso(mixed $ms): ?string
    {
        if (!is_int($ms) && !is_float($ms)) {
            if (is_string($ms) && is_numeric($ms)) {
                $ms = (float) $ms;
            } else {
                return null;
            }
        }
        $ms = (int) $ms;
        if ($ms <= 0) {
            return null;
        }
        try {
            return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('c');
        } catch (\Exception) {
            return null;
        }
    }

    /** Страница выглядит как уже залогиненный кабинет резюме. */
    public function looksLoggedIn(PageInterface $page): bool
    {
        $list = $this->readResumes($page);

        return $list->kind === ResumeListKind::Ok && $list->items !== [];
    }

    /**
     * Переключить город через UI geoSwitcher (поиск + точный выбор названия).
     * Страница должна быть уже на hh.ru; после клика дождаться смены региона.
     */
    public function switchCity(PageInterface $page, string $cityName): void
    {
        $cityName = trim($cityName);
        if ($cityName === '') {
            throw new \InvalidArgumentException('City name is empty');
        }

        $page->locator('[data-qa="geoSwitcher-button"]')->first()->click();
        usleep(500_000);
        $page->locator('[data-qa="geo-switcher-search"]')->first()->fill($cityName);
        usleep(900_000);

        $clicked = $page->evaluate(
            <<<'JS'
(city) => {
  const nodes = Array.from(document.querySelectorAll('[data-qa="geo-switcher-name"]'));
  const exact = nodes.find((el) => (el.textContent || '').trim() === city);
  if (!exact) {
    return false;
  }
  const clickable = exact.closest('label,[role="option"],a,button,div[data-qa="cell"]') || exact;
  clickable.click();
  return true;
}
JS,
            $cityName,
        );

        if ($clicked !== true) {
            throw new \RuntimeException("City option not found in geo switcher: {$cityName}");
        }

        usleep(1_500_000);
        // Иногда остаёмся на кастомном домене — принудительно обновим главную.
        $page->goto(self::HOME_URL, ['waitUntil' => 'domcontentloaded']);
        usleep(700_000);
    }

    /**
     * Отклик: страница вакансии → кнопка → попап (резюме) → submit без письма.
     */
    public function applyToVacancy(
        PageInterface $page,
        string $externalVacancyId,
        string $resumeId,
    ): ApplyResult {
        $externalVacancyId = trim($externalVacancyId);
        $resumeId = trim($resumeId);
        if ($externalVacancyId === '' || $resumeId === '') {
            return new ApplyResult(
                ok: false,
                submitted: false,
                alreadyApplied: false,
                message: 'Нужны vacancy id и resume id.',
                vacancyExternalId: $externalVacancyId,
                resumeId: $resumeId,
            );
        }

        try {
            return $this->applyToVacancyOnce($page, $externalVacancyId, $resumeId);
        } catch (\Throwable $e) {
            if (!self::isDestroyedContext($e)) {
                throw $e;
            }

            return new ApplyResult(
                false,
                false,
                false,
                'HH ушёл со страницы во время отклика (навигация). Повтори эту вакансию.',
                $externalVacancyId,
                $resumeId,
            );
        }
    }

    private function applyToVacancyOnce(
        PageInterface $page,
        string $externalVacancyId,
        string $resumeId,
    ): ApplyResult {
        $url = 'https://hh.ru/vacancy/' . rawurlencode($externalVacancyId)
            . '?resume=' . rawurlencode($resumeId);
        $page->goto($url, ['waitUntil' => 'domcontentloaded']);
        // Magritte CTA часто появляется позже domcontentloaded; фиксированные 1.5с → ложный «кнопка не найдена».
        $pre = $this->waitVacancyApplyActionable($page, 10_000_000);
        if (($pre['kind'] ?? '') === 'unavailable') {
            return new ApplyResult(
                false,
                false,
                false,
                (string) ($pre['message'] ?? 'Вакансия недоступна на HH.'),
                $externalVacancyId,
                $resumeId,
            );
        }
        if (($pre['kind'] ?? '') === 'login_required') {
            return new ApplyResult(false, false, false, 'Нужен login (browser-сессия).', $externalVacancyId, $resumeId);
        }
        if (($pre['kind'] ?? '') === 'captcha') {
            if ($this->trySolveCaptcha($page, 'apply-pre ' . $externalVacancyId)) {
                // После account/captcha HH должен вернуть на vacancy; если нет — явный goto.
                try {
                    $page->goto($url, ['waitUntil' => 'domcontentloaded']);
                } catch (\Throwable) {
                }
                $pre = $this->waitVacancyApplyActionable($page, 10_000_000);
            }
        }
        if (($pre['kind'] ?? '') === 'captcha') {
            return new ApplyResult(
                false,
                false,
                false,
                (string) ($pre['message'] ?? 'HH показал капчу/защиту — отклик не отправлен')
                    . ($this->captchaEnabled() ? ' (TG-код не принят / таймаут).' : '. Включи TELEGRAM_DM_* / HH_CAPTCHA_TG.'),
                $externalVacancyId,
                $resumeId,
            );
        }
        if (($pre['kind'] ?? '') === 'already') {
            return new ApplyResult(true, false, true, 'Уже откликались на эту вакансию.', $externalVacancyId, $resumeId);
        }
        if (($pre['kind'] ?? '') !== 'ready') {
            return new ApplyResult(
                false,
                false,
                false,
                (string) ($pre['message'] ?? 'Кнопка «Откликнуться» не найдена.'),
                $externalVacancyId,
                $resumeId,
            );
        }

        $clicked = $this->clickVacancyRespond($page);
        if (!$clicked) {
            return new ApplyResult(false, false, false, 'Не удалось нажать «Откликнуться».', $externalVacancyId, $resumeId);
        }
        // Модалка или полная страница /applicant/vacancy_response (вопросы работодателя).
        $this->waitApplySurface($page, 3_000_000);

        $popup = $this->completeResponsePopup($page, $resumeId);
        $kind = (string) ($popup['kind'] ?? '');
        // HH: «Вы откликаетесь на вакансию в другой стране» — мягкое предупреждение,
        // кнопка «Все равно откликнуться». Не abort: подтверждаем и шлём отклик.
        if ($kind === 'relocation_confirm') {
            if (!$this->confirmRelocationWarning($page)) {
                $this->dismissResponseModal($page);

                return new ApplyResult(
                    false,
                    false,
                    false,
                    'Вакансия в другой стране — не нашёл кнопку подтверждения отклика.',
                    $externalVacancyId,
                    $resumeId,
                );
            }
            usleep(1_500_000);
            $popup = $this->completeResponsePopup($page, $resumeId);
            $kind = (string) ($popup['kind'] ?? '');
            if ($kind === 'relocation_confirm') {
                $this->dismissResponseModal($page);

                return new ApplyResult(
                    false,
                    false,
                    false,
                    'Вакансия в другой стране — предупреждение HH не снялось после подтверждения.',
                    $externalVacancyId,
                    $resumeId,
                );
            }
        }
        if ($kind === 'questions_required') {
            $screened = $this->tryFillScreening($page, $externalVacancyId);
            if ($screened !== null) {
                return $screened;
            }
            // Анкета заполнена — добираем резюме / письмо / submit на той же странице.
            $popup = $this->completeResponsePopup($page, $resumeId, skipQuestions: true);
            $kind = (string) ($popup['kind'] ?? '');
        }
        if ($kind === 'resume_hidden') {
            $this->dismissResponseModal($page);

            return new ApplyResult(
                false,
                false,
                false,
                'Резюме скрыто от работодателей — HH не даёт откликнуться. Сделай видимость «Всем на hh.ru».',
                $externalVacancyId,
                $resumeId,
            );
        }
        if ($kind === 'letter_required') {
            $lettered = $this->tryFillCoverLetter($page, $externalVacancyId, $resumeId);
            if ($lettered !== null) {
                return $lettered;
            }
            $popup = $this->completeResponsePopup($page, $resumeId, skipQuestions: true);
            $kind = (string) ($popup['kind'] ?? '');
            if ($kind === 'letter_required') {
                $this->dismissResponseModal($page);

                return new ApplyResult(
                    false,
                    false,
                    false,
                    'HH требует сопроводительное письмо — после заполнения отклик не ушёл.',
                    $externalVacancyId,
                    $resumeId,
                );
            }
        }
        if ($kind === 'already') {
            return new ApplyResult(true, false, true, 'Уже откликались на эту вакансию.', $externalVacancyId, $resumeId);
        }
        if ($kind !== 'submitted' && $kind !== 'ok') {
            $this->dismissResponseModal($page);

            return new ApplyResult(
                false,
                false,
                false,
                (string) ($popup['message'] ?? 'Не удалось отправить отклик.'),
                $externalVacancyId,
                $resumeId,
            );
        }

        usleep(2_000_000);
        $submitState = $this->inspectResponseSubmitResult($page);
        // isBot 403 часто сначала «Произошла ошибка», потом модалка капчи — ждём и пробуем TG.
        if (in_array(($submitState['kind'] ?? ''), ['captcha', 'error'], true)) {
            usleep(1_500_000);
            if ($this->trySolveCaptcha($page, 'apply-submit ' . $externalVacancyId)) {
                if ($this->clickResponseSubmit($page)) {
                    usleep(2_500_000);
                    $submitState = $this->inspectResponseSubmitResult($page);
                } else {
                    // Капча снята, модалка могла закрыться — проверим по кнопке на странице ниже.
                    $submitState = ['kind' => 'ok'];
                }
            }
        }
        if (($submitState['kind'] ?? '') === 'captcha') {
            $this->dismissResponseModal($page);

            return new ApplyResult(
                false,
                false,
                false,
                'HH запросил captcha — отклик не отправлен'
                    . ($this->captchaEnabled() ? ' (TG-код не принят / таймаут).' : '. Включи TELEGRAM_DM_* / HH_CAPTCHA_TG.'),
                $externalVacancyId,
                $resumeId,
            );
        }
        if (($submitState['kind'] ?? '') === 'error') {
            $this->dismissResponseModal($page);

            return new ApplyResult(
                false,
                false,
                false,
                (string) ($submitState['message'] ?? 'HH вернул ошибку при отклике.'),
                $externalVacancyId,
                $resumeId,
            );
        }
        // Magritte часто ещё секунду-две держит старую кнопку — ждём закрытия модалки
        // и несколько раз перечитываем страницу, прежде чем орать «кнопка всё ещё видна».
        $this->waitResponseModalClosed($page, 4_000_000);
        $bust = $url . (str_contains($url, '?') ? '&' : '?') . '_=' . rawurlencode((string) time());
        $page->goto($bust, ['waitUntil' => 'domcontentloaded']);
        $post = $this->waitVacancyApplySettled($page, 6);
        if (($post['kind'] ?? '') === 'already') {
            return new ApplyResult(true, true, false, 'Отклик отправлен.', $externalVacancyId, $resumeId);
        }
        if (($post['kind'] ?? '') === 'ready') {
            // Submit «прошёл», а на деле HH хотел анкету/письмо — уточняем повторным кликом.
            $blocker = $this->reopenShowsApplyBlocker($page);
            if ($blocker === 'questions_required') {
                $screened = $this->tryFillScreening($page, $externalVacancyId);
                if ($screened !== null) {
                    return new ApplyResult(
                        false,
                        false,
                        false,
                        $screened->message,
                        $externalVacancyId,
                        $resumeId,
                    );
                }
                $popup = $this->completeResponsePopup($page, $resumeId, skipQuestions: true);
                $kind = (string) ($popup['kind'] ?? '');
                if ($kind === 'letter_required') {
                    $lettered = $this->tryFillCoverLetter($page, $externalVacancyId, $resumeId);
                    if ($lettered !== null) {
                        return $lettered;
                    }
                    $popup = $this->completeResponsePopup($page, $resumeId, skipQuestions: true);
                    $kind = (string) ($popup['kind'] ?? '');
                }
                if ($kind === 'resume_hidden') {
                    return new ApplyResult(
                        false,
                        false,
                        false,
                        'Резюме скрыто от работодателей — HH не даёт откликнуться. Сделай видимость «Всем на hh.ru».',
                        $externalVacancyId,
                        $resumeId,
                    );
                }
                if ($kind === 'submitted' || $kind === 'ok' || $kind === 'already') {
                    return new ApplyResult(true, true, $kind === 'already', 'Отклик отправлен.', $externalVacancyId, $resumeId);
                }
                if ($kind === 'letter_required') {
                    return new ApplyResult(
                        false,
                        false,
                        false,
                        'HH требует сопроводительное письмо — после заполнения отклик не ушёл.',
                        $externalVacancyId,
                        $resumeId,
                    );
                }

                return new ApplyResult(
                    false,
                    false,
                    false,
                    'HH требует ответы на вопросы работодателя — после заполнения отклик не ушёл.',
                    $externalVacancyId,
                    $resumeId,
                );
            }
            if ($blocker === 'letter_required') {
                $lettered = $this->tryFillCoverLetter($page, $externalVacancyId, $resumeId);
                if ($lettered !== null) {
                    return $lettered;
                }
                $popup = $this->completeResponsePopup($page, $resumeId, skipQuestions: true);
                $kind = (string) ($popup['kind'] ?? '');
                if ($kind === 'submitted' || $kind === 'ok' || $kind === 'already') {
                    return new ApplyResult(true, true, $kind === 'already', 'Отклик отправлен.', $externalVacancyId, $resumeId);
                }

                return new ApplyResult(
                    false,
                    false,
                    false,
                    'HH требует сопроводительное письмо — после заполнения отклик не ушёл.',
                    $externalVacancyId,
                    $resumeId,
                );
            }

            return new ApplyResult(
                false,
                false,
                false,
                'После отправки кнопка «Откликнуться» всё ещё видна — проверь вручную на HH.',
                $externalVacancyId,
                $resumeId,
            );
        }

        return new ApplyResult(true, true, false, 'Отклик отправлен.', $externalVacancyId, $resumeId);
    }

    /**
     * Повторно открыть «Откликнуться» и вернуть блокер: questions_required / letter_required / ''.
     * Только смотрим UI — submit не жмём.
     */
    private function reopenShowsApplyBlocker(PageInterface $page): string
    {
        if (!$this->clickVacancyRespond($page)) {
            return '';
        }
        $this->waitApplySurface($page, 3_000_000);
        $kind = $this->evaluateSafe($page, <<<'JS'
() => {
  const root = document.querySelector('[data-qa="modal-overlay"]')
    || document.querySelector('[data-qa="vacancy-response-popup"]')
    || document.body;
  const text = ((root && root.innerText) || '').toLowerCase().replace(/\u00a0/g, ' ');
  const questionsUi = !!(
    document.querySelector('[data-qa="employer-asking-for-test"], [data-qa="task-question"], [data-qa="task-body"]')
    || document.querySelector('textarea[name^="task_"]')
    || /для отклика необходимо ответить|ответьте на\s+вопросы|вопросов работодателя/i.test(text)
  );
  if (questionsUi) return 'questions_required';
  const letterEl = root.querySelector(
    '[data-qa="vacancy-response-popup-form-letter-input"], textarea[name="letter"], textarea[data-qa*="letter"]'
  );
  const hint = /обязательн\w*.{0,48}сопроводительн|сопроводительн\w*.{0,48}обязательн|без сопроводительн|укажите сопроводительн|напишите сопроводительн|сопроводительное письмо\s*\*/i.test(text);
  const attrRequired = !!(letterEl && (
    letterEl.required
    || letterEl.getAttribute('aria-required') === 'true'
    || letterEl.getAttribute('aria-invalid') === 'true'
  ));
  const submit = root.querySelector(
    '[data-qa="vacancy-response-submit-popup"], [data-qa="vacancy-response-popup-submit-button"]'
  );
  if (letterEl && (hint || attrRequired)) return 'letter_required';
  if (submit && submit.disabled && letterEl) return 'letter_required';
  if (hint) return 'letter_required';
  return '';
}
JS);
        // Модалку оставляем открытой для screening / cover letter fill.
        if (!is_string($kind) || $kind === '') {
            $this->dismissResponseModal($page);

            return '';
        }

        return $kind;
    }

    /**
     * Сгенерировать письмо через DevAI+TG и вставить в форму.
     * null = письмо вставлено, можно submit; иначе ApplyResult с ошибкой/skip.
     */
    private function tryFillCoverLetter(
        PageInterface $page,
        string $externalVacancyId,
        string $resumeId,
    ): ?ApplyResult {
        if ($this->coverLetter === null) {
            $this->dismissResponseModal($page);

            return new ApplyResult(
                false,
                false,
                false,
                'HH требует сопроводительное письмо — TG/DevAI не настроены (HH_LETTER_TG / TELEGRAM_DM_*).',
                $externalVacancyId,
                $resumeId,
            );
        }

        $fill = $this->coverLetter->fill($page, $externalVacancyId, $resumeId);
        if ($fill->ok()) {
            return null;
        }
        $this->dismissResponseModal($page);
        $message = $fill->message !== ''
            ? $fill->message
            : 'HH требует сопроводительное письмо — не удалось подготовить текст.';
        $skipped = $fill->kind === 'skipped';

        return new ApplyResult(false, false, false, $message, $externalVacancyId, $resumeId, $skipped);
    }

    /** Ждём модалку отклика или полную страницу vacancy_response. */
    private function waitApplySurface(PageInterface $page, int $timeoutUs): void
    {
        $deadline = hrtime(true) + ($timeoutUs * 1_000);
        while (hrtime(true) < $deadline) {
            try {
                $url = (string) $page->url();
                if (str_contains($url, '/applicant/vacancy_response')) {
                    usleep(400_000);

                    return;
                }
                $ready = $this->evaluateSafe($page, <<<'JS'
() => {
  if (document.querySelector('[data-qa="modal-overlay"], [data-qa="vacancy-response-popup"]')) return true;
  if (document.querySelector('[data-qa="employer-asking-for-test"], [data-qa="task-question"], [data-qa="task-body"]')) return true;
  if (document.querySelector('textarea[name^="task_"]')) return true;
  return false;
}
JS);
                if ($ready === true) {
                    usleep(300_000);

                    return;
                }
            } catch (\Throwable) {
                // Навигация на vacancy_response рвёт evaluate — подождём и повторим.
            }
            usleep(250_000);
        }
    }

    /** Ждём исчезновения Magritte/попапа отклика (до $timeoutUs). */
    private function waitResponseModalClosed(PageInterface $page, int $timeoutUs): void
    {
        $deadline = hrtime(true) + ($timeoutUs * 1_000);
        while (hrtime(true) < $deadline) {
            try {
                $open = $this->evaluateSafe($page, <<<'JS'
() => {
  const root = document.querySelector('[data-qa="modal-overlay"], [data-qa="vacancy-response-popup"]');
  if (!root) return false;
  const style = window.getComputedStyle(root);
  if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
    return false;
  }
  return root.offsetParent !== null || root.getClientRects().length > 0;
}
JS);
                if ($open !== true) {
                    return;
                }
            } catch (\Throwable) {
                // модалка/страница ушли вместе с навигацией
                return;
            }
            usleep(300_000);
        }
    }

    /**
     * После reload: HH может ещё мигнуть старым CTA — поллим.
     *
     * @return array{kind: string, message?: string}
     */
    private function waitVacancyApplySettled(PageInterface $page, int $attempts): array
    {
        $last = ['kind' => 'missing', 'message' => 'Не удалось прочитать страницу.'];
        for ($i = 0; $i < max(1, $attempts); $i++) {
            if ($i > 0) {
                usleep(1_200_000);
            }
            $last = $this->inspectVacancyApplyState($page);
            $kind = (string) ($last['kind'] ?? '');
            if ($kind === 'already' || $kind === 'unavailable' || $kind === 'login_required') {
                return $last;
            }
            if ($kind !== 'ready') {
                return $last;
            }
        }

        return $last;
    }

    private function captchaEnabled(): bool
    {
        return $this->captcha !== null && $this->captcha->enabled();
    }

    private function trySolveCaptcha(PageInterface $page, string $context): bool
    {
        if (!$this->captchaEnabled()) {
            return false;
        }
        // isBot часто сначала «Произошла ошибка», модалка капчи догоняет через несколько секунд.
        // Без ожидания «Повторить» молча не шлёт скрин в TG.
        $deadline = hrtime(true) + 12_000_000_000;
        while (true) {
            if (CaptchaForm::pageHasCaptcha($page)
                || $this->inspectLooksLikeCaptchaChallenge($page)) {
                break;
            }
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep(1_000_000);
        }

        return $this->captcha->tryResolve($page, $context);
    }

    private function inspectLooksLikeCaptchaChallenge(PageInterface $page): bool
    {
        $state = $this->inspectResponseSubmitResult($page);

        return ($state['kind'] ?? '') === 'captcha';
    }

    private function clickResponseSubmit(PageInterface $page): bool
    {
        $clicked = $page->evaluate(<<<'JS'
() => {
  const root = document.querySelector('[data-qa="modal-overlay"]')
    || document.querySelector('[data-qa="vacancy-response-popup"]');
  if (!root) return false;
  const submit = root.querySelector(
    '[data-qa="vacancy-response-submit-popup"], [data-qa="vacancy-response-popup-submit-button"]'
  );
  if (!submit || submit.disabled) return false;
  submit.click();
  return true;
}
JS);

        return $clicked === true;
    }

    /** Подтвердить HH-предупреждение «вакансия в другой стране». */
    private function confirmRelocationWarning(PageInterface $page): bool
    {
        $clicked = $page->evaluate(<<<'JS'
() => {
  const btn = document.querySelector('[data-qa="relocation-warning-confirm"]');
  if (!btn || btn.offsetParent === null) return false;
  btn.click();
  return true;
}
JS);

        return $clicked === true;
    }

    /**
     * После submit в Magritte-модалке: captcha / общая ошибка / ок.
     *
     * @return array{kind: string, message?: string}
     */
    private function inspectResponseSubmitResult(PageInterface $page): array
    {
        $payload = $this->evaluateSafe($page, <<<'JS'
() => {
  if (typeof location !== 'undefined' && /\/account\/captcha/i.test(location.href || '')) {
    return { kind: 'captcha' };
  }
  const root = document.querySelector('[data-qa="modal-overlay"]')
    || document.querySelector('[data-qa="vacancy-response-popup"]');
  const scope = root || document.body;
  const text = ((scope && scope.innerText) || '').toLowerCase().replace(/\u00a0/g, ' ');
  const captchaEl = document.querySelector(
    '[data-qa="account-captcha"], [data-qa="captcha"], .bloko-captcha, iframe[src*="captcha"]'
  );
  const captchaInput = document.querySelector(
    'input[placeholder*="картинки" i], input[name*="captcha" i], input[data-qa*="captcha" i]'
  );
  if (captchaEl || captchaInput || /пройдите\s+капчу|hhcaptcha|я не робот/.test(text)) {
    return { kind: 'captcha' };
  }
  if (!root) {
    return { kind: 'ok' };
  }
  if (/произошла ошибка|попробуйте ещё раз|попробуйте еще раз/.test(text)) {
    return { kind: 'error', message: 'HH вернул «Произошла ошибка» при отклике (часто captcha/антибот).' };
  }
  return { kind: 'ok' };
}
JS);

        return is_array($payload) ? $payload : ['kind' => 'ok'];
    }

    /** @return array{kind: string, message?: string} */
    private function inspectVacancyApplyState(PageInterface $page): array
    {
        $payload = $this->evaluateSafe($page, <<<'JS'
() => {
  const bodyText = ((document.body && document.body.innerText) || '').slice(0, 2500);
  const title = document.title || '';
  const href = String(location.href || '');
  // /account/captcha и «Подтвердите, что вы не робот» — не «кнопка пропала».
  if (
    /\/account\/captcha/i.test(href) ||
    /подтвердите,\s*что вы не робот|я не робот/i.test(title) ||
    /подтвердите,\s*что вы не робот/i.test(bodyText) ||
    /ddos[\s_-]*guard/i.test(title) ||
    /checking your browser|verify your browser/i.test(bodyText)
  ) {
    return {
      kind: 'captcha',
      message: 'HH показал проверку «не робот» (account/captcha) — без неё кнопка «Откликнуться» не появится.',
    };
  }
  // Закрытая/скрытая вакансия: HH пишет «недоступна» и рисует login-форму —
  // это НЕ протухшая browser-сессия (меню «Резюме и профиль» при этом есть).
  if (/вам недоступна эта вакансия|вакансия (не найдена|снята с публикации|в архиве)/i.test(bodyText)) {
    return {
      kind: 'unavailable',
      message: 'Вакансия недоступна на HH (закрыта или скрыта).',
    };
  }
  const loginForm = document.querySelector(
    '[data-qa="account-login-form"], [data-qa="applicant-login-card"]'
  );
  // [data-qa="login"] слишком широкий — ловит ссылки в шапке.
  if (loginForm) {
    return { kind: 'login_required' };
  }
  const alreadyRe = /откликнулись|отклик отправлен|вы откликнулись|отклик направлен|отклик получен/;
  // Блок статуса отклика (не только текст кнопки) — Magritte иногда оставляет старый CTA.
  const statusEl = document.querySelector(
    '[data-qa="vacancy-response-status"], [data-qa*="negotiations-item"], [data-qa="vacancy-response-link-view"]'
  );
  if (statusEl && alreadyRe.test((statusEl.textContent || '').toLowerCase())) {
    return { kind: 'already' };
  }
  const top = document.querySelector('[data-qa="vacancy-response-link-top"]');
  const bottom = document.querySelector('[data-qa="vacancy-response-link-bottom"]');
  let btn = top || bottom;
  // Magritte иногда без прежних data-qa — ищем явную кнопку по тексту.
  if (!btn) {
    btn = Array.from(document.querySelectorAll('button, a[href], [role="button"]')).find((el) => {
      const t = (el.textContent || '').toLowerCase().replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
      return t === 'откликнуться' || t === 'respond';
    }) || null;
  }
  const label = (btn ? (btn.textContent || '') : '').toLowerCase().replace(/\u00a0/g, ' ').trim();
  if (alreadyRe.test(label) || alreadyRe.test(bodyText.toLowerCase())) {
    return { kind: 'already' };
  }
  if (btn) {
    if (btn.disabled || btn.getAttribute('aria-disabled') === 'true') {
      return { kind: 'already' };
    }
    // Пустой/иконочный текст без «откликнуться» — не считаем ready.
    if (label !== '' && !/откликнуться|respond/.test(label)) {
      return { kind: 'already' };
    }
    return { kind: 'ready' };
  }
  return { kind: 'missing', message: 'Кнопка «Откликнуться» не найдена на странице вакансии.' };
}
JS);

        return is_array($payload) ? $payload : ['kind' => 'missing', 'message' => 'Не удалось прочитать страницу.'];
    }

    private function clickVacancyRespond(PageInterface $page): bool
    {
        $clicked = $this->evaluateSafe($page, <<<'JS'
() => {
  let btn = document.querySelector('[data-qa="vacancy-response-link-top"]')
    || document.querySelector('[data-qa="vacancy-response-link-bottom"]');
  if (!btn) {
    btn = Array.from(document.querySelectorAll('button, a[href], [role="button"]')).find((el) => {
      const t = (el.textContent || '').toLowerCase().replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
      return t === 'откликнуться' || t === 'respond';
    }) || null;
  }
  if (!btn) return false;
  btn.click();
  return true;
}
JS);

        return $clicked === true;
    }

    /**
     * Ждём CTA / терминальный статус после goto (не фиксированный sleep).
     *
     * @return array{kind: string, message?: string}
     */
    private function waitVacancyApplyActionable(PageInterface $page, int $timeoutUs = 10_000_000): array
    {
        $deadline = hrtime(true) + ($timeoutUs * 1_000);
        $last = ['kind' => 'missing', 'message' => 'Кнопка «Откликнуться» не найдена на странице вакансии.'];
        do {
            try {
                $last = $this->inspectVacancyApplyState($page);
            } catch (\Throwable) {
                usleep(400_000);
                continue;
            }
            $kind = (string) ($last['kind'] ?? '');
            if (in_array($kind, ['ready', 'already', 'unavailable', 'login_required', 'captcha'], true)) {
                return $last;
            }
            usleep(400_000);
        } while (hrtime(true) < $deadline);

        return $last;
    }

    /**
     * Анкета работодателя: fill (common/learned/TG) или ApplyResult с ошибкой.
     * null = анкета закрыта, можно продолжать submit.
     */
    private function tryFillScreening(PageInterface $page, string $externalVacancyId): ?ApplyResult
    {
        if ($this->screening === null) {
            $this->dismissResponseModal($page);

            return new ApplyResult(
                false,
                false,
                false,
                'HH требует ответы на вопросы работодателя — в v1 не заполняю.',
                $externalVacancyId,
                '',
            );
        }

        $fill = $this->screening->fill($page, $externalVacancyId);
        if ($fill->ok()) {
            return null;
        }
        $this->dismissResponseModal($page);
        $message = $fill->message !== ''
            ? $fill->message
            : 'HH требует ответы на вопросы работодателя — в v1 не заполняю.';
        $skipped = $fill->kind === 'skipped';

        return new ApplyResult(false, false, false, $message, $externalVacancyId, '', $skipped);
    }

    /**
     * @return array{kind: string, message?: string}
     */
    private function completeResponsePopup(
        PageInterface $page,
        string $resumeId,
        bool $skipQuestions = false,
    ): array {
        $payload = $this->evaluateSafe(
            $page,
            <<<'JS'
({ resumeId, skipQuestions }) => {
  const letterRequiredIn = (scope, scopeText) => {
    const t = String(scopeText || '').toLowerCase().replace(/\u00a0/g, ' ');
    const letterEl = scope.querySelector(
      '[data-qa="vacancy-response-popup-form-letter-input"], textarea[name="letter"], textarea[data-qa*="letter"]'
    );
    const letterValue = letterEl ? String(letterEl.value || '').trim() : '';
    if (letterValue.length >= 20) return false;
    const hint = /обязательн\w*.{0,48}сопроводительн|сопроводительн\w*.{0,48}обязательн|без сопроводительн|укажите сопроводительн|напишите сопроводительн|сопроводительное письмо\s*\*/i.test(t);
    const attrRequired = !!(letterEl && (
      letterEl.required
      || letterEl.getAttribute('aria-required') === 'true'
      || letterEl.getAttribute('aria-invalid') === 'true'
    ));
    const addLetter = Array.from(scope.querySelectorAll('button, a, [role="button"]')).some((el) => {
      if (el.offsetParent === null && !(el instanceof HTMLTextAreaElement)) return false;
      return /добавить сопроводительн|сопроводительное письмо/i.test((el.textContent || ''));
    });
    if (letterEl && (hint || attrRequired)) return true;
    if (hint && addLetter) return true;
    return false;
  };

  // Magritte: modal-overlay; старый UI: vacancy-response-popup;
  // анкета работодателя — отдельная страница /applicant/vacancy_response (не модалка).
  const root = document.querySelector('[data-qa="modal-overlay"]')
    || document.querySelector('[data-qa="vacancy-response-popup"]')
    || document.body;
  const text = ((root && root.innerText) || '').toLowerCase().replace(/\u00a0/g, ' ');
  // Мягкое geo-предупреждение HH (не SKIP DevAI). Видимая кнопка confirm.
  const relocateBtn = document.querySelector('[data-qa="relocation-warning-confirm"]');
  if (relocateBtn && relocateBtn.offsetParent !== null) {
    return { kind: 'relocation_confirm' };
  }
  if (/уже откликались|отклик отправлен|вы откликнулись/.test(text) && !document.querySelector('[data-qa="vacancy-response-submit-popup"], [data-qa="vacancy-response-popup-submit-button"]')) {
    return { kind: 'already' };
  }
  // Вопросы/тест работодателя (раньше письма: на той же странице часто есть и письмо).
  if (!skipQuestions) {
    const questionsUi = !!(
      document.querySelector('[data-qa="employer-asking-for-test"], [data-qa="task-question"], [data-qa="task-body"]')
      || document.querySelector('textarea[name^="task_"]')
      || /для отклика необходимо ответить|ответьте на\s+вопросы|вопросов работодателя/i.test(text)
    );
    if (questionsUi) {
      return { kind: 'questions_required' };
    }
  }
  // Magritte держит [data-qa=hidden-resume-warning] в DOM всегда, со свёрткой
  // max-height:0. textContent/innerText при этом всё равно содержит текст —
  // смотрим только реальную высоту.
  const hiddenWarn = root.querySelector('[data-qa="hidden-resume-warning"]');
  const hiddenVisible = !!(hiddenWarn && hiddenWarn.offsetHeight > 0);
  if (hiddenVisible) {
    return { kind: 'resume_hidden' };
  }

  const optionSelectors = [
    '[data-qa="magritte-select-option-' + resumeId + '"]',
    '[data-qa*="' + resumeId + '"]',
    'a[href*="/resume/' + resumeId + '"]',
  ];
  const dropdown = root.querySelector('[data-qa="resume-title"], [data-qa="vacancy-response-popup-resume"], [data-qa*="resume-select"], button[aria-haspopup="listbox"]');
  if (dropdown) {
    dropdown.click();
  }
  for (const sel of optionSelectors) {
    const opt = document.querySelector(sel);
    if (opt) {
      opt.click();
      break;
    }
  }

  if (letterRequiredIn(root, text)) {
    return { kind: 'letter_required' };
  }
  const submit = root.querySelector('[data-qa="vacancy-response-submit-popup"], [data-qa="vacancy-response-popup-submit-button"], button[data-qa="vacancy-response-submit-popup"]');
  if (!submit) {
    return { kind: 'error', message: 'Нет кнопки отправки отклика в попапе.' };
  }
  const letter = root.querySelector(
    '[data-qa="vacancy-response-popup-form-letter-input"], textarea[name="letter"], textarea[data-qa*="letter"]'
  );
  const letterFilled = !!(letter && String(letter.value || '').trim().length >= 20);
  // Submit disabled часто = пустое обязательное письмо (даже без явного «обязательно» в тексте).
  if (submit.disabled && letter && !letterFilled) {
    return { kind: 'letter_required' };
  }
  if (submit.disabled && letterFilled) {
    // React мог не разблокировать кнопку после программного fill — клик всё равно.
  }
  submit.click();
  return { kind: 'submitted' };
}
JS,
            ['resumeId' => $resumeId, 'skipQuestions' => $skipQuestions],
        );

        return is_array($payload) ? $payload : ['kind' => 'error', 'message' => 'Попап отклика не обработан.'];
    }

    private function dismissResponseModal(PageInterface $page): void
    {
        try {
            $this->evaluateSafe($page, <<<'JS'
() => {
  const close = document.querySelector('[data-qa="response-popup-close"]');
  if (close) {
    close.click();
    return;
  }
  const root = document.querySelector('[data-qa="modal-overlay"], [data-qa="vacancy-response-popup"]') || document.body;
  const buttons = Array.from(root.querySelectorAll('button'));
  const cancel = buttons.find((b) => /отмен/i.test((b.textContent || '')));
  if (cancel) {
    cancel.click();
    return;
  }
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
}
JS);
        } catch (\Throwable) {
            // навигация уже могла закрыть модалку
        }
        usleep(400_000);
    }

    /**
     * evaluate с одним повтором после навигации HH (иначе пакет apply-consider падает целиком).
     */
    private function evaluateSafe(PageInterface $page, string $expression, mixed $arg = null): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $arg === null
                    ? $page->evaluate($expression)
                    : $page->evaluate($expression, $arg);
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= 2 || !self::isDestroyedContext($e)) {
                    throw $e;
                }
                try {
                    $page->waitForLoadState('domcontentloaded', ['timeout' => 8_000]);
                } catch (\Throwable) {
                    usleep(800_000);
                }
                usleep(400_000);
            }
        }
    }

    private static function isDestroyedContext(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Execution context was destroyed')
            || str_contains($message, 'most likely because of a navigation')
            || str_contains($message, 'Frame was detached')
            || str_contains($message, 'Target closed')
            || str_contains($message, 'Target page, context or browser has been closed');
    }

    /** Текущий город/регион сессии (поддомен + подпись переключателя). */
    public function readCity(PageInterface $page): BrowserCity
    {

        $payload = $page->evaluate(<<<'JS'
() => {
  const regionBtn = document.querySelector(
    '[data-qa="geoSwitcher-button"], [data-qa="geoSwitcher-button-text"], ' +
    '[data-qa="mainmenu_areaSwitch"], [data-qa="geo-switcher"]'
  );
  let regionText = regionBtn ? (regionBtn.textContent || '').trim().replace(/\s+/g, ' ') : null;
  if (regionText && regionText.length > 80) {
    regionText = regionText.slice(0, 80);
  }
  return {
    url: location.href,
    host: location.hostname,
    regionText,
    title: document.title || '',
  };
}
JS);

        $url = is_array($payload) && isset($payload['url']) ? (string) $payload['url'] : $page->url();
        $host = is_array($payload) && isset($payload['host']) ? (string) $payload['host'] : '';
        $title = is_array($payload) && isset($payload['title']) ? (string) $payload['title'] : $page->title();
        $region = null;
        if (is_array($payload) && isset($payload['regionText']) && is_string($payload['regionText']) && $payload['regionText'] !== '') {
            $region = $payload['regionText'];
        }

        return new BrowserCity(
            url: $url,
            host: $host,
            subdomain: $this->subdomainFromHost($host),
            regionLabel: $region,
            title: $title,
        );
    }

    /**
     * Список чатов через chatik API (после захода на /chat с cookies сессии).
     *
     * @return list<ChatSummary>
     */
    public function listChats(PageInterface $page): array
    {
        // Не звать looksLoggedIn(): он уходит на /resumes и срывает cookies chatik.
        $page->goto(self::CHATS_URL, ['waitUntil' => 'domcontentloaded']);
        usleep(2_500_000);
        $onLogin = (bool) $page->evaluate(
            <<<'JS'
() => {
  const href = String(location.href || '');
  const text = ((document.body && document.body.innerText) || '').slice(0, 1500);
  return /account\/login/i.test(href) || /войти|вход\s+в\s+личный/i.test(text);
}
JS
        );
        if ($onLogin) {
            throw new \RuntimeException('Нужен login (browser-сессия) для чатов HH.');
        }

        $request = $page->context()->request();
        $items = [];
        /** @var array<string, array<string, mixed>> $resources */
        $resources = [];
        $nextFrom = '';
        // chatik отдаёт cursor `nextFrom` + query `from=` (не page/hasNextPage).
        for ($pageNo = 0; $pageNo < 100; $pageNo++) {
            $url = self::CHATIK_CHATS_URL;
            if ($nextFrom !== '') {
                $url .= '&from=' . rawurlencode($nextFrom);
            }
            $response = $request->get($url);
            $status = $response->status();
            $data = $response->json();
            if ($status >= 400 || !is_array($data)) {
                throw new \RuntimeException(
                    'chatik API недоступен (HTTP ' . $status . '). Проверь login / прокси.',
                );
            }
            if (!isset($data['chats']) && isset($data['error'])) {
                throw new \RuntimeException('chatik API error: ' . json_encode($data['error'], JSON_UNESCAPED_UNICODE));
            }
            $chats = is_array($data['chats'] ?? null) ? $data['chats'] : [];
            $batch = is_array($chats['items'] ?? null) ? $chats['items'] : [];
            if ($batch === []) {
                break;
            }
            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $this->mergeChatResources($resources, is_array($data['resources'] ?? null) ? $data['resources'] : []);
            $nextFrom = trim((string) ($chats['nextFrom'] ?? ''));
            if ($nextFrom === '') {
                break;
            }
        }

        $syncedAt = gmdate('c');
        $out = [];
        $position = 0;
        foreach ($items as $item) {
            $chatId = trim((string) ($item['id'] ?? ''));
            if ($chatId === '') {
                continue;
            }
            $res = is_array($item['resources'] ?? null) ? $item['resources'] : [];
            $vacancyId = '';
            if (is_array($res['VACANCY'] ?? null) && isset($res['VACANCY'][0])) {
                $vacancyId = trim((string) $res['VACANCY'][0]);
            }
            $topicId = '';
            if (is_array($res['NEGOTIATION_TOPIC'] ?? null) && isset($res['NEGOTIATION_TOPIC'][0])) {
                $topicId = trim((string) $res['NEGOTIATION_TOPIC'][0]);
            }
            [$title, $company] = $this->chatVacancyInfo($resources, $vacancyId);
            $state = $this->chatNegotiationState($resources, $topicId);
            [$resumeExtId, $resumeTitle] = $this->chatResumeInfo($resources, $res, $topicId);
            $lastMsg = is_array($item['lastMessage'] ?? null) ? $item['lastMessage'] : [];
            $preview = trim((string) ($lastMsg['text'] ?? ''));
            if (mb_strlen($preview) > 500) {
                $preview = mb_substr($preview, 0, 500);
            }

            $chat = new ChatSummary(
                chatId: $chatId,
                url: 'https://hh.ru/chat/' . rawurlencode($chatId),
                vacancyExternalId: $vacancyId,
                vacancyTitle: $title,
                companyName: $company,
                applicantState: $state,
                statusLabel: $this->chatStatusLabel($state),
                lastActivityAt: trim((string) ($item['lastActivityTime'] ?? '')),
                // В UI HH в карточке показывают время lastMessage, не lastActivityTime.
                lastMessageAt: trim((string) ($lastMsg['creationTime'] ?? '')),
                lastMessagePreview: $preview,
                unreadCount: (int) ($item['unreadCount'] ?? 0),
                // Порядок как в ответе chatik (как слева на hh.ru/chat).
                listPosition: $position,
                syncedAt: $syncedAt,
                resumeExternalId: $resumeExtId,
                resumeTitle: $resumeTitle,
            );
            // Шаблон HH «закрыли эту позицию» → отказ / бейдж «Закрыта».
            $out[] = $chat->withClosedVacancyNormalized();
            $position++;
        }

        return $out;
    }

    /**
     * Покинуть чаты через chatik /leave (нужен _xsrf из cookies после /chat).
     *
     * @param list<string> $chatIds
     * @param (callable(int, int, string): void)|null $onProgress
     *
     * @return array{left: int, failed: int, errors: list<string>}
     */
    public function leaveChats(PageInterface $page, array $chatIds, ?callable $onProgress = null): array
    {
        $ids = [];
        foreach ($chatIds as $id) {
            $id = trim((string) $id);
            if ($id !== '' && ctype_digit($id)) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return ['left' => 0, 'failed' => 0, 'errors' => []];
        }

        $page->goto(self::CHATS_URL, ['waitUntil' => 'domcontentloaded']);
        usleep(2_500_000);
        $onLogin = (bool) $page->evaluate(
            <<<'JS'
() => {
  const href = String(location.href || '');
  const text = ((document.body && document.body.innerText) || '').slice(0, 1500);
  return /account\/login/i.test(href) || /войти|вход\s+в\s+личный/i.test(text);
}
JS
        );
        if ($onLogin) {
            throw new \RuntimeException('Нужен login (browser-сессия) для чатов HH.');
        }

        $left = 0;
        $failed = 0;
        /** @var list<string> $errors */
        $errors = [];
        $total = count($ids);
        foreach ($ids as $index => $rawId) {
            // PHP numeric string keys → int; callback/API ждут string.
            $chatId = (string) $rawId;
            $n = $index + 1;
            if ($onProgress !== null) {
                $onProgress($n, $total, $chatId);
            }
            try {
                $ok = (bool) $page->evaluate(
                    <<<'JS'
async ({ chatId, leaveUrl }) => {
  const cookie = document.cookie || '';
  const m = cookie.match(/(?:^|;\s*)_xsrf=([^;]+)/);
  const xsrf = m ? decodeURIComponent(m[1]) : '';
  if (!xsrf) {
    return false;
  }
  const resp = await fetch(leaveUrl, {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Origin': location.origin,
      'X-XSRFToken': xsrf,
      'x-xsrf-token': xsrf,
    },
    body: JSON.stringify({ chatId: Number(chatId) }),
  });
  return resp.status >= 200 && resp.status < 300;
}
JS
                    ,
                    ['chatId' => $chatId, 'leaveUrl' => self::CHATIK_LEAVE_URL],
                );
                if ($ok) {
                    $left++;
                } else {
                    $failed++;
                    $errors[] = $chatId . ': leave HTTP не 2xx';
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $chatId . ': ' . $e->getMessage();
            }
            // Не долбить chatik без паузы.
            usleep(120_000);
        }

        return ['left' => $left, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * @param array<string, array<string, mixed>> $target
     * @param array<string, mixed> $source
     */
    private function mergeChatResources(array &$target, array $source): void
    {
        foreach ($source as $key => $val) {
            if (!is_string($key) || !is_array($val)) {
                continue;
            }
            if (!isset($target[$key]) || !is_array($target[$key])) {
                $target[$key] = [];
            }
            foreach ($val as $id => $row) {
                // resumes/vacancies — объекты; resumeHashById — строки hash по internal id.
                if (is_array($row) || is_string($row) || is_int($row) || is_float($row) || is_bool($row)) {
                    $target[$key][(string) $id] = $row;
                }
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $resources
     *
     * @return array{0: string, 1: string}
     */
    private function chatVacancyInfo(array $resources, string $vacancyId): array
    {
        if ($vacancyId === '') {
            return ['', ''];
        }
        $vacancies = is_array($resources['vacancies'] ?? null) ? $resources['vacancies'] : [];
        $vac = is_array($vacancies[$vacancyId] ?? null) ? $vacancies[$vacancyId] : [];
        $title = trim((string) ($vac['name'] ?? ''));
        $company = '';
        $comp = is_array($vac['company'] ?? null) ? $vac['company'] : [];
        if ($comp !== []) {
            $company = trim((string) ($comp['visibleName'] ?? $comp['name'] ?? ''));
        }

        return [$title, $company];
    }

    /**
     * @param array<string, array<string, mixed>> $resources
     */
    private function chatNegotiationState(array $resources, string $topicId): string
    {
        if ($topicId === '') {
            return '';
        }
        $topics = is_array($resources['negotiation_topics'] ?? null) ? $resources['negotiation_topics'] : [];
        $topic = is_array($topics[$topicId] ?? null) ? $topics[$topicId] : [];

        return trim((string) ($topic['currentApplicantState'] ?? ''));
    }

    /**
     * HH internal resume id → hash (external) + title из chatik resources.
     *
     * @param array<string, array<string, mixed>> $resources
     * @param array<string, mixed> $itemResources
     *
     * @return array{0: string, 1: string}
     */
    private function chatResumeInfo(array $resources, array $itemResources, string $topicId): array
    {
        $internalId = '';
        if (is_array($itemResources['RESUME'] ?? null) && isset($itemResources['RESUME'][0])) {
            $internalId = trim((string) $itemResources['RESUME'][0]);
        }
        if ($internalId === '' && $topicId !== '') {
            $topics = is_array($resources['negotiation_topics'] ?? null) ? $resources['negotiation_topics'] : [];
            $topic = is_array($topics[$topicId] ?? null) ? $topics[$topicId] : [];
            $internalId = trim((string) ($topic['resumeId'] ?? ''));
        }
        if ($internalId === '') {
            return ['', ''];
        }

        $hashById = is_array($resources['resumeHashById'] ?? null) ? $resources['resumeHashById'] : [];
        $hash = trim((string) ($hashById[$internalId] ?? $hashById[(int) $internalId] ?? ''));
        $resumes = is_array($resources['resumes'] ?? null) ? $resources['resumes'] : [];
        $resume = $resumes[$internalId] ?? $resumes[(int) $internalId] ?? null;
        $resume = is_array($resume) ? $resume : [];
        if ($hash === '') {
            $hash = trim((string) ($resume['hash'] ?? ''));
        }
        $title = trim((string) ($resume['title'] ?? ''));

        return [$hash, $title];
    }

    private function chatStatusLabel(string $state): string
    {
        return match (strtoupper($state)) {
            'RESPONSE' => 'Отклик на вакансию',
            'INVITATION' => 'Приглашение',
            'DISCARD', 'DISCARDED' => 'Отказ',
            'INTERVIEW' => 'Собеседование',
            'CONSIDER' => 'Резюме на рассмотрении',
            'PHONE_INTERVIEW' => 'Телефонное интервью',
            '' => 'Чат',
            default => $state,
        };
    }

    /**
     * Просмотры резюме с /applicant/resumeview/history (Lux historyViews).
     *
     * @return list<ResumeViewEvent>
     */
    public function listResumeViews(PageInterface $page, string $resumeHash): array
    {
        $resumeHash = trim($resumeHash);
        if ($resumeHash === '') {
            throw new \InvalidArgumentException('Resume hash is empty');
        }

        $url = self::RESUME_VIEW_HISTORY_URL . '?resumeHash=' . rawurlencode($resumeHash);
        $page->goto($url, ['waitUntil' => 'domcontentloaded']);
        usleep(3_000_000);

        $onLogin = (bool) $page->evaluate(
            <<<'JS'
() => {
  const href = String(location.href || '');
  const text = ((document.body && document.body.innerText) || '').slice(0, 1500);
  const title = document.title || '';
  if (/account\/login/i.test(href)) return true;
  if (/вход\s+в\s+личный/i.test(text)) return true;
  // Гостевой лендинг: «Войти с паролем», без «История просмотров»
  if (/Войти с/.test(text) && !/История просмотров|просмотр/i.test(title + text.slice(0, 400))) {
    return true;
  }
  return false;
}
JS
        );
        if ($onLogin) {
            throw new \RuntimeException('Нужен login (browser-сессия) для просмотров резюме HH.');
        }

        // У <template id="HH-Lux-InitialState"> textContent в Chromium пустой —
        // JSON только в outerHTML между тегами template.
        $payload = $page->evaluate(
            <<<'JS'
() => {
  const parseLux = () => {
    const html = document.documentElement.outerHTML;
    const markerDq = 'id="HH-Lux-InitialState"';
    const markerSq = "id='HH-Lux-InitialState'";
    let startTag = html.indexOf(markerDq);
    if (startTag < 0) startTag = html.indexOf(markerSq);
    if (startTag < 0) {
      // fallback: любой id=…HH-Lux-InitialState
      const m = html.match(/id=["']?HH-Lux-InitialState["']?/);
      if (m && typeof m.index === 'number') startTag = m.index;
    }
    if (startTag < 0) return null;
    const jsonStart = html.indexOf('>', startTag) + 1;
    const jsonEnd = html.indexOf('</template>', jsonStart);
    if (jsonStart <= 0 || jsonEnd <= jsonStart) return null;
    try {
      return JSON.parse(html.slice(jsonStart, jsonEnd));
    } catch (e) {
      return null;
    }
  };
  const state = parseLux();
  if (!state || typeof state !== 'object') {
    return { ok: false, error: 'lux_missing', events: [] };
  }
  const hist = state.applicantResumeViewHistory;
  if (!hist || typeof hist !== 'object') {
    return { ok: false, error: 'history_missing', events: [] };
  }
  const hv = hist.historyViews || {};
  const events = [];
  const years = Array.isArray(hv.years) ? hv.years : [];
  for (const yearBlock of years) {
    if (!yearBlock || typeof yearBlock !== 'object') continue;
    const days = Array.isArray(yearBlock.days) ? yearBlock.days : [];
    for (const dayBlock of days) {
      if (!dayBlock || typeof dayBlock !== 'object') continue;
      const companies = Array.isArray(dayBlock.companies) ? dayBlock.companies : [];
      for (const company of companies) {
        if (!company || typeof company !== 'object') continue;
        const employerId = company.id != null ? String(company.id) : '';
        const name = company.name != null ? String(company.name).trim() : '';
        const views = Array.isArray(company.views) ? company.views : [];
        for (const ts of views) {
          const n = Number(ts);
          if (!Number.isFinite(n) || n <= 0) continue;
          events.push({
            employer_id: employerId,
            company_name: name,
            viewed_at_ms: n,
          });
        }
        if (views.length === 0 && (employerId !== '' || name !== '')) {
          const y = Number(yearBlock.year);
          const m = Number(dayBlock.month);
          const d = Number(dayBlock.day);
          if (Number.isFinite(y) && Number.isFinite(m) && Number.isFinite(d)) {
            const isoDay = new Date(Date.UTC(y, m - 1, d, 12, 0, 0));
            events.push({
              employer_id: employerId,
              company_name: name,
              viewed_at_ms: isoDay.getTime(),
            });
          }
        }
      }
    }
  }
  return { ok: true, events, views_total: hv.total ?? null };
}
JS
        );

        if (!is_array($payload)) {
            throw new \RuntimeException('Не удалось прочитать историю просмотров резюме.');
        }
        if (empty($payload['ok'])) {
            $err = trim((string) ($payload['error'] ?? 'unknown'));
            throw new \RuntimeException('История просмотров недоступна (' . $err . ').');
        }

        $rawEvents = is_array($payload['events'] ?? null) ? $payload['events'] : [];
        $out = [];
        foreach ($rawEvents as $row) {
            if (!is_array($row)) {
                continue;
            }
            $employerId = trim((string) ($row['employer_id'] ?? ''));
            $name = trim((string) ($row['company_name'] ?? ''));
            $ms = (int) ($row['viewed_at_ms'] ?? 0);
            if ($employerId === '' && $name === '') {
                continue;
            }
            if ($ms <= 0) {
                continue;
            }
            $viewedAt = gmdate('c', intdiv($ms, 1000));
            $out[] = new ResumeViewEvent($employerId, $name, $viewedAt);
        }

        return $out;
    }

    private function subdomainFromHost(string $host): ?string
    {
        $host = strtolower($host);
        if (!str_ends_with($host, '.hh.ru') && $host !== 'hh.ru') {
            return null;
        }
        if ($host === 'hh.ru' || $host === 'www.hh.ru') {
            return null;
        }

        return explode('.', $host, 2)[0] ?: null;
    }
}
