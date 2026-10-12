import { useState } from 'react';
import { View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { useSession } from '@/auth/session';
import { LOCALES } from '@/i18n';
import { Body, Button, Choice, Field, Notice, Screen, Title } from '@/ui/kit';
import { normalizeServerUrl } from '@/util/format';

export default function SignIn() {
  const { t, signIn, serverUrl, locale, setLocale } = useSession();
  const insets = useSafeAreaInsets();
  const [server, setServer] = useState(serverUrl);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [needCode, setNeedCode] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [serverError, setServerError] = useState<string | null>(null);

  async function submit() {
    setError(null);
    setServerError(null);
    const address = normalizeServerUrl(server);
    if (address === null) {
      setServerError(t('server_invalid'));

      return;
    }
    setServer(address); // a pasted link such as .../api/v1/health becomes just the address
    setBusy(true);
    try {
      await signIn(address, email, password, needCode ? code : undefined);
    } catch (e) {
      if (e instanceof ApiError && e.code === 'auth.mfa_required') {
        setNeedCode(true);
        setError(t('mfa_needed'));
      } else if (e instanceof ApiError && e.code === 'auth.mfa_enrollment_required') {
        setError(t('enrol_on_web'));
      } else if (e instanceof ApiError && e.network) {
        setError(t('error_network'));
      } else if (e instanceof ApiError) {
        setError(e.message);
      } else {
        setError(t('error_generic'));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <View style={{ height: insets.top + 16 }} />
      <Title>{t('app_name')}</Title>
      <Body muted>{t('sign_in')}</Body>
      {error ? <Notice text={error} tone={needCode && error === t('mfa_needed') ? 'warn' : 'danger'} /> : null}
      <Field label={t('server')} hint={t('server_hint')} error={serverError ?? undefined} value={server} onChangeText={setServer} autoCapitalize="none" autoCorrect={false} keyboardType="url" textContentType="URL" />
      <Field label={t('email')} value={email} onChangeText={setEmail} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" textContentType="username" autoComplete="email" />
      <Field label={t('password')} value={password} onChangeText={setPassword} secureTextEntry textContentType="password" autoComplete="current-password" />
      {needCode ? <Field label={t('mfa_code')} value={code} onChangeText={setCode} keyboardType="number-pad" maxLength={20} autoComplete="one-time-code" textContentType="oneTimeCode" /> : null}
      <Button label={busy ? t('signing_in') : t('sign_in')} onPress={submit} busy={busy} disabled={!email || !password || (needCode && !code)} />
      <Choice label={t('language')} options={LOCALES.map((l) => ({ value: l.code, label: l.label }))} value={locale} onChange={setLocale} />
    </Screen>
  );
}
