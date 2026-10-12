import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Localization from 'expo-localization';
import { createContext, use, useCallback, useEffect, useState, type PropsWithChildren } from 'react';
import { AppState, Platform } from 'react-native';

import { ApiError, createClient, type Client } from '@/api/client';
import type { LoginResult, MobileConfig, User } from '@/api/types';
import { isLocale, pickLocale, translate, type Key, type Locale } from '@/i18n';
import { OfflineQueue, type FlushResult } from '@/offline/queue';
import { featuresFor, isOlderVersion, type Features } from '@/util/permissions';
import { plain, secure } from '@/util/storage';

const DEFAULT_SERVER = process.env.EXPO_PUBLIC_API_URL ?? ((Constants.expoConfig?.extra as { apiUrl?: string } | undefined)?.apiUrl ?? '');
export const APP_VERSION = Constants.expoConfig?.version ?? '1.0.0';

const NO_FEATURES = featuresFor([], []);

/** What the single API client reads at call time. One person is signed in per app process, so this lives outside React. */
const runtime = { token: null as string | null, serverUrl: DEFAULT_SERVER, locale: pickLocale(Localization.getLocales()[0]?.languageCode), onUnauthorized: () => {} };
const client = createClient({ baseUrl: () => runtime.serverUrl, token: () => runtime.token, locale: () => runtime.locale, onUnauthorized: () => runtime.onUnauthorized() });
const queue = new OfflineQueue(plain);

type SessionValue = {
  ready: boolean;
  signedIn: boolean;
  serverUrl: string;
  user: User | null;
  config: MobileConfig | null;
  features: Features;
  locale: Locale;
  client: Client;
  queue: OfflineQueue;
  pending: number;
  updateRequired: boolean;
  t: (key: Key, params?: Record<string, string | number>) => string;
  signIn: (serverUrl: string, email: string, password: string, mfaCode?: string) => Promise<void>;
  signOut: () => Promise<void>;
  setLocale: (locale: Locale) => void;
  refreshQueue: () => Promise<void>;
  flushQueue: () => Promise<FlushResult>;
};

const SessionContext = createContext<SessionValue | null>(null);

export function useSession(): SessionValue {
  const value = use(SessionContext);
  if (!value) throw new Error('useSession must be used inside <SessionProvider>.');

  return value;
}

export function SessionProvider({ children }: PropsWithChildren) {
  const [ready, setReady] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [serverUrl, setServerUrl] = useState(DEFAULT_SERVER);
  const [user, setUser] = useState<User | null>(null);
  const [config, setConfig] = useState<MobileConfig | null>(null);
  const [locale, setLocaleState] = useState<Locale>(runtime.locale);
  const [pending, setPending] = useState(0);

  const refreshQueue = useCallback(async () => {
    setPending((await queue.load()).filter((i) => i.status === 'pending').length);
  }, []);

  const flushQueue = useCallback(async () => {
    const result = await queue.flush(client);
    await refreshQueue();

    return result;
  }, [refreshQueue]);

  const clearSession = useCallback(async () => {
    setToken(null);
    setUser(null);
    setConfig(null);
    runtime.token = null;
    await secure.set('token', null);
  }, []);
  useEffect(() => {
    runtime.onUnauthorized = () => {
      void clearSession();
    };
  }, [clearSession]);

  const loadProfile = useCallback(async () => {
    const [me, cfg] = await Promise.all([client.get<{ data: User }>('/me'), client.get<{ data: MobileConfig }>('/mobile/config')]);
    setUser(me.data);
    setConfig(cfg.data);
  }, []);

  // start-up: remembered settings, then check the stored sign-in is still good
  useEffect(() => {
    (async () => {
      const [savedToken, savedServer, savedLocale] = await Promise.all([secure.get('token'), plain.getItem('server-url'), plain.getItem('locale')]);
      if (savedServer) setServerUrl(savedServer);
      if (isLocale(savedLocale)) setLocaleState(savedLocale);
      runtime.token = savedToken;
      runtime.serverUrl = savedServer || DEFAULT_SERVER;
      if (isLocale(savedLocale)) runtime.locale = savedLocale;
      if (savedToken) {
        setToken(savedToken);
        try {
          await loadProfile();
        } catch (e) {
          // an ended session is handled by the client; a missing connection keeps the person signed in (the queue still works)
          if (!(e instanceof ApiError && e.network)) await clearSession();
        }
      }
      await refreshQueue();
      setReady(true);
    })();
  }, [loadProfile, clearSession, refreshQueue]);

  // send what was entered offline when the app opens, comes back to the front, and every half minute while something waits
  useEffect(() => {
    if (!token) return;
    void Promise.resolve().then(flushQueue);
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') void flushQueue();
    });
    const timer = setInterval(() => {
      if (pending > 0) void flushQueue();
    }, 30000);

    return () => {
      sub.remove();
      clearInterval(timer);
    };
  }, [token, pending, flushQueue]);

  const signIn = useCallback(
    async (url: string, email: string, password: string, mfaCode?: string) => {
      const clean = url.trim().replace(/\/+$/, '');
      runtime.serverUrl = clean;
      const res = await client.post<{ data: LoginResult }>('/auth/login', {
        email: email.trim(),
        password,
        device_name: `${Device.modelName ?? 'Phone'} (${Platform.OS})`,
        ...(mfaCode ? { mfa_code: mfaCode.trim() } : {}),
      });
      runtime.token = res.data.token;
      setServerUrl(clean);
      setToken(res.data.token);
      await Promise.all([secure.set('token', res.data.token), plain.setItem('server-url', clean)]);
      try {
        await loadProfile();
      } catch (e) {
        await clearSession();
        throw e;
      }
    },
    [loadProfile, clearSession],
  );

  const signOut = useCallback(async () => {
    try {
      await client.post('/auth/logout');
    } catch {
      // signed out on this phone either way; the server drops the token when it expires
    }
    await clearSession();
  }, [clearSession]);

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next);
    runtime.locale = next;
    void plain.setItem('locale', next);
  }, []);

  const t = useCallback((key: Key, params?: Record<string, string | number>) => translate(locale, key, params), [locale]);

  const features = user && config ? featuresFor(user.permissions, config.modules) : NO_FEATURES;
  const updateRequired = config !== null && isOlderVersion(APP_VERSION, config.min_app_version);

  const value: SessionValue = {
    ready, signedIn: token !== null && user !== null, serverUrl, user, config, features, locale, client, queue, pending, updateRequired,
    t, signIn, signOut, setLocale, refreshQueue, flushQueue,
  };

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}
