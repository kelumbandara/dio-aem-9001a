import { Stack } from 'expo-router';

import { useSession } from '@/auth/session';
import { useTheme } from '@/ui/kit';

export default function MoreLayout() {
  const { t } = useSession();
  const th = useTheme();

  return (
    <Stack screenOptions={{ headerStyle: { backgroundColor: th.card }, headerTintColor: th.text, headerTitleStyle: { fontWeight: '700' }, contentStyle: { backgroundColor: th.bg } }}>
      <Stack.Screen name="index" options={{ title: t('more_title') }} />
      <Stack.Screen name="notifications" options={{ title: t('notifications') }} />
      <Stack.Screen name="queue" options={{ title: t('queue_title') }} />
    </Stack>
  );
}
