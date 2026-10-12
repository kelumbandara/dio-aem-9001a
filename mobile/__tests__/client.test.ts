import { ApiError, buildUrl, createClient, toApiError } from '@/api/client';

jest.mock('@/util/uuid', () => {
  let n = 0;

  return { newId: () => `id-${++n}` };
});

type Call = { url: string; init: RequestInit };

function fakeFetch(answer: (call: Call) => Response | Promise<Response>) {
  const calls: Call[] = [];
  const impl = (async (url: string, init: RequestInit) => {
    const call = { url, init };
    calls.push(call);

    return answer(call);
  }) as unknown as typeof fetch;

  return { calls, impl };
}

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

function clientWith(f: typeof fetch, over: Partial<Parameters<typeof createClient>[0]> = {}) {
  const unauthorized = jest.fn();
  const client = createClient({ baseUrl: () => 'https://erp.example.test/', token: () => 'secret-token', locale: () => 'si', onUnauthorized: unauthorized, fetchImpl: f, ...over });

  return { client, unauthorized };
}

describe('buildUrl', () => {
  it('joins the base, the API prefix and the path without doubled slashes', () => {
    expect(buildUrl('https://erp.example.test/', '/me')).toBe('https://erp.example.test/api/v1/me');
    expect(buildUrl('https://erp.example.test', '/me')).toBe('https://erp.example.test/api/v1/me');
    expect(buildUrl('http://10.0.2.2:8000///', '/stock')).toBe('http://10.0.2.2:8000/api/v1/stock');
  });

  it('adds only the filled-in query values, encoded', () => {
    expect(buildUrl('https://x.test', '/stock', { 'filter[q]': 'a b&c', per_page: 20, empty: '', none: undefined, missing: null, flag: false })).toBe(
      'https://x.test/api/v1/stock?filter%5Bq%5D=a%20b%26c&per_page=20&flag=false',
    );
  });
});

describe('requests', () => {
  it('sends the token, the language and JSON, and returns the body', async () => {
    const f = fakeFetch(() => json({ data: { ok: true } }));
    const { client } = clientWith(f.impl);
    expect(await client.get('/me')).toEqual({ data: { ok: true } });
    const h = f.calls[0].init.headers as Record<string, string>;
    expect(h.Authorization).toBe('Bearer secret-token');
    expect(h['Accept-Language']).toBe('si');
    expect(h.Accept).toBe('application/json');
    expect(f.calls[0].init.method).toBe('GET');
    expect(f.calls[0].init.body).toBeUndefined();
  });

  it('sends no Authorization header when signed out', async () => {
    const f = fakeFetch(() => json({}));
    await clientWith(f.impl, { token: () => null }).client.get('/mobile/config');
    expect((f.calls[0].init.headers as Record<string, string>).Authorization).toBeUndefined();
  });

  it('gives every POST an Idempotency-Key, a new one each time unless given', async () => {
    const f = fakeFetch(() => json({}));
    const { client } = clientWith(f.impl);
    await client.post('/orders', { a: 1 });
    await client.post('/orders', { a: 1 });
    await client.post('/orders', { a: 1 }, { idempotencyKey: 'mine' });
    const keys = f.calls.map((c) => (c.init.headers as Record<string, string>)['Idempotency-Key']);
    expect(keys[0]).not.toBe(keys[1]);
    expect(keys[2]).toBe('mine');
    expect(f.calls[0].init.body).toBe('{"a":1}');
    expect((f.calls[0].init.headers as Record<string, string>)['Content-Type']).toBe('application/json');
  });

  it('returns nothing for 204', async () => {
    const f = fakeFetch(() => new Response(null, { status: 204 }));
    await expect(clientWith(f.impl).client.del('/devices/x')).resolves.toBeUndefined();
    expect(f.calls[0].init.method).toBe('DELETE');
  });
});

describe('errors', () => {
  it('reads the standard error envelope with its field details', async () => {
    const f = fakeFetch(() => json({ error: { code: 'manufacturing.over_production', message: 'More than the order allows.', details: [{ field: 'produced', message: 'Too many.' }] } }, 422));
    const e = await clientWith(f.impl).client.post('/x').catch((x) => x as ApiError);
    expect(e).toBeInstanceOf(ApiError);
    expect(e).toMatchObject({ status: 422, code: 'manufacturing.over_production', message: 'More than the order allows.', fields: { produced: 'Too many.' }, network: false, retryable: false });
  });

  it('reads Laravel\'s own validation shape too', () => {
    const e = toApiError(422, { message: 'The given data was invalid.', errors: { message: ['The message field is required.'] } });
    expect(e.fields).toEqual({ message: 'The message field is required.' });
    expect(e.code).toBe('validation');
  });

  it('copes with an error page that is not JSON', async () => {
    const f = fakeFetch(() => new Response('<html>Bad gateway</html>', { status: 502 }));
    const e = await clientWith(f.impl).client.get('/x').catch((x) => x as ApiError);
    expect(e).toMatchObject({ status: 502, code: 'http_502', retryable: true });
  });

  it('calls onUnauthorized for an expired session but not for a wrong password', async () => {
    const f = fakeFetch(() => json({ error: { code: 'auth.invalid_credentials', message: 'No.' } }, 401));
    const { client, unauthorized } = clientWith(f.impl);
    await client.get('/me').catch(() => undefined);
    expect(unauthorized).toHaveBeenCalledTimes(1);
    await client.post('/auth/login', { email: 'a', password: 'b' }).catch(() => undefined);
    expect(unauthorized).toHaveBeenCalledTimes(1);
  });

  it('recognises an address that is not the company system (a web page answered with 200)', async () => {
    const f = fakeFetch(() => new Response('<html>Metro bundler</html>', { status: 200 }));
    const e = (await clientWith(f.impl).client.post('/auth/login', {}).catch((x) => x)) as ApiError;
    expect(e).toBeInstanceOf(ApiError);
    expect(e).toMatchObject({ code: 'bad_response', network: false });
    expect(e.message).toContain('server address');
  });

  it('turns a failed connection into a retryable network error without leaking details', async () => {
    const f = fakeFetch(() => {
      throw new TypeError('Network request failed at 10.0.0.5 with Bearer secret-token');
    });
    const e = (await clientWith(f.impl).client.get('/x').catch((x) => x)) as ApiError;
    expect(e).toMatchObject({ status: 0, code: 'network', network: true, retryable: true });
    expect(e.message).not.toContain('secret-token');
  });

  it('gives up on a server that never answers', async () => {
    const f = fakeFetch(
      (call) =>
        new Promise<Response>((_, reject) => {
          (call.init.signal as AbortSignal).addEventListener('abort', () => reject(new Error('aborted')));
        }),
    );
    const e = await clientWith(f.impl, { timeoutMs: 20 }).client.get('/x').catch((x) => x as ApiError);
    expect(e).toMatchObject({ network: true, code: 'network' });
  });

  it('says which statuses are worth repeating', () => {
    expect(new ApiError('x', 429, 'c').retryable).toBe(true);
    expect(new ApiError('x', 503, 'c').retryable).toBe(true);
    expect(new ApiError('x', 408, 'c').retryable).toBe(true);
    for (const s of [400, 401, 403, 404, 409, 422]) expect(new ApiError('x', s, 'c').retryable).toBe(false);
  });
});
