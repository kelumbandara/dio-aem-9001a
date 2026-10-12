import Constants, { ExecutionEnvironment } from 'expo-constants';
import { Platform } from 'react-native';

export type NotificationsModule = typeof import('expo-notifications');

let loaded: NotificationsModule | null | undefined;

/** True inside the Expo Go test app, which cannot receive remote push notifications (Expo removed them there with SDK 53). */
export function inExpoGo(): boolean {
  return Constants.executionEnvironment === ExecutionEnvironment.StoreClient;
}

/**
 * The notifications library, or null where it cannot work (Expo Go, the web preview). It is loaded only when needed: merely
 * importing it inside Expo Go stops the whole app with an error, so nothing may import it at the top of a file.
 */
export function notifications(): NotificationsModule | null {
  if (loaded !== undefined) return loaded;
  if (inExpoGo() || Platform.OS === 'web') {
    loaded = null;

    return loaded;
  }
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const module: NotificationsModule = require('expo-notifications');
    module.setNotificationHandler({
      handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }),
    });
    loaded = module;
  } catch {
    loaded = null;
  }

  return loaded;
}

/** For tests: forget what was loaded. */
export function resetNotificationsForTests(): void {
  loaded = undefined;
}
