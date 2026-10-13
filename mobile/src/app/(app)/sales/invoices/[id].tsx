import { router, Stack, useLocalSearchParams } from 'expo-router';

import type { DocDetail } from '@/api/types';
import { useSession } from '@/auth/session';
import { DocStatus, LinesCard, TotalsCard } from '@/features/trade/ui';
import { Body, Card, Loading, Notice, Row, Screen } from '@/ui/kit';
import { formatDate, formatMoney } from '@/util/format';
import { useLoad } from '@/util/hooks';

export default function InvoiceDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, client } = useSession();
  const detail = useLoad(async () => (await client.get<{ data: DocDetail }>(`/invoices/${id}`)).data, [id]);
  const i = detail.data;

  return (
    <Screen onRefresh={detail.refresh} refreshing={detail.refreshing}>
      <Stack.Screen options={{ title: i?.document_no ?? t('invoices') }} />
      {detail.error ? <Notice text={detail.error} tone="danger" /> : null}
      {detail.loading && !i ? <Loading /> : null}
      {i ? (
        <>
          <Card>
            <Row left={<Body bold>{i.customer_name ?? i.customer}</Body>} right={<DocStatus status={i.status} />} />
            <Body muted>{i.customer}</Body>
            {i.invoice_date ? <Body muted>{t('invoiced_on', { date: formatDate(i.invoice_date) })}</Body> : null}
            {i.due_date ? <Body muted>{t('due_on', { date: formatDate(i.due_date) })}</Body> : null}
            {i.customer_ref ? <Body muted>{t('reference')}: {i.customer_ref}</Body> : null}
            {i.notes ? <Body>{i.notes}</Body> : null}
          </Card>
          <LinesCard lines={i.lines} />
          <TotalsCard totals={i.totals} />
          {i.order_id ? (
            <Card onPress={() => router.push(`/sales/orders/${i.order_id}` as never)} accessibilityLabel={i.order ?? t('sales_orders')}>
              <Body bold>{t('sales_orders')}: {i.order}</Body>
            </Card>
          ) : null}
          {(i.credit_notes?.length ?? 0) > 0 ? (
            <Card>
              <Body bold>{t('related_documents')}</Body>
              {i.credit_notes?.map((c) => (
                <Row key={c.id} left={<Body muted>{c.document_no} · {formatDate(c.date)}</Body>} right={<Body>{formatMoney(c.gross_total)}</Body>} />
              ))}
            </Card>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}
