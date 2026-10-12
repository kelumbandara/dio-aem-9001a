import { router } from 'expo-router';

import type { Conversation } from '@/api/types';
import { useSession } from '@/auth/session';
import { Body, Button, Card, Loading, Notice, Row, Screen } from '@/ui/kit';
import { formatDate } from '@/util/format';
import { useLoad } from '@/util/hooks';

export default function Conversations() {
  const { t, client } = useSession();
  const list = useLoad(async () => (await client.get<{ data: Conversation[] }>('/ai/conversations')).data);

  return (
    <Screen onRefresh={list.refresh} refreshing={list.refreshing}>
      <Body muted>{t('assistant_hint')}</Body>
      <Button label={t('new_question')} onPress={() => router.push('/assistant/new' as never)} />
      {list.error ? <Notice text={list.error} tone="danger" /> : null}
      {list.loading && !list.data ? <Loading /> : null}
      {list.data?.length === 0 ? <Body muted>{t('no_conversations')}</Body> : null}
      {list.data?.map((c) => (
        <Card key={c.id} onPress={() => router.push(`/assistant/${c.id}` as never)} accessibilityLabel={c.title}>
          <Row left={<Body bold>{c.title}</Body>} right={<Body muted>{formatDate(c.updated_at)}</Body>} />
        </Card>
      ))}
    </Screen>
  );
}
