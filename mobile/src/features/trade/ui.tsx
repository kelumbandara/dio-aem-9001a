import { useEffect, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import type { DocHeader, DocLine, DocTotals, Lookup, Page } from '@/api/types';
import { useSession } from '@/auth/session';
import { Badge, Body, Button, Card, Field, Row, useTheme } from '@/ui/kit';
import { formatDate, formatDecimal, formatMoney } from '@/util/format';

import { docTone, STATUS_WORDS, type LineErrors, type LineRow } from './docs';

/** The status of a quotation, order, invoice or purchase order, in the person's language where there is a word for it. */
export function DocStatus({ status }: { status: string }) {
  const { t } = useSession();
  const known = (STATUS_WORDS as readonly string[]).includes(status);

  return <Badge label={known ? t(`doc_status_${status}` as never) : status} tone={docTone(status)} />;
}

/** One row of a list: who it is with, when, how much. */
export function DocCard({ doc, party, date, onPress }: { doc: DocHeader; party: string; date: string; onPress: () => void }) {
  return (
    <Card onPress={onPress} accessibilityLabel={`${doc.document_no} ${party}`}>
      <Row left={<Body bold>{doc.document_no}</Body>} right={<DocStatus status={doc.status} />} />
      <Body>{party}</Body>
      <Row left={<Body muted>{date}</Body>} right={doc.gross_total ? <Body bold>{formatMoney(doc.gross_total)}</Body> : undefined} />
    </Card>
  );
}

export function LinesCard({ lines, progress }: { lines: DocLine[]; progress?: { key: 'qty_delivered' | 'qty_invoiced' | 'qty_received' | 'qty_billed'; label: string }[] }) {
  const { t } = useSession();

  return (
    <Card>
      <Body bold>{t('lines_title')}</Body>
      {lines.map((l) => (
        <View key={l.line} style={{ gap: 2, paddingTop: 6 }}>
          <Row left={<Body>{l.item_name ?? l.item ?? l.description ?? '—'}</Body>} right={<Body bold>{formatMoney(l.net_amount)}</Body>} />
          <Body muted>
            {l.item ?? ''}{l.item ? ' · ' : ''}{formatDecimal(l.qty)} {l.uom ?? ''}
            {l.unit_price ? ` × ${formatDecimal(l.unit_price, 2)}` : ''}
            {l.discount_pct && Number(l.discount_pct) > 0 ? ` − ${formatDecimal(l.discount_pct)}%` : ''}
          </Body>
          {progress && progress.some((p) => l[p.key] !== undefined) ? (
            <Body muted>{progress.filter((p) => l[p.key] !== undefined).map((p) => `${p.label} ${formatDecimal(l[p.key])}`).join(' · ')}</Body>
          ) : null}
        </View>
      ))}
    </Card>
  );
}

export function TotalsCard({ totals }: { totals: DocTotals }) {
  const { t } = useSession();

  return (
    <Card>
      <Body bold>{t('totals')}</Body>
      <Row left={<Body muted>{t('net')}</Body>} right={<Body>{formatMoney(totals.net)}</Body>} />
      <Row left={<Body muted>{t('vat')}</Body>} right={<Body>{formatMoney(totals.vat)}</Body>} />
      {totals.levy && Number(totals.levy) > 0 ? <Row left={<Body muted>{t('levy')}</Body>} right={<Body>{formatMoney(totals.levy)}</Body>} /> : null}
      <Row left={<Body bold>{t('gross')}</Body>} right={<Body bold>{formatMoney(totals.gross)}</Body>} />
    </Card>
  );
}

export function dateLine(label: string | undefined, value: string | undefined): string | null {
  return value ? `${label ?? ''} ${formatDate(value)}`.trim() : null;
}

/**
 * A box for a customer, supplier or item code with suggestions underneath as the person types. Anything typed is accepted as the
 * code: the suggestions only help, and if they cannot be loaded (no permission, no connection) the typed code still goes to the server,
 * which says plainly when it does not know it.
 */
export function CodePicker({ label, path, value, onChange, error, filter }: { label: string; path: '/customers' | '/suppliers' | '/items'; value: string; onChange: (code: string) => void; error?: string; filter?: Record<string, string> }) {
  const { client } = useSession();
  const th = useTheme();
  // suggestions belong to the text they were found for: when the text changes they are simply not shown until the new ones arrive
  const [found, setFound] = useState<{ q: string; rows: Lookup[] }>({ q: '', rows: [] });
  const filterKey = JSON.stringify(filter ?? {});
  const q = value.trim();
  const results = q !== '' && found.q === q ? found.rows : [];

  useEffect(() => {
    if (q === '') return;
    let alive = true;
    const timer = setTimeout(() => {
      client
        .get<Page<Lookup>>(path, { 'filter[q]': q, per_page: 6, 'filter[is_active]': 'true', ...(JSON.parse(filterKey) as Record<string, string>) })
        .then((r) => alive && setFound({ q, rows: r.data }))
        .catch(() => alive && setFound({ q, rows: [] }));
    }, 300);

    return () => {
      alive = false;
      clearTimeout(timer);
    };
  }, [q, path, client, filterKey]);

  const typed = value.trim().toLowerCase();
  const exact = results.find((r) => r.code.toLowerCase() === typed);

  return (
    <View style={{ gap: 6 }}>
      <Field label={label} value={value} onChangeText={onChange} autoCapitalize="none" autoCorrect={false} error={error} />
      {exact ? <Body muted>{exact.name}</Body> : null}
      {!exact && results.length > 0 ? (
        <View style={{ gap: 6 }}>
          {results.map((r) => (
            <Pressable
              key={r.code}
              accessibilityRole="button"
              accessibilityLabel={`${r.code} ${r.name}`}
              onPress={() => {
                onChange(r.code);
                setFound({ q: r.code.trim(), rows: [r] });
              }}
              style={({ pressed }) => ({ borderWidth: 1, borderColor: th.border, borderRadius: 10, padding: 10, backgroundColor: th.card, opacity: pressed ? 0.7 : 1 })}>
              <Text style={{ color: th.text, fontSize: 15 }}>
                <Text style={{ fontWeight: '700' }}>{r.code}</Text> {r.name}
              </Text>
            </Pressable>
          ))}
        </View>
      ) : null}
    </View>
  );
}

/** The server's own sentence when it gave one ("No price is set for ITM-1; enter one."), else the app's standard text. */
const shown = (error: true | string | undefined, standard: string): string | undefined => (error === undefined ? undefined : error === true ? standard : error);

/** The lines of a new quotation or purchase order: one block per line, with a button to add another. */
export function LineEditor({ rows, errors, onChange, itemFilter }: { rows: LineRow[]; errors: LineErrors[]; onChange: (rows: LineRow[]) => void; itemFilter: Record<string, string> }) {
  const { t } = useSession();
  const set = (i: number, patch: Partial<LineRow>) => onChange(rows.map((r, n) => (n === i ? { ...r, ...patch } : r)));

  return (
    <View style={{ gap: 12 }}>
      {rows.map((r, i) => (
        <Card key={i}>
          <Row left={<Body bold>{t('line_n', { n: i + 1 })}</Body>} right={rows.length > 1 ? <Button label={t('remove_line')} onPress={() => onChange(rows.filter((_, n) => n !== i))} kind="secondary" /> : undefined} />
          <CodePicker label={t('item')} path="/items" value={r.item} onChange={(item) => set(i, { item })} error={shown(errors[i]?.item, t('choose_item'))} filter={itemFilter} />
          <Field label={t('qty')} value={r.qty} onChangeText={(qty) => set(i, { qty })} keyboardType="decimal-pad" error={shown(errors[i]?.qty, t('qty_positive'))} />
          <Field label={`${t('unit_price')} (${t('optional')})`} value={r.price} onChangeText={(price) => set(i, { price })} keyboardType="decimal-pad" hint={t('unit_price_hint')} error={shown(errors[i]?.price, t('price_invalid'))} />
          <Field label={`${t('discount')} (${t('optional')})`} value={r.discount} onChangeText={(discount) => set(i, { discount })} keyboardType="decimal-pad" error={shown(errors[i]?.discount, t('discount_invalid'))} />
        </Card>
      ))}
      <Button label={t('add_line')} onPress={() => onChange([...rows, { item: '', qty: '', price: '', discount: '' }])} kind="secondary" />
    </View>
  );
}
