type ScoreProgressResponse = {
  ok?: boolean;
  resume_id?: string;
  total?: number;
  done?: number;
  waiting?: number;
  skip?: number;
  consider?: number;
  unscored?: number;
  pct?: number;
  active?: boolean;
  message?: string;
};

/**
 * Полоски DevAI у каждого резюме (не одна общая).
 */
export class ScoringProgressBar {
  private readonly timers = new Map<string, number>();

  public static fromDocument(): ScoringProgressBar {
    return new ScoringProgressBar();
  }

  /** Старт поллинга для резюме с «без оценки» / после «Оценить». */
  public watch(resumeId: string): void {
    const id = resumeId.trim();
    if (id === '') {
      return;
    }
    const root = this.rootFor(id);
    if (root === null) {
      return;
    }
    root.hidden = false;
    void this.tick(id);
    this.startTimer(id);
  }

  /** Все резюме, у которых на странице есть «Оценить» или уже видна полоска. */
  public watchPending(): void {
    document.querySelectorAll('.js-score-ten[data-resume-id]').forEach((node) => {
      if (!(node instanceof HTMLElement)) {
        return;
      }
      const id = (node.dataset.resumeId ?? '').trim();
      if (id !== '') {
        this.watch(id);
      }
    });
    document.querySelectorAll('.js-resume-scoring[data-resume-id]').forEach((node) => {
      if (!(node instanceof HTMLElement) || node.hidden) {
        return;
      }
      const id = (node.dataset.resumeId ?? '').trim();
      if (id !== '' && node.dataset.watchOnLoad === '1') {
        this.watch(id);
      }
    });
  }

  public stop(resumeId: string): void {
    const timer = this.timers.get(resumeId);
    if (timer !== undefined) {
      window.clearInterval(timer);
      this.timers.delete(resumeId);
    }
  }

  private startTimer(resumeId: string): void {
    this.stop(resumeId);
    this.timers.set(
      resumeId,
      window.setInterval(() => {
        void this.tick(resumeId);
      }, 2500),
    );
  }

  private rootFor(resumeId: string): HTMLElement | null {
    const node = document.querySelector(
      `.js-resume-scoring[data-resume-id="${CSS.escape(resumeId)}"]`,
    );
    return node instanceof HTMLElement ? node : null;
  }

  private async tick(resumeId: string): Promise<void> {
    const root = this.rootFor(resumeId);
    if (root === null) {
      this.stop(resumeId);
      return;
    }
    try {
      const response = await fetch(
        `/?job=score_progress&format=json&resume=${encodeURIComponent(resumeId)}`,
      );
      const data = (await response.json()) as ScoreProgressResponse;
      this.render(root, data);
      if (data.ok && data.active === false) {
        this.stop(resumeId);
      }
    } catch {
      this.setText(root, 'Не удалось узнать прогресс DevAI…');
    }
  }

  private render(root: HTMLElement, data: ScoreProgressResponse): void {
    const pct = typeof data.pct === 'number' ? data.pct : 0;
    const message =
      typeof data.message === 'string' && data.message !== ''
        ? data.message
        : 'Оценка DevAI…';
    const fill = root.querySelector('.scoring-progress-fill');
    const label = root.querySelector('.scoring-progress-label');
    if (fill instanceof HTMLElement) {
      fill.style.width = `${Math.max(0, Math.min(100, pct))}%`;
    }
    if (label instanceof HTMLElement) {
      label.textContent = message;
    }
    root.classList.toggle('is-done', data.active === false && (data.total ?? 0) > 0);
    root.classList.toggle('is-active', data.active === true);
    root.hidden = false;

    // Обновить строку «Пачка: …» рядом, если есть.
    const batch = root.parentElement?.querySelector('.resume-progress-batch');
    if (batch instanceof HTMLElement && typeof data.skip === 'number') {
      batch.innerHTML =
        'Пачка: <span class="is-skip">' +
        String(data.skip) +
        ' skip</span> · <span class="is-consider">' +
        String(data.consider ?? 0) +
        ' смотрим</span> · <span class="is-wait">' +
        String(data.unscored ?? 0) +
        ' без оценки</span>';
    }
  }

  private setText(root: HTMLElement, text: string): void {
    const label = root.querySelector('.scoring-progress-label');
    if (label instanceof HTMLElement) {
      label.textContent = text;
    }
    root.hidden = false;
  }
}
