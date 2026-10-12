import { en } from '@/i18n/en';
import { translate, pickLocale, isLocale } from '@/i18n';
import { si } from '@/i18n/si';
import { ta } from '@/i18n/ta';

const placeholders = (s: string) => (s.match(/\{\w+\}/g) ?? []).sort().join(',');

describe('translations', () => {
  it.each([['Sinhala', si], ['Tamil', ta]] as const)('%s has only real keys, no empty texts and the same placeholders as English', (_name, dict) => {
    for (const [key, text] of Object.entries(dict)) {
      expect(Object.keys(en)).toContain(key);
      expect((text as string).trim()).not.toBe('');
      expect(placeholders(text as string)).toBe(placeholders((en as Record<string, string>)[key]));
    }
  });

  it('has no empty English text', () => {
    for (const text of Object.values(en)) expect(text.trim()).not.toBe('');
  });

  it('fills placeholders, and shows English for what is not translated yet', () => {
    expect(translate('en', 'hello', { name: 'Amal' })).toBe('Hello, Amal');
    expect(translate('si', 'hello', { name: 'Amal' })).toContain('Amal');
    expect(translate('si', 'no_orders')).toBe(en.no_orders);
    expect(translate('ta', 'sent_n', { count: 3 })).toBe('3 sent');
    expect(translate('en', 'hello')).toBe('Hello, ');
  });

  it('picks the phone language when we have it', () => {
    expect(pickLocale('si')).toBe('si');
    expect(pickLocale('TA-LK')).toBe('ta');
    expect(pickLocale('fr')).toBe('en');
    expect(pickLocale(null)).toBe('en');
    expect(isLocale('xx')).toBe(false);
  });
});
