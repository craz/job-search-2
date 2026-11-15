/**
 * Модалка текста резюме: открытие по клику на иконку листа, закрытие крестиком / backdrop / Escape.
 */
export class ResumeTextModal {
  private readonly texts: Record<string, { title: string; text: string }>;

  public constructor(
    private readonly root: HTMLElement,
    private readonly titleEl: HTMLElement | null,
    private readonly bodyEl: HTMLElement | null,
    private readonly emptyEl: HTMLElement | null,
    private readonly triggers: NodeListOf<Element>,
  ) {
    this.texts = this.readTexts();
  }

  public static fromDocument(): ResumeTextModal | null {
    const root = document.getElementById('resume-text-modal');
    if (!(root instanceof HTMLElement)) {
      return null;
    }

    return new ResumeTextModal(
      root,
      document.getElementById('resume-text-title'),
      document.getElementById('resume-text-body'),
      document.getElementById('resume-text-empty'),
      document.querySelectorAll('.js-resume-text'),
    );
  }

  public bind(): void {
    this.triggers.forEach((node) => {
      if (!(node instanceof HTMLElement)) {
        return;
      }
      node.addEventListener('click', (event) => {
        event.preventDefault();
        this.showFor(node);
      });
    });

    this.root.querySelectorAll('.js-close-resume-text').forEach((node) => {
      node.addEventListener('click', () => this.close());
    });

    document.addEventListener('keydown', (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !this.root.hidden) {
        this.close();
      }
    });
  }

  private showFor(trigger: HTMLElement): void {
    const id = trigger.dataset.resumeId ?? '';
    const entry = id !== '' ? this.texts[id] : undefined;

    if (this.titleEl !== null) {
      this.titleEl.textContent = entry?.title || 'Текст резюме';
    }

    if (entry !== undefined) {
      if (this.bodyEl !== null) {
        this.bodyEl.hidden = false;
        this.bodyEl.textContent = entry.text;
      }
      if (this.emptyEl !== null) {
        this.emptyEl.hidden = true;
      }
    } else {
      if (this.bodyEl !== null) {
        this.bodyEl.hidden = true;
        this.bodyEl.textContent = '';
      }
      if (this.emptyEl !== null) {
        this.emptyEl.hidden = false;
      }
    }

    this.root.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  private close(): void {
    this.root.hidden = true;
    document.body.style.overflow = '';
  }

  private readTexts(): Record<string, { title: string; text: string }> {
    const node = document.getElementById('resume-texts-data');
    if (!(node instanceof HTMLScriptElement) || node.textContent === null) {
      return {};
    }
    try {
      const parsed: unknown = JSON.parse(node.textContent);
      if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
        return {};
      }
      return parsed as Record<string, { title: string; text: string }>;
    } catch {
      return {};
    }
  }
}
