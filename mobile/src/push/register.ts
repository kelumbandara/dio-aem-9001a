import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';

import type { Client } from '@/api/client';
import type { Locale } from '@/i18n';

Notifications.setNotificationHandler({
  handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }),
});

export type PushState = 'on' | 'off' | 'unavailable';

/**
 * Asks for permission and tells the server this phone's push address. Returns what happened, never throws: push is a convenience,
 * the app works without it. (Push needs a real phone and, in a built app, the project id of the Expo account.)
 */
export async function registerForPush(client: Client, locale: Locale, appVersion: string): Promise<PushState> {
  try {
    if (!Device.isDevice) return 'unavailable';
    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('default', { name: 'DIO ERP', importance: Notifications.AndroidImportance.HIGH });
    }
    let { status } = await Notifications.getPermissionsAsync();
    if (status !== 'granted') status = (await Notifications.requestPermissionsAsync()).status;
    if (status !== 'granted') return 'off';

    const projectId = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId ?? Constants.easConfig?.projectId;
    const token = (await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined)).data;
    await client.post('/devices', { push_token: token, platform: Platform.OS === 'ios' ? 'ios' : 'android', device_name: Device.modelName ?? undefined, app_version: appVersion, locale });

    return 'on';
  } catch {
    return 'unavailable';
  }
}
