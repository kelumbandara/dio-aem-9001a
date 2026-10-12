import { useCallback, useEffect, useState } from 'react';

import { useSession } from '@/auth/session';
import type { QueueItem } from '@/offline/queue';
import { confirmAction } from '@/ui/confirm';
import { Badge, Body, Button, Card, Notice, Row, Screen } from '@/ui/kit';
import { formatDate } from '@/util/format';

export default function Queue() {
  const { t, queue, refreshQueue, flushQueue } = useSession();
  const [items, setItems] = useState<QueueItem[]>([]);
  const [note, setNote] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => setItems(await queue.load()), [queue]);
  useEffect(() => {
    let alive = true;
    queue.load().then((list) => alive && setItems(list));

    return () => {
      alive = false;
    };
  }, [queue]);

  async function send() {
    setBusy(true);
    try {
      const r = await flushQueue();
      setNote(r.stopped === 'network' ? t('still_offline') : t('sent_n', { count: r.sent }));
      await load();
    } finally {
      setBusy(false);
    }
  }

  async function discard(item: QueueItem) {
    if (!(await confirmAction(t('discard_confirm'), t('discard'), t('cancel')))) return;
    await queue.remove(item.id);
    await refreshQueue();
    await load();
  }

  return (
    <Screen>
      {items.length === 0 ? <Body muted>{t('queue_empty')}</Body> : <Button label={t('send_now')} onPress={send} busy={busy} />}
      {note ? <Notice text={note} tone="ok" /> : null}
      {items.map((i) => (
        <Card key={i.id}>
          <Row left={<Body bold>{i.label}</Body>} right={<Badge label={t(i.status === 'pending' ? 'entry_pending' : 'entry_rejected')} tone={i.status === 'pending' ? 'warn' : 'danger'} />} />
          <Body muted>{formatDate(i.createdAt)}</Body>
          {i.error ? <Body danger>{i.error}</Body> : null}
          <Button label={t('discard')} onPress={() => discard(i)} kind="secondary" />
        </Card>
      ))}
    </Screen>
  );
}
