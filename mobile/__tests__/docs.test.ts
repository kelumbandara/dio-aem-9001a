import { buildLines, checkLines, docTone, emptyRow, isBlank, isPercent, isPrice, isQty, lineErrorsFromServer, purchaseMoves, quotationMoves, STATUS_WORDS, type LineRow } from '@/features/trade/docs';
import { en } from '@/i18n/en';

const row = (item: string, qty: string, price = '', discount = ''): LineRow => ({ item, qty, price, discount });

describe('quantities, prices and discounts', () => {
  it('accepts what the server accepts and nothing else', () => {
    expect(['1', '12', '2.5', '0.000001', '999999999999'].every(isQty)).toBe(true);
    expect(['0', '0.0', '-1', '1e3', '1,5', 'abc', '', '1.1234567', '1234567890123'].some(isQty)).toBe(false);
    expect(['0', '150', '150.5', '150.1234', '99999999999999'].every(isPrice)).toBe(true);
    expect(['-1', '1.12345', 'abc', '1e2', ''].some(isPrice)).toBe(false);
    expect(['0', '10', '100', '12.5', '99.9999'].every(isPercent)).toBe(true);
    expect(['101', '100.5', '-1', 'x', ''].some(isPercent)).toBe(false);
  });
});

describe('checkLines', () => {
  it('needs at least one filled line', () => {
    expect(checkLines([emptyRow(), emptyRow()])).toEqual({ ok: false, errors: [{}, {}], filled: 0 });
  });

  it('ignores blank rows between filled ones and marks each fault on its own row', () => {
    const rows = [row('A1', '2'), emptyRow(), row('', '3'), row('B2', '0'), row('C3', '1', 'abc'), row('D4', '1', '', '150')];
    const r = checkLines(rows);
    expect(r.ok).toBe(false);
    expect(r.filled).toBe(5);
    expect(r.errors).toEqual([{}, {}, { item: true }, { qty: true }, { price: true }, { discount: true }]);
  });

  it('accepts price and discount left empty (the price list is used) and a clean set of lines', () => {
    const r = checkLines([row('A1', '2'), row('B2', '1.5', '120.50', '10')]);
    expect(r).toEqual({ ok: true, errors: [{}, {}], filled: 2 });
  });

  it('treats a row with only a price typed as a line with a missing item and quantity', () => {
    const r = checkLines([row('', '', '5')]);
    expect(r.ok).toBe(false);
    expect(r.errors[0]).toEqual({ item: true, qty: true });
    expect(isBlank(row('', '', '5'))).toBe(false);
  });
});

describe('buildLines', () => {
  it('sends only what was typed, as text, trimmed, and skips blank rows', () => {
    expect(buildLines([row(' A1 ', ' 2 '), emptyRow(), row('B2', '1.50', ' 120 ', ' 5 ')])).toEqual([
      { item: 'A1', qty: '2' },
      { item: 'B2', qty: '1.50', unit_price: '120', discount_pct: '5' },
    ]);
  });

  it('never turns a number into a float: the text stays exactly as typed', () => {
    const [line] = buildLines([row('A1', '0.1', '19.99')]);
    expect(line.qty).toBe('0.1');
    expect(line.unit_price).toBe('19.99');
    expect(typeof line.qty).toBe('string');
  });
});

describe('lineErrorsFromServer', () => {
  it('puts the server\'s line complaints on the right row even when blank rows were skipped', () => {
    const rows = [row('A1', '1'), emptyRow(), row('B2', 'x'), row('C3', '1', 'bad')];
    const out = lineErrorsFromServer({ 'lines.1.qty': 'bad', 'lines.2.unit_price': 'bad', 'lines.0.item': 'unknown', customer: 'unknown' }, rows);
    expect(out).toEqual([{ item: 'unknown' }, {}, { qty: 'bad' }, { price: 'bad' }]);
  });

  it('keeps the sentence the server gave, such as the missing price, to show on that box', () => {
    const out = lineErrorsFromServer({ 'lines.0.unit_price': 'No price is set for ITM-00001; enter one.' }, [row('ITM-00001', '2')]);
    expect(out[0].price).toBe('No price is set for ITM-00001; enter one.');
  });

  it('falls back to a plain flag when the server gave no sentence', () => {
    expect(lineErrorsFromServer({ 'lines.0.qty': '' }, [row('A', '1')])[0].qty).toBe(true);
  });

  it('ignores complaints that point at no row, and any other field', () => {
    expect(lineErrorsFromServer({ 'lines.9.item': 'x', 'lines.0.tax_code': 'x', notes: 'x' }, [row('A', '1')])).toEqual([{}]);
  });
});

describe('what a person may do next', () => {
  it('follows the server\'s own flow for quotations, and nothing without permission', () => {
    expect(quotationMoves('draft', true)).toEqual(['sent', 'cancelled']);
    expect(quotationMoves('sent', true)).toEqual(['accepted', 'rejected', 'cancelled']);
    expect(quotationMoves('accepted', true)).toEqual(['cancelled']);
    expect(['rejected', 'converted', 'cancelled', 'expired'].flatMap((s) => quotationMoves(s, true))).toEqual([]);
    expect(['draft', 'sent', 'accepted'].flatMap((s) => quotationMoves(s, false))).toEqual([]);
  });

  it('follows the flow of a purchase order, and nothing without permission', () => {
    expect(purchaseMoves('draft', true)).toEqual(['submit', 'cancel']);
    expect(purchaseMoves('pending_approval', true)).toEqual(['withdraw']);
    expect(purchaseMoves('approved', true)).toEqual(['cancel']);
    expect(['completed', 'cancelled'].flatMap((s) => purchaseMoves(s, true))).toEqual([]);
    expect(['draft', 'pending_approval', 'approved'].flatMap((s) => purchaseMoves(s, false))).toEqual([]);
  });
});

describe('status colours and words', () => {
  it('colours the states people care about', () => {
    expect(docTone('accepted')).toBe('ok');
    expect(docTone('confirmed')).toBe('ok');
    expect(docTone('sent')).toBe('warn');
    expect(docTone('pending_approval')).toBe('warn');
    expect(docTone('rejected')).toBe('danger');
    expect(docTone('draft')).toBe('muted');
    expect(docTone('something-new')).toBe('muted');
  });

  it('has an English word for every status the screens know', () => {
    for (const s of STATUS_WORDS) expect(en).toHaveProperty(`doc_status_${s}`);
  });
});
