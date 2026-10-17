/**
 * The device side of offline mode: the queue of entries made without a network and the cached reference data, in IndexedDB.
 * Nothing here talks to the network and nothing here is shared between users: every entry carries the user and company it
 * was made for, and the callers only ever list and send their own.
 *
 * Nothing secret is stored (no password, no token): an entry is the document's own fields.
 */

export type OfflineKind = 'sale' | 'purchase' | 'payment' | 'expense' | 'contact' | 'stock_adjustment';
export type EntryStatus = 'pending' | 'synced' | 'conflict';

export type OfflineEntry = {
    uuid: string;
    kind: OfflineKind;
    provisional_ref: string;
    device_id: string;
    user_id: number;
    company_id: number;
    branch_id: number | null;
    created_at: string;
    payload: Record<string, unknown>;
    summary: string;
    total: number;
    status: EntryStatus;
    server_id?: number | null;
    real_ref?: string | null;
    conflict_code?: string | null;
    message?: string | null;
    synced_at?: string | null;
};

export type Reference = {
    generated_at: string;
    balances_read_at: string;
    user_id: number;
    company_id: number;
    branch_id: number | null;
    truncated: boolean;
    products: { variation_id: number; product_id: number; unit_id: number; unit: string | null; name: string; sku: string; sell_price: number; purchase_price: number; barcodes: string[] }[];
    contacts: { id: number; name: string; type: string; mobile: string | null; pay_term: number | null; pay_type: string | null; blocked: boolean; due: number }[];
    open_invoices: { id: number; invoice_no: string; direction: 'receipt' | 'payment'; contact_id: number; total: number; due: number }[];
    units: { id: number; name: string }[];
    taxes: { id: number; name: string; rate: number }[];
    methods: string[];
    payment_accounts: { id: number; name: string }[];
    expense_accounts: { id: number; code: string; name: string }[];
    branches: { id: number; name: string }[];
    warehouses: { id: number; name: string }[];
    allowed: Record<OfflineKind, string[]>;
};

export const MAX_ENTRIES = 500;

const DB_NAME = 'app-offline';
const DB_VERSION = 1;

let connection: Promise<IDBDatabase> | null = null;

/** One connection per page, closed when another tab upgrades or deletes the database (so that never blocks it). */
function open(): Promise<IDBDatabase> {
    connection ??= new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (! db.objectStoreNames.contains('queue')) {
                db.createObjectStore('queue', { keyPath: 'uuid' });
            }

            if (! db.objectStoreNames.contains('reference')) {
                db.createObjectStore('reference');
            }

            if (! db.objectStoreNames.contains('meta')) {
                db.createObjectStore('meta');
            }
        };
        request.onsuccess = () => {
            request.result.onversionchange = () => {
                request.result.close();
                connection = null;
            };
            resolve(request.result);
        };
        request.onerror = () => {
            connection = null;
            reject(request.error);
        };
    });

    return connection;
}

/** A reactive Vue proxy cannot be stored in IndexedDB (DataCloneError), so values are copied to plain data first. */
function plain<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}

async function run<T>(store: 'queue' | 'reference' | 'meta', mode: IDBTransactionMode, work: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const db = await open();

    return new Promise<T>((resolve, reject) => {
        const request = work(db.transaction(store, mode).objectStore(store));
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

export const offlineStore = {
    put: (entry: OfflineEntry) => run('queue', 'readwrite', (store) => store.put(plain(entry))),
    remove: (uuid: string) => run('queue', 'readwrite', (store) => store.delete(uuid)),
    get: (uuid: string) => run<OfflineEntry | undefined>('queue', 'readonly', (store) => store.get(uuid)),

    /** The entries of one user in one company, oldest first: nobody else's are ever returned. */
    async forUser(userId: number, companyId: number): Promise<OfflineEntry[]> {
        const rows = await run<OfflineEntry[]>('queue', 'readonly', (store) => store.getAll());

        return rows.filter((row) => row.user_id === userId && row.company_id === companyId).sort((a, b) => a.created_at.localeCompare(b.created_at));
    },

    /** How many entries of anybody are waiting on this device (for the sign-out warning of a shared device). */
    async countOfUser(userId: number): Promise<number> {
        const rows = await run<OfflineEntry[]>('queue', 'readonly', (store) => store.getAll());

        return rows.filter((row) => row.user_id === userId && row.status !== 'synced').length;
    },

    saveReference: (key: string, reference: Reference) => run('reference', 'readwrite', (store) => store.put(plain(reference), key)),
    loadReference: (key: string) => run<Reference | undefined>('reference', 'readonly', (store) => store.get(key)),
    setMeta: (key: string, value: unknown) => run('meta', 'readwrite', (store) => store.put(plain(value), key)),
    getMeta: <T>(key: string) => run<T | undefined>('meta', 'readonly', (store) => store.get(key)),
};

/** A device keeps one id for good: it prefixes the provisional numbers so two devices can never collide. */
export function deviceId(): string {
    const key = 'pos-offline-device';
    let id = window.localStorage.getItem(key);

    if (! id) {
        id = Math.random().toString(36).slice(2, 6).toUpperCase();
        window.localStorage.setItem(key, id);
    }

    return id;
}

/** OFF-<device>-<seq>, the same series the offline POS terminal uses, so a provisional number is never repeated on a device. */
export function nextProvisionalRef(): string {
    const key = 'pos-offline-seq';
    const next = Number(window.localStorage.getItem(key) ?? '0') + 1;
    window.localStorage.setItem(key, String(next));

    return `OFF-${deviceId()}-${String(next).padStart(6, '0')}`;
}

export function newUuid(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;

            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
}

export function referenceKey(userId: number, companyId: number, branchId: number | null): string {
    return `${userId}:${companyId}:${branchId ?? 0}`;
}
