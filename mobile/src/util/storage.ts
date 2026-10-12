import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import type { KeyValueStore } from '@/offline/queue';

/**
 * Secrets (the sign-in token) go to the phone's secure store (Keychain / Keystore). On the web preview, which has none, the browser's
 * local storage is used instead; that is only for development.
 */
export const secure = {
  async get(key: string): Promise<string | null> {
    try {
      return Platform.OS === 'web' ? localStorage.getItem(key) : await SecureStore.getItemAsync(key);
    } catch {
      return null;
    }
  },
  async set(key: string, value: string | null): Promise<void> {
    try {
      if (Platform.OS === 'web') {
        if (value === null) localStorage.removeItem(key);
        else localStorage.setItem(key, value);
      } else if (value === null) {
        await SecureStore.deleteItemAsync(key);
      } else {
        await SecureStore.setItemAsync(key, value);
      }
    } catch {
      // nothing sensible to do: the person will simply sign in again next time
    }
  },
};

/** Plain settings and the offline queue (not secrets). */
export const plain: KeyValueStore = {
  getItem: (key) => AsyncStorage.getItem(key),
  setItem: (key, value) => AsyncStorage.setItem(key, value),
};
