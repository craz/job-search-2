type VacancyItem = {
  external_id: string;
  title: string;
  url: string;
  company_name: string;
  description: string;
  key_skills: string[];
  salary_text: string;
  area_text: string;
  outcome: string;
  verdict: string;
  reason: string;
  action: string;
  error: string;
};

type VacanciesResponse = {
  ok?: boolean;
  message?: string;
  resume_id?: string;
  search_run_id?: string | null;
  count?: number;
  items?: VacancyItem[];
};

type VerdictTone = 'skip' | 'consider' | '';

/**
 * Модалка списка вакансий: данные с Core (`/?job=vacancies_for_resume`).
 * Цвет: skip — розовый (не подходит); maybe/apply — светло-зелёный (рассматриваем).
 */
export class VacanciesModal {
  private currentItems: VacancyItem[] = [];
  private requestToken = 0;

  public constructor(
    private readonly root: HTMLElement,
    private readonly titleEl: HTMLElement | null,
    private readonly listEl: HTMLElement | null,
    private readonly emptyEl: HTMLElement | null,
    private readonly detailEmptyEl: HTMLElement | null,
    private readonly detailBodyEl: HTMLElement | null,
    private readonly detailTitleEl: HTMLElement | null,
    private readonly detailVerdictEl: HTMLElement | null,
    private readonly detailMetaEl: HTMLElement | null,
    private readonly detailLinkEl: HTMLAnchorElement | null,
    private readonly detailSkillsEl: HTMLElement | null,
    private readonly detailDescriptionEl: HTMLElement | null,
    private readonly triggers: NodeListOf<Element>,
  ) {}

  public static fromDocument(): VacanciesModal | null {
    const root = document.getElementById('vacancies-modal');
    if (!(root instanceof HTMLElement)) {
      return null;
    }

    const link = document.getElementById('vacancy-detail-link');

    return new VacanciesModal(
      root,
      document.getElementById('vacancies-title'),
      document.getElementById('vacancies-list'),
      document.getElementById('vacancies-empty'),
      document.getElementById('vacancy-detail-empty'),
      document.getElementById('vacancy-detail-body'),
      document.getElementById('vacancy-detail-title'),
      document.getElementById('vacancy-detail-verdict'),
      document.getElementById('vacancy-detail-meta'),
      link instanceof HTMLAnchorElement ? link : null,
      document.getElementById('vacancy-detail-skills'),
      document.getElementById('vacancy-detail-description'),
      document.querySelectorAll('.js-resume-vacancies'),
    );
  }

  public bind(): void {
    this.triggers.forEach((node) => {
      if (!(node instanceof HTMLElement)) {
        return;
      }
      node.addEventListener('click', (event) => {
        event.preventDefault();
        void this.showFor(node);
      });
    });

    this.root.querySelectorAll('.js-close-vacancies').forEach((node) => {
      node.addEventListener('click', () => this.close());
    });

    document.addEventListener('keydown', (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !this.root.hidden) {
        this.close();
      }
    });
  }

  private async showFor(trigger: HTMLElement): Promise<void> {
    const id = trigger.dataset.resumeId ?? '';
    const resumeTitle = trigger.dataset.resumeTitle ?? '';
    const token = ++this.requestToken;

    if (this.titleEl !== null) {
      this.titleEl.textContent =
        resumeTitle !== '' ? `Вакансии — ${resumeTitle}` : 'Скачанные вакансии';
    }

    this.clearDetail();
    this.currentItems = [];
    if (this.listEl !== null) {
      this.listEl.hidden = false;
      this.listEl.replaceChildren();
      const loading = document.createElement('li');
      loading.className = 'muted';
      loading.textContent = 'Загрузка из Core…';
      this.listEl.appendChild(loading);
    }
    if (this.emptyEl !== null) {
      this.emptyEl.hidden = true;
    }

    this.root.hidden = false;
    document.body.style.overflow = 'hidden';

    if (id === '') {
      this.showEmpty('Не указано резюме.');
      return;
    }

    try {
      const response = await fetch(
        `/?job=vacancies_for_resume&format=json&resume=${encodeURIComponent(id)}`,
      );
      const data = (await response.json()) as VacanciesResponse;
      if (token !== this.requestToken) {
        return;
      }
      if (!data.ok) {
        this.showEmpty(data.message ?? 'Не удалось загрузить вакансии из Core.');
        return;
      }
      const items = Array.isArray(data.items) ? data.items : [];
      if (items.length === 0) {
        this.showEmpty('Для этого резюме ещё нет скачанных вакансий в Core.');
        return;
      }
      this.renderItems(items);
    } catch (error) {
      if (token !== this.requestToken) {
        return;
      }
      const detail = error instanceof Error ? error.message : String(error);
      this.showEmpty(`Ошибка загрузки: ${detail}`);
    }
  }

  private renderItems(items: VacancyItem[]): void {
    this.currentItems = items;
    if (this.emptyEl !== null) {
      this.emptyEl.hidden = true;
    }
    if (this.listEl !== null) {
      this.listEl.hidden = false;
      this.listEl.replaceChildren();
      items.forEach((item, index) => {
        const li = document.createElement('li');
        const btn = document.createElement('button');
        btn.type = 'button';
        const tone = this.verdictTone(item.verdict);
        btn.className =
          'vacancies-list-item' +
          (tone === 'skip' ? ' is-skip' : tone === 'consider' ? ' is-consider' : '');
        btn.dataset.index = String(index);
        const company = item.company_name !== '' ? item.company_name : 'Без компании';
        btn.innerHTML =
          '<span class="vacancies-list-title"></span>' +
          '<span class="vacancies-list-company"></span>' +
          '<span class="vacancies-list-verdict" hidden></span>';
        const titleNode = btn.querySelector('.vacancies-list-title');
        const companyNode = btn.querySelector('.vacancies-list-company');
        const verdictNode = btn.querySelector('.vacancies-list-verdict');
        if (titleNode !== null) {
          titleNode.textContent = item.title !== '' ? item.title : 'Без названия';
        }
        if (companyNode !== null) {
          companyNode.textContent = company;
        }
        if (verdictNode instanceof HTMLElement) {
          const label = this.verdictLabel(item);
          if (label !== '') {
            const badgeClass =
              label === 'skip'
                ? 'is-skip'
                : label === 'apply'
                  ? 'is-apply'
                  : label === 'maybe'
                    ? 'is-maybe'
                    : 'is-pending';
            verdictNode.hidden = false;
            verdictNode.innerHTML =
              `<span class="vacancy-verdict-badge ${badgeClass}">${this.escapeHtml(label)}</span>`;
          }
        }
        btn.addEventListener('click', () => this.showDetail(index));
        li.appendChild(btn);
        this.listEl?.appendChild(li);
      });
    }
    this.showDetail(0);
  }

  private showEmpty(message: string): void {
    if (this.listEl !== null) {
      this.listEl.replaceChildren();
      this.listEl.hidden = true;
    }
    if (this.emptyEl !== null) {
      this.emptyEl.hidden = false;
      this.emptyEl.textContent = message;
    }
    this.clearDetail();
  }

  private showDetail(index: number): void {
    const item = this.currentItems[index];
    if (item === undefined) {
      this.clearDetail();
      return;
    }

    this.listEl?.querySelectorAll('.vacancies-list-item').forEach((node, i) => {
      node.classList.toggle('is-active', i === index);
    });

    if (this.detailEmptyEl !== null) {
      this.detailEmptyEl.hidden = true;
    }
    if (this.detailBodyEl !== null) {
      this.detailBodyEl.hidden = false;
    }
    if (this.detailTitleEl !== null) {
      this.detailTitleEl.textContent = item.title !== '' ? item.title : 'Без названия';
    }

    if (this.detailVerdictEl !== null) {
      const tone = this.verdictTone(item.verdict);
      const label = this.verdictLabel(item);
      const badgeClass =
        label === 'skip'
          ? 'is-skip'
          : label === 'apply'
            ? 'is-apply'
            : label === 'maybe'
              ? 'is-maybe'
              : 'is-pending';
      this.detailVerdictEl.classList.remove('is-skip', 'is-consider');
      if (label === '') {
        this.detailVerdictEl.hidden = true;
        this.detailVerdictEl.textContent = '';
      } else {
        this.detailVerdictEl.hidden = false;
        const reason =
          item.reason !== ''
            ? ` <span class="vacancy-verdict-reason">${this.escapeHtml(item.reason)}</span>`
            : '';
        this.detailVerdictEl.innerHTML =
          `<span class="vacancy-verdict-badge ${badgeClass}">${this.escapeHtml(label)}</span>` +
          reason;
        if (tone === 'skip') {
          this.detailVerdictEl.classList.add('is-skip');
        } else if (tone === 'consider') {
          this.detailVerdictEl.classList.add('is-consider');
        }
      }
    }

    const metaParts: string[] = [];
    if (item.company_name !== '') {
      metaParts.push(item.company_name);
    }
    if (item.salary_text !== '') {
      metaParts.push(item.salary_text);
    }
    if (item.area_text !== '') {
      metaParts.push(item.area_text);
    }
    if (this.detailMetaEl !== null) {
      this.detailMetaEl.textContent = metaParts.join(' · ');
    }

    if (this.detailLinkEl !== null) {
      this.detailLinkEl.href = item.url !== '' ? item.url : '#';
      this.detailLinkEl.hidden = item.url === '';
    }

    const skills = Array.isArray(item.key_skills) ? item.key_skills : [];
    if (this.detailSkillsEl !== null) {
      this.detailSkillsEl.replaceChildren();
      if (skills.length === 0) {
        this.detailSkillsEl.hidden = true;
      } else {
        this.detailSkillsEl.hidden = false;
        const label = document.createElement('div');
        label.className = 'vacancy-skills-label';
        label.textContent = 'Ключевые навыки';
        this.detailSkillsEl.appendChild(label);
        const list = document.createElement('ul');
        list.className = 'vacancy-skills-list';
        for (const skill of skills) {
          const li = document.createElement('li');
          li.textContent = skill;
          list.appendChild(li);
        }
        this.detailSkillsEl.appendChild(list);
      }
    }

    if (this.detailDescriptionEl !== null) {
      const text =
        item.description !== ''
          ? this.formatDescription(item.description)
          : item.error !== ''
            ? `Ошибка: ${item.error}`
            : 'Описание пустое в Core.';
      this.detailDescriptionEl.textContent = text;
    }
  }

  private verdictTone(verdict: string): VerdictTone {
    const v = (verdict ?? '').trim().toLowerCase();
    if (v === 'skip' || v === 'reject' || v === 'no') {
      return 'skip';
    }
    if (v === 'maybe' || v === 'apply' || v === 'consider' || v === 'ok' || v === 'yes') {
      return 'consider';
    }
    return '';
  }

  private verdictLabel(item: VacancyItem): string {
    const v = (item.verdict ?? '').trim().toLowerCase();
    if (v === 'skip' || v === 'reject' || v === 'no') {
      return 'skip';
    }
    if (v === 'maybe' || v === 'consider') {
      return 'maybe';
    }
    if (v === 'apply' || v === 'ok' || v === 'yes') {
      return 'apply';
    }
    if (v !== '') {
      return v;
    }
    return 'без оценки';
  }

  /** Core иногда схлопывает \\n — вернуть читаемые абзацы по заголовкам секций. */
  private formatDescription(raw: string): string {
    let text = raw.replace(/\u00a0/g, ' ').replace(/[ \t]+\n/g, '\n').trim();
    if (!text.includes('\n')) {
      text = text
        .replace(
          /\s+((?:Ваши задачи|Мы жд[её]м(?:\s+от\s+вас)?|Обязанности|Требования|Будет преимуществом|Условия|Ключевые навыки)\s*:)/gi,
          '\n\n$1',
        )
        .replace(/\s+([;—]\s*)/g, '\n$1')
        .replace(/\s+(\d+\.\s+)/g, '\n$1');
    }
    return text.replace(/\n{3,}/g, '\n\n');
  }

  private clearDetail(): void {
    if (this.detailEmptyEl !== null) {
      this.detailEmptyEl.hidden = false;
    }
    if (this.detailBodyEl !== null) {
      this.detailBodyEl.hidden = true;
    }
    if (this.detailVerdictEl !== null) {
      this.detailVerdictEl.hidden = true;
      this.detailVerdictEl.textContent = '';
      this.detailVerdictEl.classList.remove('is-skip', 'is-consider');
    }
    if (this.detailSkillsEl !== null) {
      this.detailSkillsEl.hidden = true;
      this.detailSkillsEl.replaceChildren();
    }
  }

  private escapeHtml(value: string): string {
    return value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  private close(): void {
    this.root.hidden = true;
    document.body.style.overflow = '';
  }
}
