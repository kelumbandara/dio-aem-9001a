import { Stack, useLocalSearchParams } from 'expo-router';

import type { StockItem } from '@/api/types';
import { useSession } from '@/auth/session';
import { Body, Card, Loading, Notice, Row, Screen } from '@/ui/kit';
import { formatDate, formatDecimal } from '@/util/format';
import { useLoad } from '@/util/hooks';

export default function StockItemScreen() {
  const { code } = useLocalSearchParams<{ code: string }>();
  const { t, client } = useSession();
  const item = useLoad(async () => (await client.get<{ data: StockItem }>(`/stock/items/${encodeURIComponent(code)}`)).data, [code]);
  const i = item.data;

  return (
    <Screen onRefresh={item.refresh} refreshing={item.refreshing}>
      <Stack.Screen options={{ title: i?.item_name ?? code }} />
      {item.error ? <Notice text={item.error} tone="danger" /> : null}
      {item.loading && !i ? <Loading /> : null}
      {i ? (
        <>
          <Body muted>{i.item_code} · {i.uom}</Body>
          <Body bold>{t('by_location')}</Body>
          {i.balances.length === 0 ? <Body muted>{t('none')}</Body> : null}
          {i.balances.map((b, n) => (
            <Card key={n}>
              <Row left={<Body bold>{b.warehouse_name}{b.location ? ` · ${b.location}` : ''}</Body>} right={<Body bold>{formatDecimal(b.on_hand)} {i.uom}</Body>} />
              {b.lot ? <Body muted>{b.lot}{b.expiry_date ? ` · ${formatDate(b.expiry_date)}` : ''}</Body> : null}
            </Card>
          ))}
        </>
      ) : null}
    </Screen>
  );
}
