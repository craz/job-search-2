/** Кнопка «Переоценить» / «Оценить» на карточке вакансии → JSON score_one, без пустого reload. */
export class RescoreAction {
  public static fromDocument(): RescoreAction | null {
    const buttons = document.querySelectorAll('.js-vacancy-score-one');
    if (buttons.length === 0) {
      return null;
    }

    return new RescoreAction(buttons);
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
    const resume = (button.dataset.resumeId ?? '').trim();
    const vacancy = (button.dataset.vacancyId ?? '').trim();
    const force = button.dataset.force === '1';
    if (resume === '' || vacancy === '') {
      this.setStatus(button, 'Нет resume/vacancy.', true);
      return;
    }

    const label = button.textContent ?? (force ? 'Переоценить' : 'Оценить');
    button.disabled = true;
    button.textContent = force ? 'Переоцениваю…' : 'Оцениваю…';
    this.setStatus(button, force ? 'Переоцениваю через DevAI — подожди…' : 'Оцениваю через DevAI — подожди…', false);

    try {
      const params = new URLSearchParams({
        job: 'score_one',
        format: 'json',
        resume,
        vacancy,
      });
      if (force) {
        params.set('force', '1');
      }
      const response = await fetch(`/?${params.toString()}`, {
        signal: AbortSignal.timeout(180_000),
      });
      const data = (await response.json()) as {
        ok?: boolean;
        message?: string;
        raw?: { results?: Array<{ verdict?: string }> };
      };
      const ok = Boolean(data.ok);
      const message =
        typeof data.message === 'string' && data.message !== ''
          ? data.message
          : ok
            ? 'Готово.'
            : 'Не удалось оценить.';
      this.setStatus(button, message, !ok);
      if (!ok) {
        button.disabled = false;
        button.textContent = label;
        return;
      }

      button.textContent = 'Обновляю…';
      const url = new URL(window.location.href);
      url.searchParams.delete('rescore');
      url.searchParams.delete('score');
      url.searchParams.set('id', vacancy);
      url.searchParams.set('flash', message);
      url.searchParams.set('ok', '1');
      url.searchParams.set('watch', '1');
      window.location.assign(url.toString());
    } catch (error) {
      const errMessage = error instanceof Error ? error.message : 'Сетевая ошибка';
      this.setStatus(button, errMessage, true);
      button.disabled = false;
      button.textContent = label;
    }
  }

  private setStatus(button: HTMLButtonElement, text: string, isError: boolean): void {
    const root = button.closest('.vacancy-detail-actions')?.parentElement
      ?? button.parentElement;
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
