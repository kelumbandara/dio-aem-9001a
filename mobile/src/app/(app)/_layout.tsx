import { Tabs } from 'expo-router';
import { useEffect } from 'react';
import { Text } from 'react-native';

import { APP_VERSION, useSession } from '@/auth/session';
import { registerForPush } from '@/push/register';
import { useTheme } from '@/ui/kit';

function TabIcon({ glyph, focused }: { glyph: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.55 }}>{glyph}</Text>;
}

const icon = (glyph: string) => function Icon({ focused }: { focused: boolean }) {
  return <TabIcon glyph={glyph} focused={focused} />;
};

export default function AppLayout() {
  const { t, features, client, locale } = useSession();
  const th = useTheme();

  // tell the server this phone's push address (again after a language change, so messages arrive in that language)
  useEffect(() => {
    void registerForPush(client, locale, APP_VERSION);
  }, [client, locale]);

  // Up to six tabs fit: people who work in trade or approvals reach Stock and the assistant from the home page instead.
  const primary = [features.approvals, features.sales, features.purchasing, features.production || features.downtime || features.quality].filter(Boolean).length;
  const extras = primary <= 2;

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: th.primary,
        tabBarInactiveTintColor: th.muted,
        tabBarStyle: { backgroundColor: th.card, borderTopColor: th.border },
        tabBarLabelStyle: { fontSize: 12 },
      }}>
      <Tabs.Screen name="index" options={{ title: t('app_name'), tabBarIcon: icon('🏠') }} />
      <Tabs.Screen name="approvals" options={{ title: t('approvals_title'), tabBarIcon: icon('✅'), href: features.approvals ? undefined : null }} />
      <Tabs.Screen name="sales" options={{ title: t('sales_title'), tabBarIcon: icon('🧾'), href: features.sales ? undefined : null }} />
      <Tabs.Screen name="purchasing" options={{ title: t('purchasing_title'), tabBarIcon: icon('🛒'), href: features.purchasing ? undefined : null }} />
      <Tabs.Screen name="work" options={{ title: t('work_title'), tabBarIcon: icon('🏭'), href: features.production || features.downtime || features.quality ? undefined : null }} />
      <Tabs.Screen name="stock" options={{ title: t('stock_title'), tabBarIcon: icon('📦'), href: features.stock && extras ? undefined : null }} />
      <Tabs.Screen name="assistant" options={{ title: t('assistant_title'), tabBarIcon: icon('💬'), href: features.assistant && extras ? undefined : null }} />
      <Tabs.Screen name="more" options={{ title: t('more_title'), tabBarIcon: icon('⋯') }} />
    </Tabs>
  );
}
