import { ApiError, type Client } from '@/api/client';
import { OfflineQueue, submitOrQueue, type KeyValueStore } from '@/offline/queue';

jest.mock('@/util/uuid', () => {
  let n = 0;

  return { newId: () => `id-${++n}` };
});

function memoryStore(initial: Record<string, string> = {}): KeyValueStore & { data: Record<string, string> } {
  const data = { ...initial };

  return {
    data,
    getItem: async (k) => data[k] ?? null,
    setItem: async (k, v) => {
      data[k] = v;
    },
  };
}

type Sent = { path: string; body: any; key?: string };

/** A server that records what it gets, once per Idempotency-Key, and fails as scripted. */
function fakeServer(script: (sent: Sent, count: number) => ApiError | null = () => null) {
  const records: Sent[] = [];
  const attempts: Sent[] = [];
  const client: Client = {
    get: async () => undefined as never,
    del: async () => undefined,
    post: async (path, body, options) => {
      const sent = { path, body, key: options?.idempotencyKey };
      attempts.push(sent);
      const failure = script(sent, attempts.length);
      if (failure) throw failure;
      if (!records.some((r) => r.key === sent.key)) records.push(sent);

      return undefined as never;
    },
  };

  return { client, records, attempts };
}

const network = () => new ApiError('offline', 0, 'network', {}, true);
const refused = (m = 'Too many.') => new ApiError(m, 422, 'manufacturing.over_production');
const broken = () => new ApiError('Server error', 500, 'http_500');

const add = (q: OfflineQueue, n: number) => q.enqueue('output', `/production-orders/o${n}/output`, { produced: String(n) }, `Output ${n}`);

describe('queueing', () => {
  it('keeps entries in order, each with its own id that doubles as external_id', async () => {
    const q = new OfflineQueue(memoryStore());
    const a = await add(q, 1);
    const b = await add(q, 2);
    const items = await q.load();
    expect(items.map((i) => i.label)).toEqual(['Output 1', 'Output 2']);
    expect(a.id).not.toBe(b.id);
    expect(items[0].body.external_id).toBe(a.id);
    expect(items[0]).toMatchObject({ status: 'pending', attempts: 0, kind: 'output' });
  });

  it('survives a restart: a new queue on the same storage sees the same entries', async () => {
    const store = memoryStore();
    await add(new OfflineQueue(store), 1);
    expect(await new OfflineQueue(store).load()).toHaveLength(1);
  });

  it('does not lose entries made at the same moment', async () => {
    const q = new OfflineQueue(memoryStore());
    await Promise.all(Array.from({ length: 25 }, (_, i) => add(q, i)));
    const items = await q.load();
    expect(items).toHaveLength(25);
    expect(new Set(items.map((i) => i.id)).size).toBe(25);
  });

  it('treats unreadable or foreign storage as empty rather than crashing', async () => {
    for (const bad of ['not json', '{"a":1}', '']) {
      expect(await new OfflineQueue(memoryStore({ 'offline-queue': bad })).load()).toEqual([]);
    }
  });

  it('removes one entry on request', async () => {
    const q = new OfflineQueue(memoryStore());
    const a = await add(q, 1);
    await add(q, 2);
    await q.remove(a.id);
    expect((await q.load()).map((i) => i.label)).toEqual(['Output 2']);
  });
});

describe('sending what is waiting', () => {
  it('sends oldest first with the entry id as the idempotency key, and clears what was sent', async () => {
    const q = new OfflineQueue(memoryStore());
    const a = await add(q, 1);
    const b = await add(q, 2);
    const s = fakeServer();
    expect(await q.flush(s.client)).toEqual({ sent: 2, rejected: 0, remaining: 0 });
    expect(s.attempts.map((x) => x.key)).toEqual([a.id, b.id]);
    expect(s.attempts[0].body).toMatchObject({ produced: '1', external_id: a.id });
    expect(await q.load()).toEqual([]);
  });

  it('stops when the server cannot be reached and keeps everything, in order, untouched', async () => {
    const q = new OfflineQueue(memoryStore());
    await add(q, 1);
    await add(q, 2);
    const s = fakeServer(() => network());
    expect(await q.flush(s.client)).toEqual({ sent: 0, rejected: 0, remaining: 2, stopped: 'network' });
    expect(s.attempts).toHaveLength(1);
    expect((await q.load()).map((i) => [i.label, i.attempts, i.status])).toEqual([['Output 1', 0, 'pending'], ['Output 2', 0, 'pending']]);
    expect(await q.flush(fakeServer().client)).toMatchObject({ sent: 2, remaining: 0 });
  });

  it('keeps a refused entry visible as rejected with the reason, and carries on with the next one', async () => {
    const q = new OfflineQueue(memoryStore());
    await add(q, 1);
    await add(q, 2);
    const s = fakeServer((sent) => (sent.body.produced === '1' ? refused('More than the order allows.') : null));
    expect(await q.flush(s.client)).toEqual({ sent: 1, rejected: 1, remaining: 0 });
    const left = await q.load();
    expect(left).toHaveLength(1);
    expect(left[0]).toMatchObject({ label: 'Output 1', status: 'rejected', error: 'More than the order allows.' });
    const again = fakeServer();
    await q.flush(again.client);
    expect(again.attempts).toHaveLength(0);                       // a rejected entry is never resent by itself
  });

  it('retries a failing server a few times in order, then gives up on that entry', async () => {
    const q = new OfflineQueue(memoryStore());
    await add(q, 1);
    await add(q, 2);
    const s = fakeServer((sent) => (sent.body.produced === '1' ? broken() : null));
    for (let i = 1; i <= 4; i++) {
      expect(await q.flush(s.client)).toMatchObject({ sent: 0, rejected: 0, remaining: 2 });
    }
    expect((await q.load())[0].attempts).toBe(4);
    expect(s.records).toHaveLength(0);                            // the second entry never jumped the queue
    expect(await q.flush(s.client)).toEqual({ sent: 1, rejected: 1, remaining: 0 });
    expect((await q.load())[0]).toMatchObject({ status: 'rejected', attempts: 5 });
  });

  it('stops at once when the session has ended', async () => {
    const q = new OfflineQueue(memoryStore());
    await add(q, 1);
    const s = fakeServer(() => new ApiError('Unauthenticated.', 401, 'http_401'));
    expect(await q.flush(s.client)).toEqual({ sent: 0, rejected: 0, remaining: 1, stopped: 'unauthorized' });
    expect((await q.load())[0].status).toBe('pending');
  });

  it('shares one run when asked twice at once, so nothing is sent twice', async () => {
    const q = new OfflineQueue(memoryStore());
    await add(q, 1);
    await add(q, 2);
    const s = fakeServer();
    const [a, b] = await Promise.all([q.flush(s.client), q.flush(s.client)]);
    expect(s.attempts).toHaveLength(2);
    expect(a).toBe(b);
  });

  it('can be run again after a run ended', async () => {
    const q = new OfflineQueue(memoryStore());
    const s = fakeServer();
    await q.flush(s.client);
    await add(q, 1);
    expect(await q.flush(s.client)).toMatchObject({ sent: 1 });
  });
});

describe('submitting now or queueing', () => {
  const body = { shift: 'DAY', produced: '40' };

  it('just sends when the server answers', async () => {
    const q = new OfflineQueue(memoryStore());
    const s = fakeServer();
    expect(await submitOrQueue(s.client, q, 'output', '/production-orders/o1/output', body, 'Output')).toEqual({ outcome: 'sent' });
    expect(s.attempts[0].body.external_id).toBe(s.attempts[0].key);
    expect(await q.load()).toEqual([]);
  });

  it('queues when there is no connection, under the same id as the attempt', async () => {
    const q = new OfflineQueue(memoryStore());
    const s = fakeServer(() => network());
    const res = await submitOrQueue(s.client, q, 'output', '/production-orders/o1/output', body, 'Output');
    expect(res.outcome).toBe('queued');
    expect(res.outcome === 'queued' && res.item.id).toBe(s.attempts[0].key);
    expect(res.outcome === 'queued' && res.item.body.external_id).toBe(s.attempts[0].key);
  });

  it('hands a refusal back to the person instead of queueing it', async () => {
    const q = new OfflineQueue(memoryStore());
    const s = fakeServer(() => refused());
    await expect(submitOrQueue(s.client, q, 'output', '/p', body, 'Output')).rejects.toMatchObject({ code: 'manufacturing.over_production' });
    expect(await q.load()).toEqual([]);
  });

  it('cannot record twice when the answer was lost on the way back', async () => {
    // the first attempt reaches the server but the phone never hears the answer; the queued retry carries the same key
    const q = new OfflineQueue(memoryStore());
    let first = true;
    const s = fakeServer(() => (first ? ((first = false), network()) : null));
    const res = await submitOrQueue(s.client, q, 'output', '/p', body, 'Output');
    expect(res.outcome).toBe('queued');
    s.records.push({ path: '/p', body, key: s.attempts[0].key });   // what the server did record from the lost attempt
    await q.flush(s.client);
    expect(s.records).toHaveLength(1);
  });
});
