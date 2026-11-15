type ScheduleStatusResponse = {
  ok?: boolean;
  note?: string;
  message?: string;
};

/**
 * Live-статус задачи-задачи: сразу при загрузке + poll раз в 15 с.
 * Элемент: `.js-schedule-status[data-schedule-task="chats|resume_views|vacancies"]`.
 */
export class ScheduleStatusPoll {
  public static fromDocument(): void {
    const nodes = document.querySelectorAll('.js-schedule-status');
    nodes.forEach((node) => {
      if (!(node instanceof HTMLElement)) {
        return;
      }
      const task = (node.dataset.scheduleTask ?? 'chats').trim() || 'chats';
      new ScheduleStatusPoll(node, task).start();
    });
  }

  private timerId = 0;
  private inFlight = false;

  private constructor(
    private readonly el: HTMLElement,
    private readonly task: string,
  ) {}

  public start(): void {
    void this.tick();
    this.timerId = window.setInterval(() => {
      void this.tick();
    }, 15_000);
  }

  public stop(): void {
    if (this.timerId !== 0) {
      window.clearInterval(this.timerId);
      this.timerId = 0;
    }
  }

  private async tick(): Promise<void> {
    if (this.inFlight) {
      return;
    }
    this.inFlight = true;
    try {
      const response = await fetch(
        `/?job=schedule_status&format=json&task=${encodeURIComponent(this.task)}`,
        {
          headers: { Accept: 'application/json' },
          cache: 'no-store',
        },
      );
      const data = (await response.json()) as ScheduleStatusResponse;
      if (!data.ok) {
        return;
      }
      const note = (data.note ?? '').trim();
      if (note !== '' && this.el.textContent !== note) {
        this.el.textContent = note;
      }
    } catch {
      // Сеть/прокси — тихо, следующий poll подхватит.
    } finally {
      this.inFlight = false;
    }
  }
}
