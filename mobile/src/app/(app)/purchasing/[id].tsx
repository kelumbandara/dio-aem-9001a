import { Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';

import type { DocDetail } from '@/api/types';
import { useSession } from '@/auth/session';
import { purchaseMoves, type PurchaseMove } from '@/features/trade/docs';
import { DocStatus, LinesCard, TotalsCard } from '@/features/trade/ui';
import { confirmAction } from '@/ui/confirm';
import { Body, Button, Card, Field, Loading, Notice, Row, Screen } from '@/ui/kit';
import { formatDate, formatMoney } from '@/util/format';
import { useDescribeError, useLoad } from '@/util/hooks';

export default function PurchaseOrderDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, client, features } = useSession();
  const describe = useDescribeError();
  const detail = useLoad(async () => (await client.get<{ data: DocDetail }>(`/purchase-orders/${id}`)).data, [id]);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState<string | undefined>();
  const [busy, setBusy] = useState<PurchaseMove | null>(null);
  const [note, setNote] = useState<{ text: string; tone: 'ok' | 'warn' | 'danger' } | null>(null);
  const o = detail.data;

  async function move(kind: PurchaseMove) {
    if (!o) return;
    setReasonError(undefined);
    if (kind === 'cancel' && reason.trim() === '') {
      setReasonError(t('cancel_reason_required'));

      return;
    }
    const question = kind === 'submit' ? t('confirm_po_submit', { doc: o.document_no }) : kind === 'withdraw' ? t('confirm_po_withdraw', { doc: o.document_no }) : t('confirm_move', { action: t('cancel_po'), doc: o.document_no });
    if (!(await confirmAction(question, t('ok'), t('cancel')))) return;
    setBusy(kind);
    setNote(null);
    try {
      const res = await client.post<{ data: DocDetail }>(`/purchase-orders/${o.id}/${kind}`, kind === 'cancel' ? { reason: reason.trim() } : {});
      const waiting = res.data.status === 'pending_approval';
      setNote(
        kind === 'submit'
          ? { text: waiting ? t('po_submitted_waiting') : t('po_submitted_approved'), tone: waiting ? 'warn' : 'ok' }
          : { text: kind === 'withdraw' ? t('po_withdrawn') : t('po_cancelled'), tone: 'ok' },
      );
    } catch (e) {
      setNote({ text: describe(e), tone: 'danger' });
    } finally {
      setBusy(null);
      detail.reload();
    }
  }

  const moves = o ? purchaseMoves(o.status, features.purchaseOrders) : [];

  return (
    <Screen onRefresh={detail.refresh} refreshing={detail.refreshing}>
      <Stack.Screen options={{ title: o?.document_no ?? t('purchase_orders') }} />
      {detail.error ? <Notice text={detail.error} tone="danger" /> : null}
      {detail.loading && !o ? <Loading /> : null}
      {o ? (
        <>
          <Card>
            <Row left={<Body bold>{o.supplier_name ?? o.supplier}</Body>} right={<DocStatus status={o.status} />} />
            <Body muted>{o.supplier}</Body>
            {o.order_date ? <Body muted>{t('ordered_on', { date: formatDate(o.order_date) })}</Body> : null}
            {o.expected_date ? <Body muted>{t('expected_on', { date: formatDate(o.expected_date) })}</Body> : null}
            {o.warehouse ? <Body muted>{t('warehouse')}: {o.warehouse}</Body> : null}
            {o.supplier_ref ? <Body muted>{t('reference')}: {o.supplier_ref}</Body> : null}
            {o.notes ? <Body>{o.notes}</Body> : null}
            {o.cancel_reason ? <Body danger>{o.cancel_reason}</Body> : null}
            {o.status === 'pending_approval' ? <Body muted>{t('po_submitted_waiting')}</Body> : null}
          </Card>
          <LinesCard lines={o.lines} progress={[{ key: 'qty_received', label: t('received') }, { key: 'qty_billed', label: t('billed') }]} />
          <TotalsCard totals={o.totals} />
          {(o.receipts?.length ?? 0) + (o.bills?.length ?? 0) > 0 ? (
            <Card>
              <Body bold>{t('related_documents')}</Body>
              {o.receipts?.map((r) => (
                <Body key={r.id} muted>{r.document_no} · {formatDate(r.date)}</Body>
              ))}
              {o.bills?.map((b) => (
                <Row key={b.id} left={<Body muted>{b.document_no} · {formatDate(b.date)}</Body>} right={<Body>{formatMoney(b.gross_total)}</Body>} />
              ))}
            </Card>
          ) : null}
          {note ? <Notice text={note.text} tone={note.tone} /> : null}
          {moves.includes('cancel') ? <Field label={t('cancel_reason_label')} value={reason} onChangeText={setReason} maxLength={255} error={reasonError} /> : null}
          {moves.map((m) => (
            <Button key={m} label={t(m === 'submit' ? 'submit_po' : m === 'withdraw' ? 'withdraw_po' : 'cancel_po')} onPress={() => move(m)} kind={m === 'cancel' ? 'danger' : m === 'withdraw' ? 'secondary' : 'primary'} busy={busy === m} disabled={busy !== null} />
          ))}
        </>
      ) : null}
    </Screen>
  );
}
