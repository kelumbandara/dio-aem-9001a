import { Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';

import type { Order } from '@/api/types';
import { useSession } from '@/auth/session';
import { DowntimeForm, InspectForm, OutputForm } from '@/features/work/forms';
import { useWorkSetup } from '@/features/work/setup';
import { Body, Card, Choice, Loading, Notice, Screen } from '@/ui/kit';
import { formatDecimal } from '@/util/format';
import { useLoad } from '@/util/hooks';

type Tab = 'output' | 'downtime' | 'inspect';

export default function OrderScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, client, features } = useSession();
  const order = useLoad(async () => (await client.get<{ data: Order }>(`/production-orders/${id}`)).data, [id]);
  const setup = useWorkSetup();
  const o = order.data;

  const tabs: { value: Tab; label: string }[] = [
    ...(features.production ? [{ value: 'output' as const, label: t('report_output') }] : []),
    ...(features.downtime ? [{ value: 'downtime' as const, label: t('report_downtime') }] : []),
    ...(features.quality && o && parseFloat(o.pending_qty) > 0 ? [{ value: 'inspect' as const, label: t('inspect') }] : []),
  ];
  const [tab, setTab] = useState<Tab | ''>('');
  const active = tabs.some((x) => x.value === tab) ? tab : (tabs[0]?.value ?? '');
  const open = o?.status === 'released' || o?.status === 'in_progress';

  return (
    <Screen onRefresh={() => { order.refresh(); setup.refresh(); }} refreshing={order.refreshing}>
      <Stack.Screen options={{ title: o?.order_no ?? t('work_title') }} />
      {order.error ? <Notice text={order.error} tone="danger" /> : null}
      {(order.loading && !o) || (setup.loading && !setup.data) ? <Loading /> : null}
      {o ? (
        <Card>
          <Body bold>{o.item_name}</Body>
          <Body muted>{t('planned')}: {formatDecimal(o.planned_qty)} {o.uom}</Body>
          <Body>{t('made')}: {formatDecimal(o.reported_qty)} · {t('accepted')}: {formatDecimal(o.accepted_qty)} · {t('rejected')}: {formatDecimal(o.rejected_qty)} · {t('rework')}: {formatDecimal(o.rework_qty)}</Body>
          {parseFloat(o.pending_qty) > 0 ? <Body muted>{t('waiting_inspection')}: {formatDecimal(o.pending_qty)}</Body> : null}
        </Card>
      ) : null}
      {setup.error ? <Notice text={setup.error} tone="danger" /> : null}
      {o && setup.data && open && tabs.length > 0 ? (
        <>
          {tabs.length > 1 ? <Choice label="" options={tabs} value={active} onChange={setTab} /> : null}
          {active === 'output' ? <OutputForm order={o} setup={setup.data} onDone={order.reload} /> : null}
          {active === 'downtime' ? <DowntimeForm order={o} setup={setup.data} onDone={order.reload} /> : null}
          {active === 'inspect' ? <InspectForm order={o} onDone={order.reload} /> : null}
        </>
      ) : null}
    </Screen>
  );
}
