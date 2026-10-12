import { newId } from '@/util/uuid';

/** A problem answered by the server (status and stable code), or no answer at all (`network` true, status 0). */
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code: string,
    public readonly fields: Record<string, string> = {},
    public readonly network = false,
  ) {
    super(message);
  }

  /** Worth sending again later: the server was not reached, was busy, or failed on its side. */
  get retryable(): boolean {
    return this.network || this.status === 408 || this.status === 429 || this.status >= 500;
  }
}

export type ClientConfig = {
  baseUrl: () => string;
  token: () => string | null;
  locale: () => string;
  onUnauthorized: () => void;
  fetchImpl?: typeof fetch;
  timeoutMs?: number;
};

export type Query = Record<string, string | number | boolean | undefined | null>;

export type Client = {
  get<T>(path: string, query?: Query): Promise<T>;
  post<T>(path: string, body?: unknown, options?: { idempotencyKey?: string }): Promise<T>;
  del(path: string): Promise<void>;
};

const API = '/api/v1';

export function buildUrl(base: string, path: string, query?: Query): string {
  const url = base.replace(/\/+$/, '') + API + path;
  const pairs = Object.entries(query ?? {}).filter(([, v]) => v !== undefined && v !== null && v !== '');
  if (pairs.length === 0) return url;

  return url + '?' + pairs.map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`).join('&');
}

/** Turns the server's error answers (our envelope, or Laravel's own validation shape) into one ApiError. */
export function toApiError(status: number, body: unknown): ApiError {
  const b = (body ?? {}) as Record<string, any>;
  const fields: Record<string, string> = {};
  for (const d of (b.error?.details ?? []) as { field?: string; message?: string }[]) {
    if (d.field && d.message) fields[d.field] = d.message;
  }
  for (const [field, messages] of Object.entries((b.errors ?? {}) as Record<string, string[]>)) {
    if (Array.isArray(messages) && messages[0]) fields[field] = messages[0];
  }
  const message = String(b.error?.message ?? b.message ?? `The server answered with status ${status}.`);
  const code = String(b.error?.code ?? (status === 422 ? 'validation' : `http_${status}`));

  return new ApiError(message, status, code, fields);
}

export function createClient(config: ClientConfig): Client {
  const doFetch = config.fetchImpl ?? fetch;

  async function request<T>(method: string, path: string, opts: { query?: Query; body?: unknown; idempotencyKey?: string } = {}): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json', 'Accept-Language': config.locale() };
    const token = config.token();
    if (token) headers.Authorization = `Bearer ${token}`;
    if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
    if (opts.idempotencyKey) headers['Idempotency-Key'] = opts.idempotencyKey;

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), config.timeoutMs ?? 20000);
    let res: Response;
    try {
      res = await doFetch(buildUrl(config.baseUrl(), path, opts.query), {
        method,
        headers,
        body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
        signal: controller.signal,
      });
    } catch {
      throw new ApiError('The server could not be reached. Check the connection and try again.', 0, 'network', {}, true);
    } finally {
      clearTimeout(timer);
    }

    if (res.status === 204) return undefined as T;
    let json: unknown = null;
    try {
      json = await res.json();
    } catch {
      json = null;
    }
    if (!res.ok) {
      const error = toApiError(res.status, json);
      // a rejected sign-in attempt is also a 401, but it is not an expired session
      if (res.status === 401 && !path.startsWith('/auth/login')) config.onUnauthorized();
      throw error;
    }

    // an answer that is not JSON (a web page, the wrong program on that port) is not our server: say so instead of failing later
    if (json === null) throw new ApiError('This address did not answer like the company system. Check the server address.', res.status, 'bad_response');

    return json as T;
  }

  return {
    get: <T>(path: string, query?: Query) => request<T>('GET', path, { query }),
    post: <T>(path: string, body?: unknown, options?: { idempotencyKey?: string }) => request<T>('POST', path, { body: body ?? {}, idempotencyKey: options?.idempotencyKey ?? newId() }),
    del: (path: string) => request<void>('DELETE', path),
  };
}
