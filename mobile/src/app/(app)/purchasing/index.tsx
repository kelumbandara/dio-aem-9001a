import { router } from 'expo-router';
import { useEffect, useState } from 'react';

import type { DocHeader, Page } from '@/api/types';
import { useSession } from '@/auth/session';
import { DocCard } from '@/features/trade/ui';
import { Body, Button, Field, Loading, Notice, Screen } from '@/ui/kit';
import { formatDate } from '@/util/format';
import { useLoad } from '@/util/hooks';

export default function Purchasing() {
  const { t, client, features } = useSession();
  const [text, setText] = useState('');
  const [q, setQ] = useState('');
  const [limit, setLimit] = useState(25);

  useEffect(() => {
    const timer = setTimeout(() => {
      setQ(text.trim());
      setLimit(25);
    }, 350);

    return () => clearTimeout(timer);
  }, [text]);

  const list = useLoad(() => client.get<Page<DocHeader>>('/purchase-orders', { 'filter[q]': q, per_page: limit }), [q, limit]);
  const total = list.data?.meta?.total ?? 0;

  return (
    <Screen onRefresh={list.refresh} refreshing={list.refreshing}>
      {features.purchaseOrders ? <Button label={t('new_purchase_order')} onPress={() => router.push('/purchasing/new' as never)} /> : null}
      <Field label={t('search_documents')} value={text} onChangeText={setText} autoCapitalize="none" autoCorrect={false} returnKeyType="search" />
      {list.error ? <Notice text={list.error} tone="danger" /> : null}
      {list.loading && !list.data ? <Loading /> : null}
      {list.data?.data.length === 0 ? <Body muted>{t('no_documents')}</Body> : null}
      {list.data?.data.map((d) => (
        <DocCard key={d.id} doc={d} party={d.supplier_name ?? d.supplier ?? ''} date={formatDate(d.order_date)} onPress={() => router.push(`/purchasing/${d.id}` as never)} />
      ))}
      {list.data && list.data.data.length < total && limit < 100 ? <Button label={t('show_more')} onPress={() => setLimit((n) => Math.min(100, n + 25))} kind="secondary" /> : null}
    </Screen>
  );
}
