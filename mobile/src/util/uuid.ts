import { randomUUID } from 'expo-crypto';

/** A fresh random id: used as the Idempotency-Key of a request and as the id of a queued entry. */
export function newId(): string {
  return randomUUID();
}
