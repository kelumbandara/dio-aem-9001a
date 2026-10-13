import { router, Stack, useLocalSearchParams } from 'expo-router';

import type { DocDetail } from '@/api/types';
import { useSession } from '@/auth/session';
import { DocStatus, LinesCard, TotalsCard } from '@/features/trade/ui';
import { Body, Card, Loading, Notice, Row, Screen } from '@/ui/kit';
import { formatDate, formatMoney } from '@/util/format';
import { useLoad } from '@/util/hooks';

export default function SalesOrderDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, client } = useSession();
  const detail = useLoad(async () => (await client.get<{ data: DocDetail }>(`/orders/${id}`)).data, [id]);
  const o = detail.data;

  return (
    <Screen onRefresh={detail.refresh} refreshing={detail.refreshing}>
      <Stack.Screen options={{ title: o?.document_no ?? t('sales_orders') }} />
      {detail.error ? <Notice text={detail.error} tone="danger" /> : null}
      {detail.loading && !o ? <Loading /> : null}
      {o ? (
        <>
          <Card>
            <Row left={<Body bold>{o.customer_name ?? o.customer}</Body>} right={<DocStatus status={o.status} />} />
            <Body muted>{o.customer}</Body>
            {o.order_date ? <Body muted>{t('ordered_on', { date: formatDate(o.order_date) })}</Body> : null}
            {o.warehouse ? <Body muted>{t('warehouse')}: {o.warehouse}</Body> : null}
            {o.quotation ? <Body muted>{t('quotations')}: {o.quotation}</Body> : null}
            {o.customer_ref ? <Body muted>{t('reference')}: {o.customer_ref}</Body> : null}
            {o.notes ? <Body>{o.notes}</Body> : null}
            {o.cancel_reason ? <Body danger>{o.cancel_reason}</Body> : null}
          </Card>
          <LinesCard lines={o.lines} progress={[{ key: 'qty_delivered', label: t('delivered') }, { key: 'qty_invoiced', label: t('invoiced') }]} />
          <TotalsCard totals={o.totals} />
          {(o.deliveries?.length ?? 0) + (o.invoices?.length ?? 0) > 0 ? (
            <Card>
              <Body bold>{t('related_documents')}</Body>
              {o.deliveries?.map((d) => (
                <Body key={d.id} muted>{d.document_no} · {formatDate(d.date)}</Body>
              ))}
              {o.invoices?.map((i) => (
                <Card key={i.id} onPress={() => router.push(`/sales/invoices/${i.id}` as never)} accessibilityLabel={i.document_no}>
                  <Row left={<Body bold>{i.document_no}</Body>} right={<Body bold>{formatMoney(i.gross_total)}</Body>} />
                  <Body muted>{formatDate(i.date)}</Body>
                </Card>
              ))}
            </Card>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}
