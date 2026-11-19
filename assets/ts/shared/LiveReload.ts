/**
 * Same-origin live reload для `vite build --watch`.
 * Без :5173 / BrowserSync — работает в браузере с системным прокси.
 */
export class LiveReload {
  private version = '';

  /** 5 с: php -S однопоточный — поллинг раз в секунду клинит UI, пока главная ~8–12 с. */
  public boot(intervalMs = 5000): void {
    window.setInterval(() => {
      void this.poll();
    }, intervalMs);
    void this.poll();
  }

  private async poll(): Promise<void> {
    try {
      const response = await fetch(`/build/reload.json?_=${Date.now()}`, {
        cache: 'no-store',
      });
      if (!response.ok) {
        return;
      }
      const data = (await response.json()) as { v?: number | string };
      const next = String(data.v ?? '');
      if (next === '') {
        return;
      }
      if (this.version === '') {
        this.version = next;
        return;
      }
      if (next !== this.version) {
        window.location.reload();
      }
    } catch {
      // сервер/сборка ещё не готовы — тихо ждём
    }
  }
}
