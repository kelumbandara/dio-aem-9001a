import { router } from 'expo-router';

import { APP_VERSION, useSession } from '@/auth/session';
import { LOCALES } from '@/i18n';
import { confirmAction } from '@/ui/confirm';
import { Body, Button, Card, Choice, Screen } from '@/ui/kit';

export default function More() {
  const { t, user, config, serverUrl, locale, setLocale, signOut, pending } = useSession();

  async function out() {
    if (await confirmAction(t('sign_out_confirm'), t('sign_out'), t('cancel'))) await signOut();
  }

  return (
    <Screen>
      <Card>
        <Body bold>{user?.name}</Body>
        <Body muted>{user?.email}</Body>
        <Body muted>{config?.company} · {serverUrl}</Body>
      </Card>
      <Card onPress={() => router.push('/more/notifications' as never)} accessibilityLabel={t('notifications')}>
        <Body bold>{t('notifications')}</Body>
      </Card>
      <Card onPress={() => router.push('/more/queue' as never)} accessibilityLabel={t('queue_title')}>
        <Body bold>{t('queue_title')}</Body>
        <Body muted>{pending > 0 ? t('waiting_to_send', { count: pending }) : t('queue_empty')}</Body>
      </Card>
      <Choice label={t('language')} options={LOCALES.map((l) => ({ value: l.code, label: l.label }))} value={locale} onChange={setLocale} />
      <Body muted>{t('app_version', { version: APP_VERSION })}</Body>
      <Button label={t('sign_out')} onPress={out} kind="secondary" />
    </Screen>
  );
}
