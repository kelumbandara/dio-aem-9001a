import { Stack } from 'expo-router';

import { useTheme } from '@/ui/kit';

/** The header style shared by the stacks inside each tab. */
export function StackLayout({ title }: { title: string }) {
  const th = useTheme();

  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: th.card },
        headerTintColor: th.text,
        headerTitleStyle: { fontWeight: '700' },
        contentStyle: { backgroundColor: th.bg },
      }}>
      <Stack.Screen name="index" options={{ title }} />
    </Stack>
  );
}
