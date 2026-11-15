/** Кнопки ручного вердикта (Skip / В Смотрим) → JSON set_verdict, потом reload. */
export class ManualVerdictAction {
  public static fromDocument(): ManualVerdictAction | null {
    const buttons = document.querySelectorAll('.js-vacancy-set-verdict');
    if (buttons.length === 0) {
      return null;
    }

    return new ManualVerdictAction(buttons);
  }

  private constructor(private readonly buttons: NodeListOf<Element>) {}

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
  }

  private async run(button: HTMLButtonElement): Promise<void> {
    const vacancy = (button.dataset.vacancyId ?? '').trim();
    const verdict = (button.dataset.verdict ?? '').trim().toLowerCase();
    if (vacancy === '' || verdict === '') {
      this.setStatus(button, 'Нет vacancy/verdict.', true);
      return;
    }

    const label = button.textContent ?? 'Skip';
    button.disabled = true;
    button.textContent = 'Сохраняю…';
    this.setStatus(button, 'Пишу вердикт…', false);

    try {
      const params = new URLSearchParams({
        job: 'set_verdict',
        format: 'json',
        vacancy,
        verdict,
      });
      const response = await fetch(`/?${params.toString()}`, {
        signal: AbortSignal.timeout(30_000),
      });
      const data = (await response.json()) as { ok?: boolean; message?: string };
      const ok = Boolean(data.ok);
      const message =
        typeof data.message === 'string' && data.message !== ''
          ? data.message
          : ok
            ? 'Готово.'
            : 'Не удалось сохранить.';
      this.setStatus(button, message, !ok);
      if (!ok) {
        button.disabled = false;
        button.textContent = label;
        return;
      }

      button.textContent = 'Обновляю…';
      const url = new URL(window.location.href);
      url.searchParams.set('id', vacancy);
      url.searchParams.set('flash', message);
      url.searchParams.set('ok', '1');
      url.searchParams.delete('watch');
      if (verdict === 'skip') {
        url.searchParams.set('filter', 'skip');
      } else if (verdict === 'maybe' || verdict === 'apply' || verdict === 'consider') {
        url.searchParams.set('filter', 'consider');
      }
      window.location.assign(url.toString());
    } catch (error) {
      const errMessage = error instanceof Error ? error.message : 'Сетевая ошибка';
      this.setStatus(button, errMessage, true);
      button.disabled = false;
      button.textContent = label;
    }
  }

  private setStatus(button: HTMLButtonElement, text: string, isError: boolean): void {
    const root =
      button.closest('.vacancy-detail-actions')?.parentElement ?? button.parentElement;
    if (!(root instanceof HTMLElement)) {
      return;
    }
    let status = root.querySelector('.js-vacancy-score-status');
    if (!(status instanceof HTMLElement)) {
      status = document.createElement('p');
      status.className = 'vacancy-verdict-status muted js-vacancy-score-status';
      status.setAttribute('role', 'status');
      button.closest('.vacancy-detail-actions')?.insertAdjacentElement('afterend', status)
        ?? root.appendChild(status);
    }
    status.hidden = false;
    status.textContent = text;
    status.classList.toggle('is-err', isError);
    status.classList.toggle('is-ok', !isError);
  }
}
