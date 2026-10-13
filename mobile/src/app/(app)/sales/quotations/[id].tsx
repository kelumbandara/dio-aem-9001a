import { Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';

import type { DocDetail } from '@/api/types';
import { useSession } from '@/auth/session';
import { quotationMoves, type QuotationMove } from '@/features/trade/docs';
import { DocStatus, LinesCard, TotalsCard } from '@/features/trade/ui';
import { confirmAction } from '@/ui/confirm';
import { Body, Button, Card, Loading, Notice, Row, Screen } from '@/ui/kit';
import { formatDate } from '@/util/format';
import { useDescribeError, useLoad } from '@/util/hooks';

export default function QuotationDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, client, features } = useSession();
  const describe = useDescribeError();
  const detail = useLoad(async () => (await client.get<{ data: DocDetail }>(`/quotations/${id}`)).data, [id]);
  const [busy, setBusy] = useState<QuotationMove | null>(null);
  const [note, setNote] = useState<{ text: string; tone: 'ok' | 'danger' } | null>(null);
  const q = detail.data;

  async function move(to: QuotationMove) {
    if (!q) return;
    if (!(await confirmAction(t('confirm_move', { action: t(`move_${to}` as never), doc: q.document_no }), t('ok'), t('cancel')))) return;
    setBusy(to);
    setNote(null);
    try {
      await client.post(`/quotations/${q.id}/${to}`);
      setNote({ text: t('moved_done'), tone: 'ok' });
    } catch (e) {
      setNote({ text: describe(e), tone: 'danger' });
    } finally {
      setBusy(null);
      detail.reload();
    }
  }

  return (
    <Screen onRefresh={detail.refresh} refreshing={detail.refreshing}>
      <Stack.Screen options={{ title: q?.document_no ?? t('quotations') }} />
      {detail.error ? <Notice text={detail.error} tone="danger" /> : null}
      {detail.loading && !q ? <Loading /> : null}
      {q ? (
        <>
          <Card>
            <Row left={<Body bold>{q.customer_name ?? q.customer}</Body>} right={<DocStatus status={q.status} />} />
            <Body muted>{q.customer}</Body>
            {q.valid_until ? <Body muted>{t('quote_valid_until', { date: formatDate(q.valid_until) })}</Body> : null}
            {q.reference ? <Body muted>{t('reference')}: {q.reference}</Body> : null}
            {q.notes ? <Body>{q.notes}</Body> : null}
          </Card>
          <LinesCard lines={q.lines} />
          <TotalsCard totals={q.totals} />
          {note ? <Notice text={note.text} tone={note.tone} /> : null}
          {quotationMoves(q.status, features.quotations).map((m) => (
            <Button key={m} label={t(`move_${m}` as never)} onPress={() => move(m)} kind={m === 'cancelled' || m === 'rejected' ? 'danger' : 'primary'} busy={busy === m} disabled={busy !== null} />
          ))}
        </>
      ) : null}
    </Screen>
  );
}
