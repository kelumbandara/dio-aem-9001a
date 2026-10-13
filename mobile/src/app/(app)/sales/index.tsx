import { router } from 'expo-router';
import { useEffect, useState } from 'react';

import type { DocHeader, Page } from '@/api/types';
import { useSession } from '@/auth/session';
import { DocCard } from '@/features/trade/ui';
import { Body, Button, Choice, Field, Loading, Notice, Screen } from '@/ui/kit';
import { formatDate } from '@/util/format';
import { useLoad } from '@/util/hooks';

type Kind = 'quotations' | 'orders' | 'invoices';

const DATE_FIELD: Record<Kind, 'quote_date' | 'order_date' | 'invoice_date'> = { quotations: 'quote_date', orders: 'order_date', invoices: 'invoice_date' };

export default function Sales() {
  const { t, client, features } = useSession();
  const [kind, setKind] = useState<Kind>('quotations');
  const [text, setText] = useState('');
  const [q, setQ] = useState('');
  const [limit, setLimit] = useState(25);

  // search a moment after the person stops typing, not on every key
  useEffect(() => {
    const timer = setTimeout(() => {
      setQ(text.trim());
      setLimit(25);
    }, 350);

    return () => clearTimeout(timer);
  }, [text]);

  const list = useLoad(() => client.get<Page<DocHeader>>(`/${kind}`, { 'filter[q]': q, per_page: limit }), [kind, q, limit]);
  const total = list.data?.meta?.total ?? 0;

  return (
    <Screen onRefresh={list.refresh} refreshing={list.refreshing}>
      {features.quotations ? <Button label={t('new_quotation')} onPress={() => router.push('/sales/new-quotation' as never)} /> : null}
      <Choice label="" options={[{ value: 'quotations', label: t('quotations') }, { value: 'orders', label: t('sales_orders') }, { value: 'invoices', label: t('invoices') }]} value={kind} onChange={(k) => { setKind(k); setLimit(25); }} />
      <Field label={t('search_documents')} value={text} onChangeText={setText} autoCapitalize="none" autoCorrect={false} returnKeyType="search" />
      {list.error ? <Notice text={list.error} tone="danger" /> : null}
      {list.loading && !list.data ? <Loading /> : null}
      {list.data?.data.length === 0 ? <Body muted>{t('no_documents')}</Body> : null}
      {list.data?.data.map((d) => (
        <DocCard key={d.id} doc={d} party={d.customer_name ?? d.customer ?? ''} date={formatDate(d[DATE_FIELD[kind]])} onPress={() => router.push(`/sales/${kind}/${d.id}` as never)} />
      ))}
      {list.data && list.data.data.length < total && limit < 100 ? <Button label={t('show_more')} onPress={() => setLimit((n) => Math.min(100, n + 25))} kind="secondary" /> : null}
    </Screen>
  );
}
