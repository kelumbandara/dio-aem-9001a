import { en } from './en';
import { si } from './si';
import { ta } from './ta';

export type Locale = 'en' | 'si' | 'ta';
export type Key = keyof typeof en;

export const LOCALES: { code: Locale; label: string }[] = [
  { code: 'en', label: 'English' },
  { code: 'si', label: 'සිංහල' },
  { code: 'ta', label: 'தமிழ்' },
];

const dictionaries: Record<Locale, Partial<Record<Key, string>>> = { en, si, ta };

/** Text in the chosen language; anything not yet translated shows in English, never as a bare key. */
export function translate(locale: Locale, key: Key, params: Record<string, string | number> = {}): string {
  const text = dictionaries[locale][key] ?? en[key];

  return text.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? ''));
}

export function isLocale(value: string | null | undefined): value is Locale {
  return value === 'en' || value === 'si' || value === 'ta';
}

/** The phone's own language when we have it, else English. */
export function pickLocale(deviceLanguage: string | null | undefined): Locale {
  const code = (deviceLanguage ?? '').slice(0, 2).toLowerCase();

  return isLocale(code) ? code : 'en';
}
