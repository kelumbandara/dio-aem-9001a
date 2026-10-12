import { router } from 'expo-router';
import { useState } from 'react';

import type { OrderHeader, Page } from '@/api/types';
import { useSession } from '@/auth/session';
import { Badge, Body, Card, Field, Loading, Notice, Row, Screen } from '@/ui/kit';
import { statusTone } from '@/ui/tones';
import { formatDate, formatDecimal } from '@/util/format';
import { useLoad } from '@/util/hooks';

export default function Orders() {
  const { t, client } = useSession();
  const [q, setQ] = useState('');
  // orders being worked on: released ones and those already started
  const list = useLoad(async () => {
    const [released, running] = await Promise.all([
      client.get<Page<OrderHeader>>('/production-orders', { 'filter[status]': 'released', 'filter[q]': q.trim(), per_page: 50 }),
      client.get<Page<OrderHeader>>('/production-orders', { 'filter[status]': 'in_progress', 'filter[q]': q.trim(), per_page: 50 }),
    ]);

    return [...running.data, ...released.data];
  }, [q]);

  return (
    <Screen onRefresh={list.refresh} refreshing={list.refreshing}>
      <Field label={t('search')} value={q} onChangeText={setQ} autoCapitalize="none" autoCorrect={false} returnKeyType="search" />
      {list.error ? <Notice text={list.error} tone="danger" /> : null}
      {list.loading && !list.data ? <Loading /> : null}
      {list.data?.length === 0 ? <Body muted>{t('no_orders')}</Body> : null}
      {list.data?.map((o) => (
        <Card key={o.id} onPress={() => router.push(`/work/${o.id}` as never)} accessibilityLabel={`${o.order_no} ${o.item_name}`}>
          <Row left={<Body bold>{o.order_no}</Body>} right={<Badge label={t(`order_status_${o.status}` as never)} tone={statusTone(o.status)} />} />
          <Body>{o.item_name}</Body>
          <Body muted>{t('planned')}: {formatDecimal(o.planned_qty)} {o.uom} · {formatDate(o.planned_end)}</Body>
        </Card>
      ))}
    </Screen>
  );
}
