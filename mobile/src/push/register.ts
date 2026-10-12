import Constants from 'expo-constants';
import * as Device from 'expo-device';
import { Platform } from 'react-native';

import type { Client } from '@/api/client';
import type { Locale } from '@/i18n';

import { notifications } from './notifications';

export type PushState = 'on' | 'off' | 'unavailable';

/**
 * Asks for permission and tells the server this phone's push address. Returns what happened, never throws: push is a convenience,
 * the app works without it. Push needs a built app (not Expo Go), a real phone and, in a store build, the project id of the Expo account.
 */
export async function registerForPush(client: Client, locale: Locale, appVersion: string): Promise<PushState> {
  try {
    const N = notifications();
    if (!N || !Device.isDevice) return 'unavailable';
    if (Platform.OS === 'android') {
      await N.setNotificationChannelAsync('default', { name: 'DIO ERP', importance: N.AndroidImportance.HIGH });
    }
    let { status } = await N.getPermissionsAsync();
    if (status !== 'granted') status = (await N.requestPermissionsAsync()).status;
    if (status !== 'granted') return 'off';

    const projectId = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId ?? Constants.easConfig?.projectId;
    const token = (await N.getExpoPushTokenAsync(projectId ? { projectId } : undefined)).data;
    await client.post('/devices', { push_token: token, platform: Platform.OS === 'ios' ? 'ios' : 'android', device_name: Device.modelName ?? undefined, app_version: appVersion, locale });

    return 'on';
  } catch {
    return 'unavailable';
  }
}
