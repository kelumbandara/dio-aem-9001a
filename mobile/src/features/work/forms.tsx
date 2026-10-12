import { useRef, useState } from 'react';

import { ApiError } from '@/api/client';
import type { Order } from '@/api/types';
import { useSession } from '@/auth/session';
import { submitOrQueue } from '@/offline/queue';
import { confirmAction as ask } from '@/ui/confirm';
import { Body, Button, Choice, Field, Notice } from '@/ui/kit';
import { isQty, qtyOrZero, today } from '@/util/format';
import { useDescribeError } from '@/util/hooks';
import { newId } from '@/util/uuid';

import { entryDate, type WorkSetup } from './setup';

type Errors = Record<string, string>;

function useForm() {
  const describe = useDescribeError();
  const [errors, setErrors] = useState<Errors>({});
  const [note, setNote] = useState<{ text: string; tone: 'ok' | 'warn' | 'danger' } | null>(null);
  const [busy, setBusy] = useState(false);

  /** Shows the server's refusal on the box it belongs to, or as one message. */
  const show = (e: unknown) => {
    if (e instanceof ApiError && Object.keys(e.fields).length > 0) setErrors(e.fields);
    setNote({ text: describe(e), tone: 'danger' });
  };

  return { errors, setErrors, note, setNote, busy, setBusy, show };
}

type Props = { order: Order; setup: WorkSetup; onDone: () => void };

export function OutputForm({ order, setup, onDone }: Props) {
  const { t, client, queue, refreshQueue } = useSession();
  const f = useForm();
  const [shift, setShift] = useState(setup.current?.shift ?? '');
  const [produced, setProduced] = useState('');
  const [rejected, setRejected] = useState('');
  const [rework, setRework] = useState('');
  const [machine, setMachine] = useState('');
  const [notes, setNotes] = useState('');

  async function submit() {
    const errs: Errors = {};
    if (!shift) errs.shift = t('choose_shift');
    if (!isQty(produced)) errs.produced = t('qty_invalid');
    if (rejected.trim() !== '' && !isQty(rejected)) errs.rejected = t('qty_invalid');
    if (rework.trim() !== '' && !isQty(rework)) errs.rework = t('qty_invalid');
    f.setErrors(errs);
    f.setNote(null);
    if (Object.keys(errs).length > 0) return;
    if (!(await ask(t('confirm_output', { produced: produced.trim(), rejected: qtyOrZero(rejected), rework: qtyOrZero(rework) }), t('ok'), t('cancel')))) return;

    f.setBusy(true);
    try {
      const body: Record<string, unknown> = { shift, date: entryDate(setup, shift, today()), produced: produced.trim(), rejected: qtyOrZero(rejected), rework: qtyOrZero(rework) };
      if (machine) body.machine = machine;
      if (notes.trim()) body.notes = notes.trim();
      const res = await submitOrQueue(client, queue, 'output', `/production-orders/${order.id}/output`, body, `${order.order_no}: ${produced.trim()} ${order.uom}`);
      await refreshQueue();
      f.setNote({ text: t(res.outcome === 'sent' ? 'output_sent' : 'output_queued'), tone: res.outcome === 'sent' ? 'ok' : 'warn' });
      setProduced('');
      setRejected('');
      setRework('');
      setNotes('');
      onDone();
    } catch (e) {
      f.show(e);
    } finally {
      f.setBusy(false);
    }
  }

  return (
    <>
      {setup.current ? <Body muted>{t('running_shift', { name: setup.current.name })}</Body> : <Body muted>{t('no_running_shift')}</Body>}
      <Choice label={t('shift')} options={setup.shifts.map((s) => ({ value: s.code, label: s.name }))} value={shift} onChange={setShift} error={f.errors.shift} />
      <Field label={`${t('produced')} (${order.uom})`} value={produced} onChangeText={setProduced} keyboardType="decimal-pad" error={f.errors.produced} />
      <Field label={t('rejected_units')} value={rejected} onChangeText={setRejected} keyboardType="decimal-pad" error={f.errors.rejected} hint={t('optional')} />
      <Field label={t('rework_units')} value={rework} onChangeText={setRework} keyboardType="decimal-pad" error={f.errors.rework} hint={t('optional')} />
      {setup.machines.length > 0 ? <Choice label={`${t('machine')} (${t('optional')})`} options={setup.machines.map((m) => ({ value: m.code, label: m.name }))} value={machine} onChange={(v) => setMachine(v === machine ? '' : v)} /> : null}
      <Field label={t('notes')} value={notes} onChangeText={setNotes} multiline maxLength={500} />
      {f.note ? <Notice text={f.note.text} tone={f.note.tone === 'danger' ? 'danger' : f.note.tone} /> : null}
      <Button label={t('report_output')} onPress={submit} busy={f.busy} />
    </>
  );
}

export function DowntimeForm({ order, setup, onDone }: Props) {
  const { t, client, queue, refreshQueue } = useSession();
  const f = useForm();
  const [shift, setShift] = useState(setup.current?.shift ?? '');
  const [machine, setMachine] = useState('');
  const [reason, setReason] = useState('');
  const [minutes, setMinutes] = useState('');
  const [notes, setNotes] = useState('');

  async function submit() {
    const errs: Errors = {};
    if (!shift) errs.shift = t('choose_shift');
    if (!machine) errs.machine = t('choose_machine');
    if (!reason) errs.reason = t('choose_reason');
    if (!/^[1-9]\d{0,3}$/.test(minutes.trim())) errs.minutes = t('qty_invalid');
    f.setErrors(errs);
    f.setNote(null);
    if (Object.keys(errs).length > 0) return;
    const machineName = setup.machines.find((m) => m.code === machine)?.name ?? machine;
    const reasonName = setup.reasons.find((r) => r.code === reason)?.name ?? reason;
    if (!(await ask(t('confirm_downtime', { machine: machineName, minutes: minutes.trim(), reason: reasonName }), t('ok'), t('cancel')))) return;

    f.setBusy(true);
    try {
      const body: Record<string, unknown> = { shift, machine, reason, minutes: minutes.trim(), date: entryDate(setup, shift, today()), order: order.order_no };
      if (notes.trim()) body.notes = notes.trim();
      const res = await submitOrQueue(client, queue, 'downtime', '/downtime-events', body, `${machineName}: ${minutes.trim()} min`);
      await refreshQueue();
      f.setNote({ text: t(res.outcome === 'sent' ? 'downtime_sent' : 'downtime_queued'), tone: res.outcome === 'sent' ? 'ok' : 'warn' });
      setMinutes('');
      setNotes('');
      onDone();
    } catch (e) {
      f.show(e);
    } finally {
      f.setBusy(false);
    }
  }

  return (
    <>
      <Choice label={t('shift')} options={setup.shifts.map((s) => ({ value: s.code, label: s.name }))} value={shift} onChange={setShift} error={f.errors.shift} />
      <Choice label={t('machine')} options={setup.machines.map((m) => ({ value: m.code, label: m.name }))} value={machine} onChange={setMachine} error={f.errors.machine} />
      <Choice label={t('reason')} options={setup.reasons.map((r) => ({ value: r.code, label: r.name }))} value={reason} onChange={setReason} error={f.errors.reason} />
      <Field label={t('minutes')} value={minutes} onChangeText={setMinutes} keyboardType="number-pad" maxLength={4} error={f.errors.minutes} />
      <Field label={t('notes')} value={notes} onChangeText={setNotes} multiline maxLength={500} />
      {f.note ? <Notice text={f.note.text} tone={f.note.tone} /> : null}
      <Button label={t('report_downtime')} onPress={submit} busy={f.busy} />
    </>
  );
}

export function InspectForm({ order, onDone }: Omit<Props, 'setup'>) {
  const { t, client } = useSession();
  const f = useForm();
  const key = useRef(newId());                          // one key per form: a second tap or a retry cannot judge the same units twice
  const [accepted, setAccepted] = useState('');
  const [rejected, setRejected] = useState('');
  const [rework, setRework] = useState('');
  const [reason, setReason] = useState('');

  async function submit() {
    const errs: Errors = {};
    for (const [name, value] of [['accepted', accepted], ['rejected', rejected], ['rework', rework]] as const) {
      if (value.trim() !== '' && !isQty(value)) errs[name] = t('qty_invalid');
    }
    f.setErrors(errs);
    f.setNote(null);
    if (Object.keys(errs).length > 0) return;
    if (!(await ask(t('confirm_inspection', { accepted: qtyOrZero(accepted), rejected: qtyOrZero(rejected), rework: qtyOrZero(rework) }), t('ok'), t('cancel')))) return;

    f.setBusy(true);
    try {
      await client.post(`/production-orders/${order.id}/inspections`, { accepted: qtyOrZero(accepted), rejected: qtyOrZero(rejected), rework: qtyOrZero(rework), reject_reason: reason.trim() || undefined }, { idempotencyKey: key.current });
      f.setNote({ text: t('inspection_sent'), tone: 'ok' });
      key.current = newId();
      setAccepted('');
      setRejected('');
      setRework('');
      setReason('');
      onDone();
    } catch (e) {
      f.show(e);
    } finally {
      f.setBusy(false);
    }
  }

  return (
    <>
      <Body bold>{t('waiting_inspection')}: {order.pending_qty} {order.uom}</Body>
      <Field label={t('accepted_units')} value={accepted} onChangeText={setAccepted} keyboardType="decimal-pad" error={f.errors.accepted} />
      <Field label={t('rejected_units')} value={rejected} onChangeText={setRejected} keyboardType="decimal-pad" error={f.errors.rejected} />
      <Field label={t('rework_units')} value={rework} onChangeText={setRework} keyboardType="decimal-pad" error={f.errors.rework} />
      <Field label={t('reject_reason')} value={reason} onChangeText={setReason} maxLength={255} error={f.errors.reject_reason} />
      {f.note ? <Notice text={f.note.text} tone={f.note.tone} /> : null}
      <Button label={t('inspect')} onPress={submit} busy={f.busy} />
    </>
  );
}
