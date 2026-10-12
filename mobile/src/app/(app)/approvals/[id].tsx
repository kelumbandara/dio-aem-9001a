import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';

import type { Approval } from '@/api/types';
import { useSession } from '@/auth/session';
import { confirmAction } from '@/ui/confirm';
import { statusTone } from '@/ui/tones';
import { Badge, Body, Button, Card, Field, Loading, Notice, Row, Screen } from '@/ui/kit';
import { formatDate, formatMoney } from '@/util/format';
import { useDescribeError, useLoad } from '@/util/hooks';


export default function ApprovalDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, client } = useSession();
  const describe = useDescribeError();
  const detail = useLoad(async () => (await client.get<{ data: Approval }>(`/approvals/${id}`)).data, [id]);
  const [comment, setComment] = useState('');
  const [commentError, setCommentError] = useState<string | undefined>();
  const [busy, setBusy] = useState<'approve' | 'reject' | null>(null);
  const [note, setNote] = useState<{ text: string; tone: 'ok' | 'danger' } | null>(null);

  const a = detail.data;

  async function decide(kind: 'approve' | 'reject') {
    if (!a) return;
    setCommentError(undefined);
    if (kind === 'reject' && comment.trim() === '') {
      setCommentError(t('comment_required'));

      return;
    }
    if (!(await confirmAction(t(kind === 'approve' ? 'approve_confirm' : 'reject_confirm', { doc: a.document_no }), t(kind === 'approve' ? 'approve' : 'reject'), t('cancel')))) return;
    setBusy(kind);
    setNote(null);
    try {
      await client.post(`/approvals/${a.id}/${kind}`, comment.trim() === '' ? {} : { comment: comment.trim() });
      setNote({ text: t(kind === 'approve' ? 'approved_done' : 'rejected_done'), tone: 'ok' });
      detail.reload();
      setTimeout(() => router.back(), 900);
    } catch (e) {
      setNote({ text: describe(e), tone: 'danger' });
      detail.reload();
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen onRefresh={detail.refresh} refreshing={detail.refreshing}>
      <Stack.Screen options={{ title: a?.document_no ?? t('approvals_title') }} />
      {detail.error ? <Notice text={detail.error} tone="danger" /> : null}
      {detail.loading && !a ? <Loading /> : null}
      {a ? (
        <>
          <Card>
            <Row left={<Body bold>{a.document_no}</Body>} right={<Badge label={t(`status_${a.status}` as never)} tone={statusTone(a.status)} />} />
            <Body>{a.summary}</Body>
            {a.amount ? <Body bold>{t('amount')}: {formatMoney(a.amount)}</Body> : null}
            {a.requested_by_name ? <Body muted>{t('requested_by', { name: a.requested_by_name })}</Body> : null}
            <Body muted>{t('expires', { date: formatDate(a.expires_at) })}</Body>
          </Card>

          {a.steps && a.steps.length > 0 ? (
            <Card>
              <Body bold>{t('steps')}</Body>
              {a.steps.map((s) => (
                <Row key={s.order} left={<Body>{s.order}. {s.label ?? ''}{s.decided_by?.name ? ` (${s.decided_by.name})` : ''}</Body>} right={<Badge label={s.status} tone={statusTone(s.status)} />} />
              ))}
            </Card>
          ) : null}

          {a.decisions && a.decisions.length > 0 ? (
            <Card>
              <Body bold>{t('decisions')}</Body>
              {a.decisions.map((d, i) => (
                <Body key={i} muted>{d.decided_by.name}: {d.decision}{d.comment ? ` — ${d.comment}` : ''} ({formatDate(d.decided_at)})</Body>
              ))}
            </Card>
          ) : null}

          {note ? <Notice text={note.text} tone={note.tone} /> : null}

          {a.status === 'pending' ? (
            <>
              <Field label={t('comment')} value={comment} onChangeText={setComment} multiline maxLength={2000} error={commentError} />
              <Button label={t('approve')} onPress={() => decide('approve')} busy={busy === 'approve'} disabled={busy !== null} />
              <Button label={t('reject')} onPress={() => decide('reject')} kind="danger" busy={busy === 'reject'} disabled={busy !== null} />
            </>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}
