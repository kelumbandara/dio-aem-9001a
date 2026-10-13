import { router } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import type { Page } from '@/api/types';
import { useSession } from '@/auth/session';
import { Body, Button, Card, Notice, Screen, Title } from '@/ui/kit';
import { useLoad } from '@/util/hooks';

type Tile = { key: string; label: string; detail?: string; href: string };

export default function Home() {
  const { t, user, config, features, client, pending, flushQueue, updateRequired } = useSession();
  const [sendNote, setSendNote] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  const waiting = useLoad(async () => {
    if (!features.approvals) return 0;
    const res = await client.get<Page<unknown>>('/approvals', { scope: 'inbox', status: 'pending', per_page: 1 });

    return res.meta?.total ?? res.data.length;
  }, [features.approvals]);

  async function sendNow() {
    setSending(true);
    try {
      const r = await flushQueue();
      setSendNote(r.stopped === 'network' ? t('still_offline') : t('sent_n', { count: r.sent }));
    } finally {
      setSending(false);
    }
  }

  const tiles: Tile[] = [
    ...(features.approvals ? [{ key: 'approvals', label: t('tile_approvals'), detail: waiting.data ? t('pending_approvals', { count: waiting.data }) : t('nothing_to_do'), href: '/approvals' }] : []),
    ...(features.sales ? [{ key: 'sales', label: t('tile_sales'), href: '/sales' }] : []),
    ...(features.purchasing ? [{ key: 'purchasing', label: t('tile_purchasing'), href: '/purchasing' }] : []),
    ...(features.production ? [{ key: 'output', label: t('tile_production'), href: '/work' }] : []),
    ...(features.downtime ? [{ key: 'downtime', label: t('tile_downtime'), href: '/work' }] : []),
    ...(features.quality ? [{ key: 'quality', label: t('tile_quality'), href: '/work' }] : []),
    ...(features.stock ? [{ key: 'stock', label: t('tile_stock'), href: '/stock' }] : []),
    ...(features.assistant ? [{ key: 'assistant', label: t('tile_assistant'), href: '/assistant' }] : []),
    { key: 'notifications', label: t('tile_notifications'), href: '/more/notifications' },
  ];

  return (
    <Screen onRefresh={waiting.refresh} refreshing={waiting.refreshing}>
      <Title>{t('hello', { name: user?.name ?? '' })}</Title>
      <Body muted>{config?.company}</Body>
      {updateRequired ? <Notice text={t('update_required')} tone="danger" /> : null}
      {pending > 0 ? (
        <Card>
          <Body bold>{t('waiting_to_send', { count: pending })}</Body>
          <Button label={t('send_now')} onPress={sendNow} busy={sending} kind="secondary" />
        </Card>
      ) : null}
      {sendNote ? <Notice text={sendNote} tone="ok" /> : null}
      <View style={{ gap: 12 }}>
        {tiles.map((tile) => (
          <Card key={tile.key} onPress={() => router.push(tile.href as never)} accessibilityLabel={tile.label}>
            <Body bold>{tile.label}</Body>
            {tile.detail ? <Body muted>{tile.detail}</Body> : null}
          </Card>
        ))}
      </View>
    </Screen>
  );
}
