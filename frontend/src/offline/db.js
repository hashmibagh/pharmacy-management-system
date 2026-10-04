import { createStore, get, set, del } from 'idb-keyval';

/**
 * IndexedDB wrapper for offline-first behaviour.
 *
 * Stores (all inside one idb-keyval custom store):
 *  - outbox:index          -> ordered array of outbox item ids
 *  - outbox:item:<id>      -> one queued mutation
 *  - cache:<key>           -> last-fetched snapshots (medicines, customers, ...)
 *  - held-sales            -> POS "hold" tickets kept on this device
 *
 * Outbox item shape:
 *  { id, kind, method, url, payload, localId, createdAt, attempts,
 *    status: 'queued' | 'syncing' | 'synced' | 'failed',
 *    lastError, serverId, serverData }
 */

const store = createStore('pharmacy-offline', 'pharmacy-store');

const INDEX_KEY = 'outbox:index';
const itemKey = (id) => `outbox:item:${id}`;

function uid() {
  return (
    Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)
  );
}

async function readIndex() {
  return (await get(INDEX_KEY, store)) || [];
}

async function writeIndex(index) {
  await set(INDEX_KEY, index, store);
}

/** Queue a mutation for later sync. Returns the created outbox item. */
export async function enqueueOutbox({ kind = 'generic', method = 'POST', url, payload = {}, localId = null }) {
  const id = uid();
  const item = {
    id,
    kind,
    method,
    url,
    payload,
    localId: localId || uid(),
    createdAt: new Date().toISOString(),
    attempts: 0,
    status: 'queued',
    lastError: null,
    serverId: null,
    serverData: null,
  };
  const index = await readIndex();
  index.push(id);
  await Promise.all([set(itemKey(id), item, store), writeIndex(index)]);
  return item;
}

/** All outbox items, newest last. */
export async function listOutbox() {
  const index = await readIndex();
  const items = await Promise.all(index.map((id) => get(itemKey(id), store)));
  return items.filter(Boolean);
}

/** Items that still need syncing (queued or failed-but-retryable). */
export async function listPendingOutbox() {
  const items = await listOutbox();
  return items.filter((i) => i.status === 'queued' || i.status === 'failed');
}

export async function getOutboxItem(id) {
  return get(itemKey(id), store);
}

export async function updateOutbox(id, patch) {
  const item = await get(itemKey(id), store);
  if (!item) return null;
  const next = { ...item, ...patch, updatedAt: new Date().toISOString() };
  await set(itemKey(id), next, store);
  return next;
}

export async function removeOutbox(id) {
  await del(itemKey(id), store);
  const index = await readIndex();
  await writeIndex(index.filter((x) => x !== id));
}

/** Drop synced items older than `maxAgeMs` to keep the DB small. */
export async function pruneSyncedOutbox(maxAgeMs = 7 * 24 * 3600 * 1000) {
  const items = await listOutbox();
  const cutoff = Date.now() - maxAgeMs;
  for (const item of items) {
    if (item.status === 'synced' && new Date(item.updatedAt || item.createdAt).getTime() < cutoff) {
      await removeOutbox(item.id);
    }
  }
}

/* ---------------- Generic read caches (offline lists) ---------------- */

export async function cacheSet(key, value) {
  await set(`cache:${key}`, { value, cachedAt: new Date().toISOString() }, store);
}

export async function cacheGet(key) {
  return get(`cache:${key}`, store);
}

/* ---------------- POS held tickets (device-local) ---------------- */

export async function getHeldSales() {
  return (await get('held-sales', store)) || [];
}

export async function saveHeldSale(ticket) {
  const held = await getHeldSales();
  held.push({ ...ticket, id: ticket.id || uid(), heldAt: new Date().toISOString() });
  await set('held-sales', held, store);
  return held;
}

export async function removeHeldSale(id) {
  const held = await getHeldSales();
  const next = held.filter((t) => t.id !== id);
  await set('held-sales', next, store);
  return next;
}
