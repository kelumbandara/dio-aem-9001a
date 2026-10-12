import { formatDate, formatDecimal, formatMoney, isQty, localPath, normalizeServerUrl, qtyOrZero, screenFor, today } from '@/util/format';

describe('server address', () => {
  it('keeps only scheme, host and port, whatever link was pasted', () => {
    expect(normalizeServerUrl('http://192.168.0.105:8000')).toBe('http://192.168.0.105:8000');
    expect(normalizeServerUrl(' http://192.168.0.105:8000/api/v1/health ')).toBe('http://192.168.0.105:8000');
    expect(normalizeServerUrl('https://erp.mycompany.lk/login?x=1#top')).toBe('https://erp.mycompany.lk');
    expect(normalizeServerUrl('HTTPS://ERP.MyCompany.lk///')).toBe('https://erp.mycompany.lk');
  });

  it('refuses what is not a web address', () => {
    for (const bad of ['', '192.168.0.105:8000', 'ftp://x.test', 'javascript:alert(1)', 'http://', 'https:// spaced.test', 'erp.mycompany.lk']) {
      expect(normalizeServerUrl(bad)).toBeNull();
    }
  });
});

describe('quantities', () => {
  it('accepts what the server accepts and nothing else', () => {
    for (const ok of ['0', '5', '12.5', '0.000001', '999999999999', ' 7 ']) expect(isQty(ok)).toBe(true);
    for (const bad of ['', '-1', '1e3', '1,000', 'abc', '1.1234567', '1.', '.5', '1000000000000', '1 2']) expect(isQty(bad)).toBe(false);
  });

  it('treats an empty optional box as zero', () => {
    expect(qtyOrZero('')).toBe('0');
    expect(qtyOrZero('  ')).toBe('0');
    expect(qtyOrZero(' 3 ')).toBe('3');
  });
});

describe('numbers', () => {
  it('groups thousands without touching the digits', () => {
    expect(formatDecimal('1234567.5')).toBe('1,234,567.5');
    expect(formatDecimal('1234567.500000')).toBe('1,234,567.5');
    expect(formatDecimal('0.1000')).toBe('0.1');
    expect(formatDecimal('1000')).toBe('1,000');
    expect(formatDecimal('12345678901234567890.123456789')).toBe('12,345,678,901,234,567,890.123456789');   // far beyond float precision
  });

  it('pads or cuts to the places asked for, cutting rather than rounding', () => {
    expect(formatDecimal('5', 2)).toBe('5.00');
    expect(formatDecimal('5.129', 2)).toBe('5.12');
    expect(formatMoney('1250.5')).toBe('LKR 1,250.50');
    expect(formatMoney('-40')).toBe('LKR -40.00');
  });

  it('shows a dash for nothing, and never "-0"', () => {
    expect(formatDecimal(null)).toBe('—');
    expect(formatDecimal(undefined)).toBe('—');
    expect(formatDecimal('abc')).toBe('—');
    expect(formatMoney(null)).toBe('—');
    expect(formatDecimal('-0.00', 2)).toBe('0.00');
  });
});

describe('dates', () => {
  it('shows the calendar date as written, whatever the phone\'s time zone', () => {
    expect(formatDate('2026-11-04')).toBe('4 Nov 2026');
    expect(formatDate('2026-12-31T23:59:59+05:30')).toBe('31 Dec 2026');
    expect(formatDate(null)).toBe('—');
    expect(formatDate('soon')).toBe('—');
  });

  it('writes today on the phone calendar', () => {
    expect(today(new Date(2026, 10, 4, 23, 59))).toBe('2026-11-04');
    expect(today(new Date(2027, 0, 5, 0, 1))).toBe('2027-01-05');
  });
});

describe('opening what the server points at', () => {
  const id = '3f2b1c9e-8a4d-4c7e-9b1a-2d5e6f7a8b9c';

  it('keeps only our own paths from a notification', () => {
    expect(localPath('/approvals/abc')).toBe('/approvals/abc');
    expect(localPath('https://erp.example.test/approvals/abc?x=1')).toBe('/approvals/abc?x=1');
    expect(localPath('//evil.example/phish')).toBeNull();                 // protocol-relative: not ours, never opened
    expect(localPath(null)).toBeNull();
    expect(localPath('not a url')).toBeNull();
  });

  it('maps known paths to app screens and leaves the rest for the web', () => {
    expect(screenFor(`/approvals/${id}`)).toBe(`/approvals/${id}`);
    expect(screenFor(`/manufacturing/orders/${id}`)).toBe(`/work/${id}`);
    expect(screenFor('/inventory/stock/items/ITM-001')).toBe('/stock/ITM-001');
    expect(screenFor('/sales/invoices/abc')).toBeNull();
    expect(screenFor('/approvals/not-a-uuid')).toBeNull();
    expect(screenFor('/approvals/' + id + '/../../admin')).toBeNull();
    expect(screenFor(null)).toBeNull();
  });
});
