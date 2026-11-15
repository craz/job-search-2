type VerdictResponse = {
  ok?: boolean;
  vacancy_id?: string;
  verdict?: string;
  reason?: string;
  action?: string;
  pending?: boolean;
  message?: string;
};

/**
 * Ждёт вердикт DevAI по выбранной вакансии и сама перезагружает страницу.
 */
export class VerdictPoll {
  public static fromDocument(): VerdictPoll | null {
    const root = document.querySelector('.js-vacancy-verdict-poll');
    if (!(root instanceof HTMLElement)) {
      return null;
    }
    const vacancyId = (root.dataset.vacancyId ?? '').trim();
    if (vacancyId === '') {
      return null;
    }
    const pending = root.dataset.pending === '1';
    const watch = root.dataset.watch === '1' || pending;
    if (!watch) {
      return null;
    }

    return new VerdictPoll(root, vacancyId);
  }

  private timerId = 0;
  private readonly startedAt = Date.now();
  private readonly maxMs = 180_000;
  private readonly statusEl: HTMLElement | null;

  private constructor(
    private readonly root: HTMLElement,
    private readonly vacancyId: string,
  ) {
    this.statusEl = this.root.querySelector('.js-vacancy-verdict-status');
  }

  public start(): void {
    this.setStatus('Жду оценку DevAI…');
    void this.tick();
    this.timerId = window.setInterval(() => {
      void this.tick();
    }, 2500);
  }

  private async tick(): Promise<void> {
    if (Date.now() - this.startedAt > this.maxMs) {
      this.stop();
      this.setStatus('DevAI ещё не ответил за 3 мин — нажми «Оценить» снова или обнови позже.');
      return;
    }

    try {
      const response = await fetch(
        `/?job=vacancy_verdict&format=json&vacancy=${encodeURIComponent(this.vacancyId)}`,
      );
      const data = (await response.json()) as VerdictResponse;
      if (!data.ok) {
        this.setStatus(data.message ?? 'Не удалось проверить вердикт.');
        return;
      }
      if (data.pending === false && (data.verdict ?? '').trim() !== '') {
        this.stop();
        this.setStatus('Оценка готова — обновляю…');
        const url = new URL(window.location.href);
        url.searchParams.delete('flash');
        url.searchParams.delete('ok');
        url.searchParams.delete('score');
        url.searchParams.delete('watch');
        window.location.replace(url.pathname + (url.searchParams.toString() !== '' ? `?${url.searchParams}` : ''));
        return;
      }
      this.setStatus('Оцениваю в DevAI…');
    } catch {
      this.setStatus('Сеть/JSON при опросе вердикта…');
    }
  }

  private stop(): void {
    if (this.timerId !== 0) {
      window.clearInterval(this.timerId);
      this.timerId = 0;
    }
  }

  private setStatus(text: string): void {
    if (this.statusEl !== null) {
      this.statusEl.hidden = false;
      this.statusEl.textContent = text;
    }
  }
}
