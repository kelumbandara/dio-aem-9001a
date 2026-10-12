import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError } from '@/api/client';
import { useSession } from '@/auth/session';

/** One plain sentence for a failure, in the person's language where we have it. */
export function useDescribeError() {
  const { t } = useSession();

  return useCallback(
    (e: unknown): string => {
      if (e instanceof ApiError) return e.network ? t('error_network') : e.message;

      return t('error_generic');
    },
    [t],
  );
}

export type Loaded<T> = { data: T | null; error: string | null; loading: boolean; refreshing: boolean; reload: () => void; refresh: () => void };

type State<T> = { key: string | null; data: T | null; error: string | null };

/**
 * Loads something when the screen opens, when `deps` change, and again on request; keeps showing the old data while it loads.
 * `loading` is true until the first answer for the current `deps` has arrived.
 */
export function useLoad<T>(load: () => Promise<T>, deps: unknown[] = []): Loaded<T> {
  const describe = useDescribeError();
  const [state, setState] = useState<State<T>>({ key: null, data: null, error: null });
  const [refreshing, setRefreshing] = useState(false);
  const [again, setAgain] = useState(0);
  const loadRef = useRef(load);
  const key = JSON.stringify(deps);

  useEffect(() => {
    loadRef.current = load;
  });

  useEffect(() => {
    let alive = true;
    loadRef
      .current()
      .then((data) => alive && setState({ key, data, error: null }))
      .catch((e: unknown) => alive && setState((s) => ({ key, data: s.data, error: describe(e) })))
      .finally(() => alive && setRefreshing(false));

    return () => {
      alive = false;
    };
  }, [key, again, describe]);

  return {
    data: state.data,
    error: state.error,
    loading: state.key !== key,
    refreshing,
    reload: () => setAgain((n) => n + 1),
    refresh: () => {
      setRefreshing(true);
      setAgain((n) => n + 1);
    },
  };
}
