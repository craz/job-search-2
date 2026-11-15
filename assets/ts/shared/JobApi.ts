/** Ответ лёгких JSON-endpoint'ов UI (?job=…&format=json). */
export type JobKind =
  | 'load'
  | 'refresh'
  | 'set_city'
  | 'vacancies'
  | 'apply'
  | 'apply_consider'
  | 'apply_errors'
  | 'rehydrate_bot_wall'
  | 'chats'
  | 'leave_rejections'
  | 'leave_old'
  | 'resume_views';

export type JobStartResponse = {
  ok: boolean;
  started?: boolean;
  message?: string;
};

export type JobStatusResponse = {
  state?: string;
  step?: string;
  ok?: boolean | null;
  message?: string;
  updated_at?: number;
};

/**
 * Клиент фоновых заданий кеша (start + status).
 * Без DOM — только HTTP.
 */
export class JobApi {
  public async start(
    kind: JobKind,
    city = '',
    resume = '',
    vacancy = '',
    scope = '',
  ): Promise<JobStartResponse> {
    const url = this.startUrl(kind, city, resume, vacancy, scope);
    const response = await fetch(url);
    return (await response.json()) as JobStartResponse;
  }

  public async status(): Promise<JobStatusResponse> {
    const response = await fetch('/?job=status&format=json');
    return (await response.json()) as JobStatusResponse;
  }

  private startUrl(
    kind: JobKind,
    city: string,
    resume: string,
    vacancy: string,
    scope = '',
  ): string {
    if (kind === 'set_city') {
      return `/?job=set_city&format=json&city=${encodeURIComponent(city)}`;
    }
    if (kind === 'vacancies') {
      const q = resume !== '' ? `&resume=${encodeURIComponent(resume)}` : '';
      return `/?job=vacancies&format=json${q}`;
    }
    if (kind === 'apply') {
      return (
        `/?job=apply&format=json` +
        `&resume=${encodeURIComponent(resume)}` +
        `&vacancy=${encodeURIComponent(vacancy)}`
      );
    }
    if (kind === 'apply_consider') {
      const scopeQ = scope === 'all' ? '&scope=all' : '';
      return (
        `/?job=apply_consider&format=json` +
        `&resume=${encodeURIComponent(resume)}` +
        scopeQ
      );
    }
    if (kind === 'apply_errors') {
      return '/?job=apply_errors&format=json';
    }
    if (kind === 'rehydrate_bot_wall') {
      return '/?job=rehydrate_bot_wall&format=json';
    }
    if (kind === 'chats') {
      return '/?job=chats&format=json';
    }
    if (kind === 'leave_rejections') {
      return '/?job=leave_rejections&format=json';
    }
    if (kind === 'leave_old') {
      return '/?job=leave_old&format=json';
    }
    if (kind === 'resume_views') {
      return '/?job=resume_views&format=json';
    }
    if (kind === 'refresh') {
      return '/?job=refresh&format=json';
    }
    return '/?job=load&format=json';
  }
}
