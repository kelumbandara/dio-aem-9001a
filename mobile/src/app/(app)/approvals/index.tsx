import { router } from 'expo-router';
import { useState } from 'react';

import type { Approval, Page } from '@/api/types';
import { useSession } from '@/auth/session';
import { statusTone } from '@/ui/tones';
import { Badge, Body, Card, Choice, Loading, Notice, Row, Screen } from '@/ui/kit';
import { formatDate, formatMoney } from '@/util/format';
import { useLoad } from '@/util/hooks';

export default function Approvals() {
  const { t, client } = useSession();
  const [scope, setScope] = useState<'inbox' | 'mine'>('inbox');
  const list = useLoad(() => client.get<Page<Approval>>('/approvals', scope === 'inbox' ? { scope: 'inbox', status: 'pending', per_page: 50 } : { scope: 'mine', per_page: 50 }), [scope]);

  return (
    <Screen onRefresh={list.refresh} refreshing={list.refreshing}>
      <Choice label="" options={[{ value: 'inbox', label: t('inbox') }, { value: 'mine', label: t('sent_by_me') }]} value={scope} onChange={setScope} />
      {list.error ? <Notice text={list.error} tone="danger" /> : null}
      {list.loading && !list.data ? <Loading /> : null}
      {list.data?.data.length === 0 ? <Body muted>{t('no_approvals')}</Body> : null}
      {list.data?.data.map((a) => (
        <Card key={a.id} onPress={() => router.push(`/approvals/${a.id}` as never)} accessibilityLabel={`${a.document_no} ${a.summary}`}>
          <Row left={<Body bold>{a.document_no}</Body>} right={<Badge label={t(`status_${a.status}` as never)} tone={statusTone(a.status)} />} />
          <Body>{a.summary}</Body>
          <Row left={<Body muted>{a.requested_by_name ? t('requested_by', { name: a.requested_by_name }) : ''}</Body>} right={a.amount ? <Body bold>{formatMoney(a.amount)}</Body> : undefined} />
          <Body muted>{t('expires', { date: formatDate(a.expires_at) })}</Body>
        </Card>
      ))}
    </Screen>
  );
}
