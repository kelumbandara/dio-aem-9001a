import { router } from 'expo-router';

import type { AppNotification } from '@/api/types';
import { useSession } from '@/auth/session';
import { Body, Button, Card, Loading, Notice, Row, Screen } from '@/ui/kit';
import { formatDate, localPath, screenFor } from '@/util/format';
import { useLoad } from '@/util/hooks';

export default function Notifications() {
  const { t, client } = useSession();
  const list = useLoad(async () => (await client.get<{ data: AppNotification[] }>('/notifications', { per_page: 50 })).data);

  async function open(n: AppNotification) {
    if (!n.read) {
      await client.post(`/notifications/${n.id}/read`).catch(() => undefined);
      list.reload();
    }
    const screen = screenFor(localPath(n.url));
    if (screen) router.push(screen as never);
  }

  async function readAll() {
    await client.post('/notifications/read-all');
    list.reload();
  }

  return (
    <Screen onRefresh={list.refresh} refreshing={list.refreshing}>
      {list.error ? <Notice text={list.error} tone="danger" /> : null}
      {list.loading && !list.data ? <Loading /> : null}
      {list.data?.length === 0 ? <Body muted>{t('no_notifications')}</Body> : null}
      {list.data && list.data.some((n) => !n.read) ? <Button label={t('mark_all_read')} onPress={readAll} kind="secondary" /> : null}
      {list.data?.map((n) => (
        <Card key={n.id} onPress={() => open(n)} accessibilityLabel={n.title}>
          <Row left={<Body bold={!n.read}>{n.title}</Body>} right={<Body muted>{formatDate(n.created_at)}</Body>} />
          <Body muted>{n.body}</Body>
        </Card>
      ))}
    </Screen>
  );
}
