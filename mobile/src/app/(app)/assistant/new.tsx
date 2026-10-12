import { router } from 'expo-router';
import { useRef, useState } from 'react';

import type { Agent, Conversation } from '@/api/types';
import { useSession } from '@/auth/session';
import { Body, Button, Choice, Field, Loading, Notice, Screen } from '@/ui/kit';
import { useDescribeError, useLoad } from '@/util/hooks';
import { newId } from '@/util/uuid';

export default function NewQuestion() {
  const { t, client } = useSession();
  const describe = useDescribeError();
  const agents = useLoad(async () => (await client.get<{ data: Agent[] }>('/ai/agents')).data);
  const [agent, setAgent] = useState('');
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const key = useRef(newId());                                   // the same key on a retry: the question is paid for once

  const chosen = agent || agents.data?.[0]?.key || 'assistant';
  const description = agents.data?.find((a) => a.key === chosen)?.description;

  async function send() {
    setBusy(true);
    setError(null);
    try {
      const res = await client.post<{ data: Conversation }>('/ai/conversations', { message: text.trim(), agent: chosen }, { idempotencyKey: key.current });
      key.current = newId();
      router.replace(`/assistant/${res.data.id}` as never);
    } catch (e) {
      setError(describe(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      {agents.loading && !agents.data ? <Loading /> : null}
      {agents.data && agents.data.length > 1 ? <Choice label={t('ask_who')} options={agents.data.map((a) => ({ value: a.key, label: a.name }))} value={chosen} onChange={setAgent} /> : null}
      {description ? <Body muted>{description}</Body> : null}
      <Field label={t('your_question')} value={text} onChangeText={(v) => { setText(v); key.current = newId(); }} multiline maxLength={2000} />
      {error ? <Notice text={error} tone="danger" /> : null}
      {busy ? <Body muted>{t('thinking')}</Body> : null}
      <Button label={t('ask')} onPress={send} busy={busy} disabled={text.trim() === ''} />
    </Screen>
  );
}
