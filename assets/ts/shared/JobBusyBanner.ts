import { JobApi, type JobStatusResponse } from './JobApi';

/**
 * Заметная плашка: UI-job уже бежит — не жми «Откликнуться» / «Качать».
 * Поллит /?job=status (лёгкий JSON).
 */
export class JobBusyBanner {
  private readonly api = new JobApi();
  private readonly root: HTMLElement;
  private readonly titleEl: HTMLElement;
  private readonly detailEl: HTMLElement;
  private timer: number | null = null;
  private busy = false;

  public static boot(intervalMs = 4000): JobBusyBanner {
    const banner = new JobBusyBanner();
    banner.start(intervalMs);
    return banner;
  }

  private constructor() {
    const existing = document.querySelector('.js-job-busy-banner');
    const el =
      existing instanceof HTMLElement
        ? existing
        : document.createElement('div');
    if (!(existing instanceof HTMLElement)) {
      el.className = 'job-busy-banner js-job-busy-banner';
      el.hidden = true;
      el.setAttribute('role', 'status');
      el.innerHTML =
        '<strong class="job-busy-banner-title"></strong>' +
        '<span class="job-busy-banner-detail"></span>';
      const topbar = document.querySelector('.topbar');
      if (topbar?.parentElement) {
        topbar.insertAdjacentElement('afterend', el);
      } else {
        document.body.prepend(el);
      }
    }
    this.root = el;
    const title = el.querySelector('.job-busy-banner-title');
    const detail = el.querySelector('.job-busy-banner-detail');
    this.titleEl =
      title instanceof HTMLElement
        ? title
        : (() => {
            const t = document.createElement('strong');
            t.className = 'job-busy-banner-title';
            el.appendChild(t);
            return t;
          })();
    this.detailEl =
      detail instanceof HTMLElement
        ? detail
        : (() => {
            const d = document.createElement('span');
            d.className = 'job-busy-banner-detail';
            el.appendChild(d);
            return d;
          })();
  }

  public start(intervalMs: number): void {
    void this.tick();
    this.timer = window.setInterval(() => {
      void this.tick();
    }, intervalMs);
  }

  private async tick(): Promise<void> {
    try {
      const data = await this.api.status();
      this.apply(data);
    } catch {
      // сеть/прокси — тихо, следующий poll
    }
  }

  private apply(data: JobStatusResponse & { busy?: boolean }): void {
    const running =
      data.busy === true ||
      String(data.state ?? '')
        .trim()
        .toLowerCase() === 'running';
    if (!running) {
      this.setBusy(false, '');
      return;
    }
    const message = (data.message ?? '').trim() || 'фоновое задание';
    this.setBusy(true, message);
  }

  private setBusy(next: boolean, message: string): void {
    this.busy = next;
    document.body.classList.toggle('is-job-busy', next);
    this.root.hidden = !next;
    if (!next) {
      this.titleEl.textContent = '';
      this.detailEl.textContent = '';
      return;
    }
    this.titleEl.textContent =
      'Идёт задание — не жми «Откликнуться» / «Качать» / «Обновить».';
    this.detailEl.textContent = message !== '' ? ' Сейчас: ' + message : '';
  }
}
