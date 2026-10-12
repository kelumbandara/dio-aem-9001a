import { router, SplashScreen, Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { SessionProvider, useSession } from '@/auth/session';
import { notifications } from '@/push/notifications';
import { localPath, screenFor } from '@/util/format';

void SplashScreen.preventAutoHideAsync();

export default function Root() {
  return (
    <SafeAreaProvider>
      <SessionProvider>
        <Gate />
      </SessionProvider>
    </SafeAreaProvider>
  );
}

function Gate() {
  const { ready, signedIn } = useSession();

  useEffect(() => {
    if (ready) void SplashScreen.hideAsync();
  }, [ready]);

  // a tap on a notification opens the screen it is about (only our own known paths; anything else is ignored)
  useEffect(() => {
    if (!signedIn) return;
    const open = (data: Record<string, unknown> | undefined) => {
      const target = screenFor(localPath(typeof data?.url === 'string' ? data.url : null));
      if (target) router.push(target as never);
    };
    const N = notifications();                        // null in Expo Go, where push cannot work: the rest of the app is unaffected
    if (!N) return;
    const sub = N.addNotificationResponseReceivedListener((r) => open(r.notification.request.content.data as Record<string, unknown> | undefined));

    return () => sub.remove();
  }, [signedIn]);

  if (!ready) return null;

  return (
    <>
      <StatusBar style="auto" />
      <Stack screenOptions={{ headerShown: false }}>
        <Stack.Protected guard={signedIn}>
          <Stack.Screen name="(app)" />
        </Stack.Protected>
        <Stack.Protected guard={!signedIn}>
          <Stack.Screen name="sign-in" />
        </Stack.Protected>
      </Stack>
    </>
  );
}
