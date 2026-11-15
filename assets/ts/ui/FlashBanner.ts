/**
 * Тост с ?flash=… .
 * ok — скрыть через N с; err (ошибки отклика и т.п.) — пока сам не закроешь.
 */
export class FlashBanner {
  public static fromDocument(hideAfterMs = 12_000): FlashBanner | null {
    const el = document.querySelector('.js-flash');
    if (!(el instanceof HTMLElement)) {
      return null;
    }
    const isError = el.classList.contains('err');
    return new FlashBanner(el, isError ? 0 : hideAfterMs, isError);
  }

  private constructor(
    private readonly el: HTMLElement,
    private readonly hideAfterMs: number,
    private readonly persistent: boolean,
  ) {}

  public bind(): void {
    if (this.persistent) {
      this.ensureDismiss();
      return;
    }
    window.setTimeout(() => {
      this.hide();
    }, this.hideAfterMs);
  }

  private ensureDismiss(): void {
    if (this.el.querySelector('.js-flash-dismiss') !== null) {
      return;
    }
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'flash-dismiss js-flash-dismiss';
    btn.setAttribute('aria-label', 'Закрыть');
    btn.textContent = '×';
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      this.hide();
    });
    this.el.appendChild(btn);
  }

  private hide(): void {
    this.el.classList.add('is-hiding');
    window.setTimeout(() => {
      this.el.remove();
      this.stripFlashFromUrl();
    }, 320);
  }

  private stripFlashFromUrl(): void {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('flash') && !url.searchParams.has('ok')) {
      return;
    }
    url.searchParams.delete('flash');
    url.searchParams.delete('ok');
    const next =
      url.pathname +
      (url.searchParams.toString() !== '' ? `?${url.searchParams}` : '') +
      url.hash;
    window.history.replaceState({}, '', next);
  }
}
