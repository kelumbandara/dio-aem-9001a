/** A quantity the server accepts: digits, at most 6 decimals, no sign, no exponent. */
export function isQty(value: string): boolean {
  return /^\d{1,12}(\.\d{1,6})?$/.test(value.trim());
}

/** Zero or empty counts as "none" for the optional rejected / rework boxes. */
export function qtyOrZero(value: string): string {
  const v = value.trim();

  return v === '' ? '0' : v;
}

/** Decimal string to display text with thousands separators; never goes through a float. */
export function formatDecimal(value: string | null | undefined, places?: number): string {
  if (value === null || value === undefined || !/^-?\d+(\.\d+)?$/.test(value)) return '—';
  const negative = value.startsWith('-');
  const [int, dec = ''] = value.replace('-', '').split('.');
  const frac = places === undefined ? dec.replace(/0+$/, '') : dec.padEnd(places, '0').slice(0, places);
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const text = grouped + (frac ? '.' + frac : '');

  return negative && /[1-9]/.test(text) ? '-' + text : text;
}

export function formatMoney(value: string | null | undefined): string {
  return value === null || value === undefined ? '—' : 'LKR ' + formatDecimal(value, 2);
}

/** "2026-11-04" or an ISO date-time to "4 Nov 2026" (the calendar date as written, not shifted by the phone's time zone). */
export function formatDate(value: string | null | undefined): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value ?? '');
  if (!m) return '—';
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  return `${parseInt(m[3], 10)} ${months[parseInt(m[2], 10) - 1]} ${m[1]}`;
}

/** Today as YYYY-MM-DD on the phone's calendar. */
export function today(now: Date = new Date()): string {
  const p = (n: number) => String(n).padStart(2, '0');

  return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`;
}

/** A relative web path from a notification's url ("/approvals/abc"), or null when it is not one of ours. */
export function localPath(url: string | null | undefined): string | null {
  if (!url) return null;
  if (url.startsWith('/') && !url.startsWith('//')) return url;
  try {
    const u = new URL(url);

    return u.pathname + u.search;
  } catch {
    return null;
  }
}

/** Which screen a path from the server (a notification or an AI reference) opens in the app, or null for "open on the web". */
export function screenFor(path: string | null): string | null {
  if (!path) return null;
  const approval = /^\/approvals\/([0-9a-f-]{36})\/?$/i.exec(path);
  if (approval) return `/approvals/${approval[1]}`;
  const order = /^\/manufacturing\/orders\/([0-9a-f-]{36})\/?$/i.exec(path);
  if (order) return `/work/${order[1]}`;
  const stock = /^\/inventory\/stock\/items\/([A-Za-z0-9._-]+)\/?$/.exec(path);
  if (stock) return `/stock/${stock[1]}`;

  return null;
}

/**
 * The server address as the app needs it: only the scheme, host and port. People paste whole links (".../api/v1/health"); the path
 * is dropped. Returns null when it is not an http(s) address.
 */
export function normalizeServerUrl(input: string): string | null {
  const text = input.trim();
  if (!/^https?:\/\/[^\s/]+/i.test(text)) return null;
  try {
    const u = new URL(text);

    return u.protocol === 'http:' || u.protocol === 'https:' ? u.origin : null;
  } catch {
    return null;
  }
}
