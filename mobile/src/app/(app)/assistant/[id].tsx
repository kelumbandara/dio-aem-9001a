import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useRef, useState } from 'react';
import { Linking, Pressable, Text, View } from 'react-native';

import type { ChatMessage, Conversation } from '@/api/types';
import { useSession } from '@/auth/session';
import { Body, Button, Field, Loading, Notice, Screen, useTheme } from '@/ui/kit';
import { localPath, screenFor } from '@/util/format';
import { useDescribeError, useLoad } from '@/util/hooks';
import { newId } from '@/util/uuid';

export default function Chat() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, client, serverUrl } = useSession();
  const describe = useDescribeError();
  const th = useTheme();
  const convo = useLoad(async () => (await client.get<{ data: Conversation }>(`/ai/conversations/${id}`)).data, [id]);
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const key = useRef(newId());

  async function send() {
    setBusy(true);
    setError(null);
    try {
      await client.post<{ data: ChatMessage }>(`/ai/conversations/${id}/messages`, { message: text.trim() }, { idempotencyKey: key.current });
      key.current = newId();
      setText('');
      convo.reload();
    } catch (e) {
      setError(describe(e));
    } finally {
      setBusy(false);
    }
  }

  function open(url: string) {
    const path = localPath(url);
    const screen = screenFor(path);
    if (screen) router.push(screen as never);
    else if (path) void Linking.openURL(serverUrl.replace(/\/+$/, '') + path);
  }

  return (
    <Screen onRefresh={convo.refresh} refreshing={convo.refreshing}>
      <Stack.Screen options={{ title: convo.data?.title ?? t('assistant_title') }} />
      {convo.error ? <Notice text={convo.error} tone="danger" /> : null}
      {convo.loading && !convo.data ? <Loading /> : null}
      {convo.data?.messages?.map((m) => (
        <View key={m.id} style={{ alignItems: m.role === 'user' ? 'flex-end' : 'flex-start' }}>
          <View style={{ maxWidth: '88%', borderRadius: 16, padding: 12, gap: 6, backgroundColor: m.role === 'user' ? th.primary : th.card, borderWidth: m.role === 'user' ? 0 : 1, borderColor: th.border }}>
            <Text selectable style={{ fontSize: 16, lineHeight: 22, color: m.role === 'user' ? th.onPrimary : th.text }}>{m.content}</Text>
            {m.role === 'assistant' && m.refs.length > 0 ? (
              <View style={{ gap: 4, borderTopWidth: 1, borderTopColor: th.border, paddingTop: 6 }}>
                <Text style={{ color: th.muted, fontSize: 13 }}>{t('based_on')}</Text>
                {m.refs.map((r, i) => (
                  <Pressable key={i} accessibilityRole="link" onPress={() => open(r.url)} style={{ minHeight: 32, justifyContent: 'center' }}>
                    <Text style={{ color: th.primary, fontSize: 15 }}>{r.label}</Text>
                  </Pressable>
                ))}
              </View>
            ) : null}
          </View>
        </View>
      ))}
      {busy ? <Body muted>{t('thinking')}</Body> : null}
      {error ? <Notice text={error} tone="danger" /> : null}
      <Field label={t('your_question')} value={text} onChangeText={(v) => { setText(v); key.current = newId(); }} multiline maxLength={2000} />
      <Button label={t('ask')} onPress={send} busy={busy} disabled={text.trim() === ''} />
    </Screen>
  );
}
