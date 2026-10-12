import Constants from 'expo-constants';

import { registerForPush } from '@/push/register';
import { inExpoGo, notifications, resetNotificationsForTests } from '@/push/notifications';
import type { Client } from '@/api/client';

// importing the real library inside Expo Go stops the app: this stand-in notes whenever it is loaded, so the test can prove it is not
const loads = { count: 0 };
jest.mock('expo-notifications', () => {
  (globalThis as unknown as { __pushLoads: { count: number } }).__pushLoads.count++;
  throw new Error('stand-in: the library cannot work here');
});

jest.mock('expo-constants', () => ({
  __esModule: true,
  default: { executionEnvironment: 'storeClient' },
  ExecutionEnvironment: { StoreClient: 'storeClient', Bare: 'bare', Standalone: 'standalone' },
}));
jest.mock('expo-device', () => ({ isDevice: true, modelName: 'Test phone' }));

(globalThis as unknown as { __pushLoads: { count: number } }).__pushLoads = loads;

const setEnvironment = (value: string) => {
  (Constants as unknown as { executionEnvironment: string }).executionEnvironment = value;
};

describe('push notifications in Expo Go', () => {
  beforeEach(() => {
    setEnvironment('storeClient');
    resetNotificationsForTests();
  });

  it('knows it is in Expo Go and does not load the notifications library', () => {
    expect(inExpoGo()).toBe(true);
    expect(notifications()).toBeNull();
    expect(loads.count).toBe(0);
  });

  it('simply reports push as unavailable instead of failing, and tells the server nothing', async () => {
    const post = jest.fn();
    const client = { get: jest.fn(), post, del: jest.fn() } as unknown as Client;
    await expect(registerForPush(client, 'en', '1.0.0')).resolves.toBe('unavailable');
    expect(post).not.toHaveBeenCalled();
    expect(loads.count).toBe(0);
  });
});

describe('push notifications in a built app', () => {
  it('tries to load the library, and falls back to "unavailable" if it cannot', async () => {
    setEnvironment('standalone');
    resetNotificationsForTests();
    expect(inExpoGo()).toBe(false);
    expect(notifications()).toBeNull();                         // the stand-in above fails to load: handled, not fatal
    expect(loads.count).toBe(1);                                // in a built app the library is tried
    const client = { get: jest.fn(), post: jest.fn(), del: jest.fn() } as unknown as Client;
    await expect(registerForPush(client, 'en', '1.0.0')).resolves.toBe('unavailable');
  });
});
