import { JobApi, type JobKind, type JobStatusResponse } from '../shared/JobApi';

/**
 * Экран ожидания: job уже стартовал в PHP; здесь poll статуса и редирект домой.
 * Секунды дублирует inline-скрипт в HTML (на случай если бандл не выполнился).
 */
export class BusyPage {
  private readonly api = new JobApi();
  private readonly startedAt = Date.now();
  private readonly maxMs: number;
  private sawRunning = false;

  public constructor(
    private readonly job: JobKind,
    private readonly statusEl: HTMLElement | null,
    private readonly errorEl: HTMLElement | null,
    private readonly homeEl: HTMLElement | null,
  ) {
    this.maxMs =
      job === 'vacancies' ||
      job === 'resume_views' ||
      job === 'apply' ||
      job === 'apply_consider' ||
      job === 'apply_errors' ||
      job === 'rehydrate_bot_wall' ||
      job === 'leave_rejections' ||
      job === 'leave_old'
        ? 1_200_000
        : 180_000;
  }

  public static fromDocument(): BusyPage {
    const raw = document.body.dataset.busyJob ?? 'load';
    const job: JobKind =
      raw === 'refresh' ||
      raw === 'set_city' ||
      raw === 'load' ||
      raw === 'vacancies' ||
      raw === 'apply' ||
      raw === 'apply_consider' ||
      raw === 'apply_errors' ||
      raw === 'rehydrate_bot_wall' ||
      raw === 'chats' ||
      raw === 'leave_rejections' ||
      raw === 'leave_old' ||
      raw === 'resume_views'
        ? raw
        : 'load';

    return new BusyPage(
      job,
      document.getElementById('busy-status'),
      document.getElementById('busy-error'),
      document.getElementById('busy-home'),
    );
  }

  public async run(): Promise<void> {
    this.setStatus('Связываемся со статусом…');
    try {
      const current = await this.api.status();
      if (current.state === 'running' || current.state === 'queued') {
        this.sawRunning = true;
        this.setStatus(current.message ?? 'В фоне…');
      } else if (current.state === 'done') {
        // Возможно ещё не перезаписали статус новым job — подождём poll.
        this.setStatus('Ожидаем новый статус…');
      } else {
        await this.startJob();
      }
    } catch (error) {
      try {
        await this.startJob();
      } catch (startError) {
        const detail =
          startError instanceof Error ? startError.message : String(startError);
        this.fail(`Не удалось стартовать: ${detail}`);
        return;
      }
    }

    window.setTimeout(() => {
      void this.poll();
    }, 300);
  }

  private async startJob(): Promise<void> {
    const params = new URLSearchParams(window.location.search);
    let city = '';
    let resume = '';
    let vacancy = '';
    let scope = '';
    if (this.job === 'set_city') {
      city = params.get('city') ?? '';
      if (city === '') {
        this.fail('Не указан город.');
        throw new Error('city');
      }
    }
    if (this.job === 'vacancies') {
      resume = params.get('resume') ?? '';
    }
    if (this.job === 'apply') {
      resume = params.get('resume') ?? '';
      vacancy = params.get('vacancy') ?? '';
      if (resume === '' || vacancy === '') {
        this.fail('Не указаны resume или vacancy.');
        throw new Error('apply');
      }
    }
    if (this.job === 'apply_consider') {
      resume = params.get('resume') ?? '';
      scope = params.get('scope') ?? '';
      if (resume === '') {
        this.fail('Не указано resume.');
        throw new Error('apply_consider');
      }
    }

    const data = await this.api.start(this.job, city, resume, vacancy, scope);
    if (!data.ok) {
      this.fail(data.message ?? 'Не удалось запустить задание.');
      throw new Error('start');
    }
    this.sawRunning = true;
    this.setStatus(data.message ?? 'В фоне…');
  }

  private async poll(): Promise<void> {
    if (Date.now() - this.startedAt > this.maxMs) {
      await this.onWaitTimeout();
      return;
    }

    try {
      const data = await this.api.status();
      this.applyStatus(data);
    } catch {
      window.setTimeout(() => {
        void this.poll();
      }, 1000);
    }
  }

  /**
   * Экран ждал maxMs — уходим, но сначала смотрим job.status:
   * иначе «если ещё идёт» пользователь проверить не может.
   */
  private async onWaitTimeout(): Promise<void> {
    const mins = Math.max(1, Math.round(this.maxMs / 60_000));
    let data: JobStatusResponse | null = null;
    try {
      data = await this.api.status();
    } catch {
      data = null;
    }

    const state = (data?.state ?? '').toLowerCase();
    const jobMsg = (data?.message ?? '').trim();

    if (state === 'running' || state === 'queued') {
      const detail = jobMsg !== '' ? ` Сейчас: ${jobMsg}` : '';
      this.goHome(
        `Экран ждал ${mins} мин — отклик ещё идёт в фоне.${detail} Откройте «Смотрим» через пару минут.`,
        false,
      );
      return;
    }

    if (state === 'done') {
      this.goHome(
        jobMsg !== ''
          ? jobMsg
          : `Экран ждал ${mins} мин — задание уже завершилось.`,
        data?.ok !== false,
      );
      return;
    }

    this.goHome(
      `Экран ждал ${mins} мин — по статусу job сейчас не running (скорее уже не идёт). Проверьте «Смотрим» и «Ошибки».`,
      false,
    );
  }

  private applyStatus(data: JobStatusResponse): void {
    const state = data.state ?? '';
    if (state === 'running' || state === 'queued') {
      this.sawRunning = true;
    }

    if (data.message) {
      this.setStatus(data.message);
    }

    if (state === 'done') {
      const updatedAtMs = typeof data.updated_at === 'number' ? data.updated_at * 1000 : 0;
      const isStale = !this.sawRunning && updatedAtMs > 0 && updatedAtMs < this.startedAt - 500;
      if (isStale) {
        window.setTimeout(() => {
          void this.poll();
        }, 700);
        return;
      }

      // Если PHP только что стартовал job, а мы ещё видим старый done — ждём running.
      if (!this.sawRunning && Date.now() - this.startedAt < 5000) {
        window.setTimeout(() => {
          void this.poll();
        }, 500);
        return;
      }

      if (
        this.job === 'apply' ||
        this.job === 'apply_consider' ||
        this.job === 'apply_errors' ||
        this.job === 'rehydrate_bot_wall' ||
        this.job === 'chats' ||
        this.job === 'leave_rejections' ||
        this.job === 'leave_old' ||
        this.job === 'resume_views'
      ) {
        this.goHome(data.message ?? 'Готово.', data.ok !== false);
        return;
      }

      if (data.ok) {
        this.goHome(data.message ?? 'Готово.', true);
      } else {
        this.fail(data.message ?? 'Задание завершилось с ошибкой.');
      }
      return;
    }

    window.setTimeout(() => {
      void this.poll();
    }, 700);
  }

  private setStatus(message: string): void {
    if (this.statusEl !== null) {
      this.statusEl.textContent = message;
    }
  }

  private fail(message: string): void {
    // Стартовые сбои (нет resume/city) — сразу домой с flash; кнопку оставляем на случай сбоя navigate.
    if (this.statusEl !== null) {
      this.statusEl.textContent = 'Ошибка';
    }
    if (this.errorEl !== null) {
      this.errorEl.style.display = 'block';
      this.errorEl.textContent = message;
    }
    if (this.homeEl !== null) {
      this.homeEl.classList.add('show');
    }
    document.body.dataset.busyStopped = '1';
    window.setTimeout(() => {
      this.goHome(message, false);
    }, 1200);
  }

  private goHome(flash: string, ok: boolean): void {
    document.body.dataset.busyStopped = '1';
    const query = new URLSearchParams();
    if (flash !== '') {
      query.set('flash', flash);
    }
    query.set('ok', ok ? '1' : '0');

    if (this.job === 'apply_errors') {
      query.set('page', 'errors');
      // После пакета удобнее сразу «Открытые».
    }

    if (this.job === 'rehydrate_bot_wall') {
      query.set('page', 'rehydrate');
    }

    if (this.job === 'apply' || this.job === 'apply_consider') {
      const params = new URLSearchParams(window.location.search);
      if ((params.get('return') ?? '') === 'errors') {
        query.set('page', 'errors');
        const errFilter = params.get('filter') ?? '';
        if (errFilter !== '' && errFilter !== 'open') {
          query.set('filter', errFilter);
        }
      } else {
        query.set('page', 'vacancies');
        const resume = params.get('resume') ?? '';
        const id = params.get('id') ?? '';
        const filter =
          this.job === 'apply_consider'
            ? 'consider'
            : (params.get('filter') ?? '');
        const scope = params.get('scope') ?? '';
        if (resume !== '') {
          query.set('resume', resume);
        }
        if (id !== '') {
          query.set('id', id);
        }
        if (filter !== '' && filter !== 'all') {
          query.set('filter', filter);
        }
        if (scope === 'all') {
          query.set('scope', 'all');
        }
      }
    }

    if (this.job === 'chats') {
      query.set('page', 'chats');
    }

    if (this.job === 'leave_rejections' || this.job === 'leave_old') {
      query.set('page', 'chats');
    }

    if (this.job === 'resume_views') {
      const params = new URLSearchParams(window.location.search);
      query.set('page', 'vacancies');
      const resume = params.get('resume') ?? '';
      const filter = params.get('filter') ?? '';
      const scope = params.get('scope') ?? '';
      if (resume !== '') {
        query.set('resume', resume);
      }
      if (filter !== '' && filter !== 'all') {
        query.set('filter', filter);
      }
      if (scope === 'all') {
        query.set('scope', 'all');
      }
    }

    window.location.replace(`/?${query.toString()}`);
  }
}
