import { reactive } from 'vue';
import { posCounts, syncPosQueue } from '@/lib/offline/posAdapter';
import type { OfflineEntry, OfflineKind, Reference } from '@/lib/offline/store';
import { MAX_ENTRIES, deviceId, newUuid, nextProvisionalRef, offlineStore, referenceKey } from '@/lib/offline/store';

/**
 * The offline engine: one status for the whole app, one queue runner, one reference cache.
 *
 * It decides whether the network is there by asking the server (a heartbeat), not by trusting the browser; sends the waiting entries
 * in the order they were made, one at a time, and after a failure waits a little longer each time; keeps every refusal as a
 * conflict with its reason; and never touches an entry of another user. The offline POS terminal's queue is counted and sent by
 * the same runner (lib/offline/posAdapter.ts). Design: docs/offline-mode-design.md.
 */
export type Scope = { userId: number; companyId: number; branchId: number | null };

export const offline = reactive({
    started: false,
    online: true,
    syncing: false,
    pending: 0,
    conflicts: 0,
    posPending: 0,
    posConflicts: 0,
    lastSyncedAt: null as string | null,
    needsLogin: false,
    error: '',
    referenceAt: null as string | null,
    blocked: false,
});

export const CONFLICT_TEXT: Record<string, string> = {
    insufficient_stock: 'There was not enough stock when it reached the server.',
    period_locked: 'The date of this entry is in a period that has been locked.',
    credit_limit: 'The customer would go over the credit limit.',
    product_missing: 'A product, customer or invoice on it was deleted or is no longer sold.',
    price_changed: 'A price changed since it was made.',
    contact_blocked: 'The customer is blacklisted or on hold.',
    no_permission: 'You no longer have the permission to do this.',
    server_error: 'The server could not process it.',
    rejected: 'The server did not accept it.',
};

export const KIND_LABEL: Record<OfflineKind, string> = {
    sale: 'Sales invoice', purchase: 'Purchase', payment: 'Payment / receipt', expense: 'Expense', contact: 'New customer / supplier', stock_adjustment: 'Stock adjustment',
};

let scope: Scope | null = null;
let timer: number | undefined;
let failures = 0;
let nextAttempt = 0;

function tryJson<T>(value: string | null): T | null {
    try {
        return value ? (JSON.parse(value) as T) : null;
    } catch {
        return null;
    }
}

export async function ping(): Promise<boolean> {
    try {
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 4000);
        const response = await fetch(`/api/ping?_=${Date.now()}`, { cache: 'no-store', signal: controller.signal, credentials: 'omit' });
        window.clearTimeout(timeout);

        return response.ok;
    } catch {
        return false;
    }
}

async function setOnline(value: boolean): Promise<void> {
    const wasOnline = offline.online;
    offline.online = value;

    if (value && ! wasOnline) {
        failures = 0;
        nextAttempt = 0;
        void syncAll();
        void refreshReference();
    }
}

export async function heartbeat(): Promise<void> {
    await setOnline(await ping());
    await refreshCounts();

    if (offline.online && (offline.pending > 0 || offline.posPending > 0) && Date.now() >= nextAttempt) {
        void syncAll();
    }
}

export async function refreshCounts(): Promise<void> {
    if (scope === null) {
        return;
    }

    const rows = await offlineStore.forUser(scope.userId, scope.companyId);
    offline.pending = rows.filter((row) => row.status === 'pending').length;
    offline.conflicts = rows.filter((row) => row.status === 'conflict').length;
    const pos = await posCounts(scope.userId);
    offline.posPending = pos.pending;
    offline.posConflicts = pos.conflicts;
    offline.lastSyncedAt = rows.filter((row) => row.synced_at).map((row) => row.synced_at as string).sort().pop() ?? offline.lastSyncedAt;
}

export function startEngine(next: Scope): void {
    scope = next;
    offline.started = true;
    offline.needsLogin = false;
    offline.online = navigator.onLine;
    window.clearInterval(timer);
    timer = window.setInterval(() => void heartbeat(), 15000);

    window.addEventListener('online', () => void heartbeat());
    window.addEventListener('offline', () => {
        offline.online = false;
    });

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            if ((event.data as { type?: string } | null)?.type === 'offline-sync') {
                void heartbeat();
            }
        });
    }

    void offlineStore.getMeta<string>('last_synced:'+next.userId).then((value) => {
        offline.lastSyncedAt = value ?? offline.lastSyncedAt;
    });
    void heartbeat();
    void refreshReference();
}

export function stopEngine(): void {
    window.clearInterval(timer);
    scope = null;
    offline.started = false;
}

function summaryOf(kind: OfflineKind, payload: Record<string, unknown>): string {
    const lines = (payload.lines as unknown[] | undefined)?.length ?? 0;

    switch (kind) {
        case 'sale':
        case 'purchase':
            return `${lines} line${lines === 1 ? '' : 's'}${payload.payment ? ', paid now' : ', on credit'}`;
        case 'payment':
            return `${payload.direction === 'payment' ? 'Payment' : 'Receipt'} of ${payload.amount}`;
        case 'expense':
            return String(payload.description ?? 'Expense');
        case 'contact':
            return `${payload.type === 'supplier' ? 'Supplier' : 'Customer'} ${payload.business_name ?? ''}`.trim();
        default:
            return `${lines} line${lines === 1 ? '' : 's'}`;
    }
}

/** Puts an entry in the queue. Throws a readable message when the device refuses (the queue is full, or the device storage is). */
export async function enqueue(kind: OfflineKind, payload: Record<string, unknown>, total: number): Promise<OfflineEntry> {
    if (scope === null) {
        throw new Error('Offline entries need a signed-in user.');
    }

    const mine = await offlineStore.forUser(scope.userId, scope.companyId);

    if (mine.filter((row) => row.status !== 'synced').length >= MAX_ENTRIES) {
        throw new Error(`This device already holds ${MAX_ENTRIES} entries that have not been sent. Connect and send them first.`);
    }

    const entry: OfflineEntry = {
        uuid: newUuid(), kind, provisional_ref: nextProvisionalRef(), device_id: deviceId(), user_id: scope.userId, company_id: scope.companyId, branch_id: scope.branchId,
        created_at: new Date().toISOString(), payload, summary: summaryOf(kind, payload), total: Math.round(total * 100) / 100, status: 'pending',
    };

    try {
        await offlineStore.put(entry);
    } catch (error: unknown) {
        if ((error as { name?: string }).name === 'QuotaExceededError') {
            throw new Error('The storage of this device is full, so the entry was not saved. Free some space or send the waiting entries first.');
        }

        throw error;
    }

    await refreshCounts();

    if (offline.online) {
        void syncAll();
    } else {
        void registerBackgroundSync();
    }

    return entry;
}

type ServerBody = { status?: string; id?: number; real_ref?: string | null; conflict_code?: string | null; message?: string | null };

async function applyResult(entry: OfflineEntry, body: ServerBody): Promise<void> {
    if (body.status === 'synced') {
        await offlineStore.put({ ...entry, status: 'synced', server_id: body.id ?? null, real_ref: body.real_ref ?? null, conflict_code: null, message: null, synced_at: new Date().toISOString() });
        await offlineStore.setMeta(`last_synced:${entry.user_id}`, new Date().toISOString());
    } else {
        await offlineStore.put({ ...entry, status: 'conflict', server_id: body.id ?? entry.server_id ?? null, conflict_code: body.conflict_code ?? 'rejected', message: body.message ?? null });
    }
}

/** Sends the waiting entries in the order they were made. */
export async function syncAll(): Promise<void> {
    if (scope === null || offline.syncing || ! offline.online || offline.needsLogin) {
        return;
    }

    offline.syncing = true;
    offline.error = '';

    try {
        const waiting = (await offlineStore.forUser(scope.userId, scope.companyId)).filter((row) => row.status === 'pending');

        for (const entry of waiting) {
            try {
                const response = await window.axios.post('/api/offline/sync', {
                    client_uuid: entry.uuid, kind: entry.kind, payload: entry.payload, device_id: entry.device_id, provisional_ref: entry.provisional_ref, created_at: entry.created_at,
                });
                await applyResult(entry, response.data as ServerBody);
                failures = 0;
            } catch (error: unknown) {
                const failure = error as { response?: { status?: number; data?: { message?: string } } };
                const status = failure.response?.status;

                if (status === 401 || status === 419) {
                    offline.needsLogin = true;
                    offline.error = 'Your session ended. Sign in again to send the waiting entries; nothing was lost.';

                    break;
                }

                if (status === 413 || status === 422 || status === 409) {
                    // the server will never take this one as it is: keep it, with the reason, for the user to fix or discard
                    await applyResult(entry, { status: 'conflict', conflict_code: 'rejected', message: failure.response?.data?.message ?? 'The server did not accept this entry.' });

                    continue;
                }

                // no network, too many calls, or a server error: stop here, keep the order, try again a little later
                failures += 1;
                nextAttempt = Date.now() + Math.min(60000, 2000 * 2 ** failures);

                if (status === undefined) {
                    offline.online = false;
                }

                break;
            }
        }

        if (! offline.needsLogin && offline.online) {
            const outcome = await syncPosQueue(scope.userId);

            if (outcome === 'login') {
                offline.needsLogin = true;
                offline.error = 'Your session ended. Sign in again to send the waiting entries; nothing was lost.';
            }
        }
    } finally {
        offline.syncing = false;
        await refreshCounts();
    }
}

/** Edit and retry: the entry gets its new fields and goes to the server again. */
export async function retryEntry(entry: OfflineEntry, payload: Record<string, unknown>, total: number): Promise<void> {
    const edited: OfflineEntry = { ...entry, payload, total: Math.round(total * 100) / 100, summary: summaryOf(entry.kind, payload), message: null, conflict_code: null };

    if (entry.server_id) {
        await offlineStore.put({ ...edited, status: 'conflict' });
        const response = await window.axios.post(`/api/offline/entries/${entry.server_id}/retry`, { payload });
        await applyResult(edited, response.data as ServerBody);
    } else {
        await offlineStore.put({ ...edited, status: 'pending' });
        await syncAll();
    }

    await refreshCounts();
}

/** Discard asks for the reason and the confirmation first (the caller), and is audited on the server. */
export async function discardEntry(entry: OfflineEntry, reason: string): Promise<void> {
    if (entry.server_id && entry.status === 'conflict') {
        await window.axios.post(`/api/offline/entries/${entry.server_id}/discard`, { reason, confirm: true });
    }

    await offlineStore.remove(entry.uuid);
    await refreshCounts();
}

export async function listEntries(): Promise<OfflineEntry[]> {
    return scope === null ? [] : (await offlineStore.forUser(scope.userId, scope.companyId)).reverse();
}

export async function loadReference(): Promise<Reference | null> {
    if (scope === null) {
        return null;
    }

    return (await offlineStore.loadReference(referenceKey(scope.userId, scope.companyId, scope.branchId))) ?? null;
}

export async function refreshReference(force = false): Promise<Reference | null> {
    if (scope === null) {
        return null;
    }

    const key = referenceKey(scope.userId, scope.companyId, scope.branchId);
    const cached = (await offlineStore.loadReference(key)) ?? null;
    offline.referenceAt = cached?.generated_at ?? null;

    const fresh = cached !== null && Date.now() - new Date(cached.generated_at).getTime() < 30 * 60 * 1000;

    if (! offline.online || (fresh && ! force)) {
        return cached;
    }

    try {
        const response = await window.axios.get('/api/offline/reference');
        const reference = response.data?.data as Reference;
        await offlineStore.saveReference(key, reference);
        offline.referenceAt = reference.generated_at;

        return reference;
    } catch {
        return cached;
    }
}

export function isStale(iso: string | null): boolean {
    return iso === null || Date.now() - new Date(iso).getTime() > 24 * 3600 * 1000;
}

/** Entries of this user that the device still holds unsent: the sign-out warning counts them. */
export async function unsentForUser(userId: number): Promise<number> {
    const pos = await posCounts(userId);

    return (await offlineStore.countOfUser(userId)) + pos.pending;
}

async function registerBackgroundSync(): Promise<void> {
    try {
        const registration = await navigator.serviceWorker?.ready;
        await (registration as unknown as { sync?: { register: (tag: string) => Promise<void> } } | undefined)?.sync?.register('offline-sync');
    } catch {
        // the page does it when it is open
    }
}

/** The two offline screens and the build files are kept by the service worker (never any other page); this primes them. */
export async function registerShell(): Promise<void> {
    if (! ('serviceWorker' in navigator)) {
        return;
    }

    try {
        const registration = await navigator.serviceWorker.register('/pos-sw.js');
        const ready = await navigator.serviceWorker.ready;

        if (offline.online) {
            const manifest = tryJson<Record<string, { file: string; css?: string[]; imports?: string[]; assets?: string[] }>>(await (await fetch('/build/manifest.json')).text()) ?? {};
            const urls = new Set<string>();
            const seen = new Set<string>();
            const walk = (key: string) => {
                const entry = manifest[key];

                if (! entry || seen.has(key)) {
                    return;
                }

                seen.add(key);
                urls.add(`/build/${entry.file}`);
                (entry.css ?? []).forEach((css) => urls.add(`/build/${css}`));
                (entry.assets ?? []).forEach((asset) => urls.add(`/build/${asset}`));
                (entry.imports ?? []).forEach(walk);
            };
            ['resources/js/app.ts', 'resources/js/pages/offline/index.vue'].forEach(walk);
            (registration.active ?? ready.active)?.postMessage({ type: 'precache', urls: [...urls] });
            (registration.active ?? ready.active)?.postMessage({ type: 'warm', urls: ['/offline'] });
        }
    } catch {
        // the offline screen still works while its page is open
    }
}

/** On sign-out the device forgets the screen it cached (the entries stay, under the user's id). */
export async function clearShell(): Promise<void> {
    try {
        for (const key of await caches.keys()) {
            if (key.startsWith('pos-shell-')) {
                await caches.delete(key);
            }
        }
    } catch {
        // nothing cached
    }
}
