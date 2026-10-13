import { router, Stack } from 'expo-router';
import { useRef, useState } from 'react';

import { ApiError } from '@/api/client';
import type { DocDetail, Lookup } from '@/api/types';
import { useSession } from '@/auth/session';
import { confirmAction } from '@/ui/confirm';
import { Button, Choice, Field, Notice, Screen } from '@/ui/kit';
import { useDescribeError, useLoad } from '@/util/hooks';
import { newId } from '@/util/uuid';

import { buildLines, checkLines, emptyRow, lineErrorsFromServer, type LineErrors, type LineRow } from './docs';
import { CodePicker, LineEditor } from './ui';

/** A new quotation (for a customer) or purchase order (to a supplier, into a warehouse), saved as a draft. Needs a connection. */
export function NewDocument({ kind }: { kind: 'quotation' | 'purchase' }) {
  const { t, client } = useSession();
  const describe = useDescribeError();
  const sales = kind === 'quotation';
  const [party, setParty] = useState('');
  const [warehouse, setWarehouse] = useState('');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');
  const [rows, setRows] = useState<LineRow[]>([emptyRow()]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [lineErrors, setLineErrors] = useState<LineErrors[]>([]);
  const [note, setNote] = useState<{ text: string; tone: 'ok' | 'danger' } | null>(null);
  const [busy, setBusy] = useState(false);
  // one identity for this form: a second tap or an answer lost on the way back cannot make a second document
  const key = useRef(newId());
  const warehouses = useLoad(async () => (sales ? [] : (await client.get<{ data: Lookup[] }>('/warehouses', { 'filter[is_active]': 'true' })).data), [kind]);

  async function save() {
    const errs: Record<string, string> = {};
    if (party.trim() === '') errs.party = t(sales ? 'choose_customer' : 'choose_supplier');
    if (!sales && warehouse === '') errs.warehouse = t('choose_warehouse');
    const check = checkLines(rows);
    setErrors(errs);
    setLineErrors(check.errors);
    setNote(null);
    if (check.filled === 0) {
      setNote({ text: t('need_one_line'), tone: 'danger' });

      return;
    }
    if (Object.keys(errs).length > 0 || !check.ok) {
      if (!check.ok) setNote({ text: t('check_lines'), tone: 'danger' });

      return;
    }
    const question = sales ? t('confirm_quotation', { customer: party.trim(), count: check.filled }) : t('confirm_po', { supplier: party.trim(), count: check.filled });
    if (!(await confirmAction(question, t('ok'), t('cancel')))) return;

    setBusy(true);
    try {
      const common = { lines: buildLines(rows), ...(notes.trim() ? { notes: notes.trim() } : {}) };
      const body = sales
        ? { customer: party.trim(), ...(reference.trim() ? { reference: reference.trim() } : {}), ...common }
        : { supplier: party.trim(), warehouse, ...(reference.trim() ? { supplier_ref: reference.trim() } : {}), ...common };
      const res = await client.post<{ data: DocDetail }>(sales ? '/quotations' : '/purchase-orders', body, { idempotencyKey: key.current });
      setNote({ text: t(sales ? 'saved_quotation' : 'saved_po', { doc: res.data.document_no }), tone: 'ok' });
      router.replace((sales ? `/sales/quotations/${res.data.id}` : `/purchasing/${res.data.id}`) as never);
    } catch (e) {
      if (e instanceof ApiError && e.network) {
        // the answer may have been lost on the way back: the same identity makes a retry safe
        setNote({ text: t('needs_connection'), tone: 'danger' });
      } else {
        key.current = newId();
        if (e instanceof ApiError) {
          const next: Record<string, string> = {};
          if (e.fields[sales ? 'customer' : 'supplier']) next.party = e.fields[sales ? 'customer' : 'supplier'];
          if (e.fields.warehouse) next.warehouse = e.fields.warehouse;
          setErrors(next);
          setLineErrors(lineErrorsFromServer(e.fields, rows));
        }
        setNote({ text: describe(e), tone: 'danger' });
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <Stack.Screen options={{ title: t(sales ? 'new_quotation' : 'new_purchase_order') }} />
      <CodePicker label={t(sales ? 'pick_customer' : 'pick_supplier')} path={sales ? '/customers' : '/suppliers'} value={party} onChange={setParty} error={errors.party} />
      {!sales ? (
        <Choice label={t('warehouse')} options={(warehouses.data ?? []).map((w) => ({ value: w.code, label: `${w.code} · ${w.name}` }))} value={warehouse} onChange={setWarehouse} error={errors.warehouse} />
      ) : null}
      <Field label={`${t('reference')} (${t('optional')})`} value={reference} onChangeText={setReference} maxLength={100} />
      <LineEditor rows={rows} errors={lineErrors} onChange={setRows} itemFilter={sales ? { 'filter[is_sellable]': 'true' } : { 'filter[is_purchasable]': 'true' }} />
      <Field label={`${t('notes')} (${t('optional')})`} value={notes} onChangeText={setNotes} multiline maxLength={2000} />
      {note ? <Notice text={note.text} tone={note.tone} /> : null}
      <Button label={t('save')} onPress={save} busy={busy} />
    </Screen>
  );
}
