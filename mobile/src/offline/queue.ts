import { ApiError, type Client } from '@/api/client';
import { newId } from '@/util/uuid';

/** The little storage the queue needs (AsyncStorage on the phone, a plain object in tests). */
export type KeyValueStore = {
  getItem(key: string): Promise<string | null>;
  setItem(key: string, value: string): Promise<void>;
};

export type QueueKind = 'output' | 'downtime';

export type QueueItem = {
  /** Also the Idempotency-Key and the `external_id`: however often it is sent, the server records it once. */
  id: string;
  kind: QueueKind;
  path: string;
  body: Record<string, unknown>;
  /** Plain words for the person: "Output ORD/1: 40 made". */
  label: string;
  createdAt: string;
  attempts: number;
  status: 'pending' | 'rejected';
  error?: string;
};

export type FlushResult = { sent: number; rejected: number; remaining: number; stopped?: 'network' | 'unauthorized' };

const MAX_SERVER_ERROR_ATTEMPTS = 5;

/**
 * Entries made without a connection (a machine hall with poor signal) wait here, in the order they were made, and are sent when the
 * phone can reach the server again. Nothing is lost on a restart; nothing is recorded twice; an entry the server refuses is kept
 * visible (status "rejected") so a person decides what to do with it instead of it vanishing.
 */
export class OfflineQueue {
  private chain: Promise<unknown> = Promise.resolve();
  private flushing: Promise<FlushResult> | null = null;

  constructor(
    private readonly store: KeyValueStore,
    private readonly key = 'offline-queue',
  ) {}

  async load(): Promise<QueueItem[]> {
    const raw = await this.store.getItem(this.key);
    if (!raw) return [];
    try {
      const parsed = JSON.parse(raw);

      return Array.isArray(parsed) ? (parsed as QueueItem[]) : [];
    } catch {
      return [];
    }
  }

  /** Runs one change to the stored list at a time, so two quick taps cannot overwrite each other. */
  private mutate<T>(change: (items: QueueItem[]) => { items: QueueItem[]; result: T }): Promise<T> {
    const run = this.chain.then(async () => {
      const { items, result } = change(await this.load());
      await this.store.setItem(this.key, JSON.stringify(items));

      return result;
    });
    this.chain = run.catch(() => undefined);

    return run;
  }

  enqueue(kind: QueueKind, path: string, body: Record<string, unknown>, label: string, id: string = newId()): Promise<QueueItem> {
    const item: QueueItem = { id, kind, path, body: { ...body, external_id: id }, label, createdAt: new Date().toISOString(), attempts: 0, status: 'pending' };

    return this.mutate((items) => ({ items: [...items, item], result: item }));
  }

  remove(id: string): Promise<void> {
    return this.mutate((items) => ({ items: items.filter((i) => i.id !== id), result: undefined }));
  }

  /** Sends what is waiting, oldest first, stopping when the server cannot be reached. Two calls at once share one run. */
  flush(client: Client): Promise<FlushResult> {
    this.flushing ??= this.run(client).finally(() => {
      this.flushing = null;
    });

    return this.flushing;
  }

  private async run(client: Client): Promise<FlushResult> {
    const result: FlushResult = { sent: 0, rejected: 0, remaining: 0 };
    for (const item of (await this.load()).filter((i) => i.status === 'pending')) {
      try {
        await client.post(item.path, item.body, { idempotencyKey: item.id });
        await this.remove(item.id);
        result.sent++;
      } catch (e) {
        if (!(e instanceof ApiError)) throw e;
        if (e.status === 401) {
          result.stopped = 'unauthorized';
          break;
        }
        if (e.network) {
          result.stopped = 'network';
          break;
        }
        const giveUp = !e.retryable || item.attempts + 1 >= MAX_SERVER_ERROR_ATTEMPTS;
        await this.mutate((items) => ({
          items: items.map((i) => (i.id === item.id ? { ...i, attempts: i.attempts + 1, status: giveUp ? 'rejected' : 'pending', error: e.message } : i)),
          result: undefined,
        }));
        if (giveUp) {
          result.rejected++;
        } else {
          break;                                              // the server is struggling: try again later, keep the order
        }
      }
    }
    result.remaining = (await this.load()).filter((i) => i.status === 'pending').length;

    return result;
  }
}

export type SubmitOutcome = { outcome: 'sent' } | { outcome: 'queued'; item: QueueItem };

/**
 * Sends an entry now; if the server cannot be reached, keeps it in the queue instead. Any answer from the server that is a refusal
 * (for example "more than the order allows") is thrown so the person can correct it.
 */
export async function submitOrQueue(client: Client, queue: OfflineQueue, kind: QueueKind, path: string, body: Record<string, unknown>, label: string): Promise<SubmitOutcome> {
  const id = newId();
  try {
    await client.post(path, { ...body, external_id: id }, { idempotencyKey: id });

    return { outcome: 'sent' };
  } catch (e) {
    if (e instanceof ApiError && e.network) {
      // the same id as the attempt that may have reached the server, so a lost answer cannot become a second record
      const item = await queue.enqueue(kind, path, body, label, id);

      return { outcome: 'queued', item };
    }
    throw e;
  }
}
