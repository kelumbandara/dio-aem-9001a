/** Pure rules for the sales and purchasing screens: what a line must look like, which actions a document offers, how its status is coloured. */

export type LineRow = { item: string; qty: string; price: string; discount: string };

/** What is wrong on a line: `true` for the app's own check (its standard text is shown), or the server's own sentence. */
export type LineErrors = { item?: true | string; qty?: true | string; price?: true | string; discount?: true | string };

export const emptyRow = (): LineRow => ({ item: '', qty: '', price: '', discount: '' });

const QTY = /^\d{1,12}(\.\d{1,6})?$/;
const PRICE = /^\d{1,14}(\.\d{1,4})?$/;
const PERCENT = /^\d{1,3}(\.\d{1,4})?$/;

export const isQty = (v: string): boolean => QTY.test(v.trim()) && Number(v) > 0;
export const isPrice = (v: string): boolean => PRICE.test(v.trim());
export const isPercent = (v: string): boolean => PERCENT.test(v.trim()) && Number(v) <= 100;

/** A row with nothing typed in it is not a line (the server ignores such rows too). */
export const isBlank = (r: LineRow): boolean => r.item.trim() === '' && r.qty.trim() === '' && r.price.trim() === '' && r.discount.trim() === '';

/**
 * Checks the rows the person filled in. `errors[i]` belongs to `rows[i]`; blank rows have none.
 * `ok` needs at least one filled row and no error. Price and discount are optional: left empty, the server uses the price list.
 */
export function checkLines(rows: LineRow[]): { ok: boolean; errors: LineErrors[]; filled: number } {
  let filled = 0;
  let bad = false;
  const errors = rows.map((r): LineErrors => {
    if (isBlank(r)) return {};
    filled++;
    const e: LineErrors = {};
    if (r.item.trim() === '') e.item = true;
    if (!isQty(r.qty)) e.qty = true;
    if (r.price.trim() !== '' && !isPrice(r.price)) e.price = true;
    if (r.discount.trim() !== '' && !isPercent(r.discount)) e.discount = true;
    if (Object.keys(e).length > 0) bad = true;

    return e;
  });

  return { ok: filled > 0 && !bad, errors, filled };
}

/** The lines the server receives: only what was typed, as text (never as numbers). */
export function buildLines(rows: LineRow[]): { item: string; qty: string; unit_price?: string; discount_pct?: string }[] {
  return rows
    .filter((r) => !isBlank(r))
    .map((r) => ({
      item: r.item.trim(),
      qty: r.qty.trim(),
      ...(r.price.trim() !== '' ? { unit_price: r.price.trim() } : {}),
      ...(r.discount.trim() !== '' ? { discount_pct: r.discount.trim() } : {}),
    }));
}

export type QuotationMove = 'sent' | 'accepted' | 'rejected' | 'cancelled';

/** What a person may do with a quotation next (the server's own flow; it refuses anything else). */
export function quotationMoves(status: string, canManage: boolean): QuotationMove[] {
  if (!canManage) return [];
  if (status === 'draft') return ['sent', 'cancelled'];
  if (status === 'sent') return ['accepted', 'rejected', 'cancelled'];
  if (status === 'accepted') return ['cancelled'];

  return [];
}

export type PurchaseMove = 'submit' | 'withdraw' | 'cancel';

/** What a person may do with a purchase order next. Cancelling needs a reason, so it is asked for separately. */
export function purchaseMoves(status: string, canManage: boolean): PurchaseMove[] {
  if (!canManage) return [];
  if (status === 'draft') return ['submit', 'cancel'];
  if (status === 'pending_approval') return ['withdraw'];
  if (status === 'approved') return ['cancel'];

  return [];
}

/** The colour of a status badge on quotations, orders, invoices and purchase orders. */
export function docTone(status: string): 'ok' | 'warn' | 'danger' | 'muted' {
  if (['accepted', 'confirmed', 'approved', 'completed', 'posted', 'converted'].includes(status)) return 'ok';
  if (['sent', 'pending_approval'].includes(status)) return 'warn';
  if (['rejected', 'expired'].includes(status)) return 'danger';

  return 'muted';
}

/** The text key of a status, or null when there is none (the raw word is then shown). */
export const STATUS_WORDS = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted', 'cancelled', 'confirmed', 'completed', 'posted', 'pending_approval', 'approved'] as const;

/** Where the server's complaints about `lines.N.field` belong: the N-th filled row (blank rows are not sent). */
export function lineErrorsFromServer(fields: Record<string, string>, rows: LineRow[]): LineErrors[] {
  const filled = rows.map((r, i) => (isBlank(r) ? -1 : i)).filter((i) => i >= 0);
  const out: LineErrors[] = rows.map(() => ({}));
  for (const key of Object.keys(fields)) {
    const m = /^lines\.(\d+)\.(item|qty|unit_price|discount_pct)$/.exec(key);
    if (!m) continue;
    const row = filled[parseInt(m[1], 10)];
    if (row === undefined) continue;
    const field = m[2] === 'unit_price' ? 'price' : m[2] === 'discount_pct' ? 'discount' : (m[2] as 'item' | 'qty');
    out[row] = { ...out[row], [field]: fields[key] || true };
  }

  return out;
}
