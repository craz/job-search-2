import { ScoringProgressBar } from './ScoringProgressBar';

/** Кнопки «Оценить N» у резюме → DevAI + полоска у этой строки. */
export class ScoreTenActions {
  public static fromDocument(
    progress: ScoringProgressBar | null = null,
  ): ScoreTenActions | null {
    const buttons = document.querySelectorAll('.js-score-ten');
    if (buttons.length === 0 && progress === null) {
      return null;
    }

    return new ScoreTenActions(buttons, progress);
  }

  private toast: HTMLElement | null = null;

  private constructor(
    private readonly buttons: NodeListOf<Element>,
    private readonly progress: ScoringProgressBar | null,
  ) {}

  public bind(): void {
    for (const el of this.buttons) {
      if (!(el instanceof HTMLButtonElement)) {
        continue;
      }
      el.addEventListener('click', (event) => {
        event.preventDefault();
        void this.run(el);
      });
    }

    this.progress?.watchPending();
  }

  private async run(button: HTMLButtonElement): Promise<void> {
    const resume = (button.dataset.resumeId ?? '').trim();
    const label = button.textContent ?? 'Оценить';
    button.disabled = true;
    button.textContent = 'Отправляю…';

    try {
      const q = resume !== '' ? `&resume=${encodeURIComponent(resume)}` : '';
      const response = await fetch(`/?job=score_ten&format=json${q}`);
      const data = (await response.json()) as {
        ok?: boolean;
        enqueued?: number;
        message?: string;
      };
      const ok = Boolean(data.ok);
      const message =
        typeof data.message === 'string' && data.message !== ''
          ? data.message
          : ok
            ? 'Отправил вакансии на оценку DevAI.'
            : 'Не удалось отправить на оценку.';
      this.showToast(message, ok);

      if (ok && resume !== '' && this.progress !== null) {
        this.progress.watch(resume);
      }

      if (ok && resume !== '') {
        button.remove();
        return;
      }
    } catch (error) {
      const errMessage = error instanceof Error ? error.message : 'Сетевая ошибка';
      this.showToast(errMessage, false);
    } finally {
      if (button.isConnected) {
        button.disabled = false;
        button.textContent = label;
      }
    }
  }

  private showToast(message: string, ok: boolean): void {
    if (this.toast === null) {
      const panel = document.querySelector('.panel .panel-head');
      this.toast = document.createElement('p');
      this.toast.className = 'score-toast';
      this.toast.setAttribute('role', 'status');
      if (panel?.parentElement) {
        panel.insertAdjacentElement('afterend', this.toast);
      } else {
        document.body.prepend(this.toast);
      }
    }

    this.toast.textContent = message;
    this.toast.classList.toggle('is-ok', ok);
    this.toast.classList.toggle('is-err', !ok);
    this.toast.hidden = false;
  }
}
