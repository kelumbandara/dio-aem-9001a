import { router } from 'expo-router';
import { useEffect, useState } from 'react';

import type { Page, StockRow } from '@/api/types';
import { useSession } from '@/auth/session';
import { Body, Card, Field, Loading, Notice, Row, Screen } from '@/ui/kit';
import { formatDecimal } from '@/util/format';
import { useLoad } from '@/util/hooks';

export default function StockSearch() {
  const { t, client } = useSession();
  const [text, setText] = useState('');
  const [q, setQ] = useState('');

  // search a moment after the person stops typing, not on every key
  useEffect(() => {
    const timer = setTimeout(() => setQ(text.trim()), 350);

    return () => clearTimeout(timer);
  }, [text]);

  const list = useLoad(() => client.get<Page<StockRow>>('/stock', { 'filter[q]': q, per_page: 30 }), [q]);

  return (
    <Screen onRefresh={list.refresh} refreshing={list.refreshing}>
      <Field label={t('search_items')} value={text} onChangeText={setText} autoCapitalize="none" autoCorrect={false} returnKeyType="search" />
      {list.error ? <Notice text={list.error} tone="danger" /> : null}
      {list.loading && !list.data ? <Loading /> : null}
      {list.data?.data.length === 0 ? <Body muted>{t('no_items')}</Body> : null}
      {list.data?.data.map((r) => (
        <Card key={r.item_code} onPress={() => router.push(`/stock/${encodeURIComponent(r.item_code)}` as never)} accessibilityLabel={`${r.item_code} ${r.item_name}`}>
          <Row left={<Body bold>{r.item_name}</Body>} right={<Body bold>{formatDecimal(r.available)} {r.uom}</Body>} />
          <Body muted>{r.item_code} · {t('on_hand')} {formatDecimal(r.on_hand)} · {t('reserved')} {formatDecimal(r.reserved)}</Body>
        </Card>
      ))}
    </Screen>
  );
}
