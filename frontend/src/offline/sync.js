import api, { isOnline, onConnectivityChange } from '../api/client';
import {
  listPendingOutbox,
  updateOutbox,
  pruneSyncedOutbox,
} from './db';

/**
 * Background-sync engine for the offline outbox.
 *
 * - flushOutbox(): sends queued mutations oldest-first.
 * - Never silently overwrites newer server data: if the server answers
 *   409 (conflict) the item is marked 'failed' with the server's message
 *   and left for the user to review — it is NOT retried blindly.
 * - Sales created offline keep their localId; on success we store the
 *   server id so the POS can show "Server confirmed".
 * - Network failures keep the item 'queued' (retryable). Repeated
 *   failures after MAX_ATTEMPTS become 'failed' for manual review.
 */

const MAX_ATTEMPTS = 5;
let flushing = false;

const listeners = new Set();
export function onOutboxChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}
function emit() {
  listeners.forEach((fn) => {
    try {
      fn();
    } catch (e) {
      /* ignore */
    }
  });
}

function isConflict(err) {
  return err?.status === 409;
}

async function syncOne(item) {
  await updateOutbox(item.id, { status: 'syncing' });

  try {
    const res = await api.request({
      method: item.method,
      url: item.url,
      data: item.payload,
    });
    const data = res.unwrapped?.data ?? res.data;
    // Sale receipts may come back as an object with an id field.
    const serverId =
      (data && (data.id || data.sale_id || data.invoice_id)) || null;

    await updateOutbox(item.id, {
      status: 'synced',
      attempts: item.attempts + 1,
      lastError: null,
      serverId,
      serverData: data ?? null,
    });
  } catch (err) {
    if (isConflict(err)) {
      // Conflict: server has newer/different data. Do NOT retry or
      // overwrite — mark failed for the user to resolve manually.
      await updateOutbox(item.id, {
        status: 'failed',
        attempts: item.attempts + 1,
        lastError: `Conflict: ${err.message || 'server has newer data'}`,
      });
    } else if (!isOnline() || err.code === 'ECONNABORTED' || !err.response) {
      // Still offline / network drop: stay queued, retry later.
      await updateOutbox(item.id, { status: 'queued' });
    } else {
      const attempts = item.attempts + 1;
      await updateOutbox(item.id, {
        status: attempts >= MAX_ATTEMPTS ? 'failed' : 'queued',
        attempts,
        lastError: err.message || 'Sync failed',
      });
    }
  }
  emit();
}

export async function flushOutbox() {
  if (flushing) return;
  if (!isOnline()) return;
  flushing = true;
  try {
    const pending = await listPendingOutbox();
    for (const item of pending) {
      // eslint-disable-next-line no-await-in-loop
      await syncOne(item);
    }
    await pruneSyncedOutbox();
  } finally {
    flushing = false;
    emit();
  }
}

/** Register the SW background-sync tag so the SW wakes us up. */
export function registerBackgroundSync() {
  if ('serviceWorker' in navigator && 'SyncManager' in window) {
    navigator.serviceWorker.ready
      .then((reg) => reg.sync.register('pharmacy-outbox-sync').catch(() => {}))
      .catch(() => {});
  }
}

// Auto-flush when connectivity returns, and when the service worker
// forwards a Background Sync event.
onConnectivityChange((online) => {
  if (online) {
    registerBackgroundSync();
    flushOutbox();
  }
});

if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data?.type === 'FLUSH_OUTBOX') flushOutbox();
  });
}
