/**
 * Local storage of the offline POS terminal: the cached catalog and the queue of sales rung up offline, in IndexedDB.
 * Nothing here talks to the network; the terminal page does that.
 */

export type CatalogProduct = { variation_id: number; product_id: number; unit_id: number; unit: string | null; name: string; sku: string; price: number };
export type PaymentAccount = { id: number; name: string };
export type Catalog = {
    catalog_version: string;
    generated_at: string;
    default_customer_id: number | null;
    company: { id: number; name: string | null };
    customers: { id: number; name: string }[];
    payment_accounts: PaymentAccount[];
    products: CatalogProduct[];
    truncated: boolean;
};

export type QueuedSale = {
    client_uuid: string;
    device_id: string;
    offline_receipt_no: string;
    sold_at: string;
    catalog_version: string | null;
    contact_id: number | null;
    total: number;
    lines: { product_id: number; variation_id: number; unit_id: number; quantity: number; unit_price: number; name: string }[];
    payments: { method: 'cash' | 'card'; payment_account: number; amount: number }[];
    status: 'pending' | 'synced' | 'conflict' | 'failed' | 'discarded';
    message?: string | null;
    invoice_no?: string | null;
    conflict_code?: string | null;
    oversold?: unknown[];
    created_at: string;
};

const DB_NAME = 'pos-offline';
const DB_VERSION = 1;

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (! db.objectStoreNames.contains('catalog')) {
                db.createObjectStore('catalog');
            }

            if (! db.objectStoreNames.contains('queue')) {
                db.createObjectStore('queue', { keyPath: 'client_uuid' });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/** A reactive Vue proxy cannot be stored in IndexedDB (DataCloneError), so values are copied to plain data first. */
function plain<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}

async function run<T>(store: 'catalog' | 'queue', mode: IDBTransactionMode, work: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const db = await open();

    return new Promise<T>((resolve, reject) => {
        const request = work(db.transaction(store, mode).objectStore(store));
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

export const posOfflineStore = {
    saveCatalog: (catalog: Catalog) => run('catalog', 'readwrite', (store) => store.put(plain(catalog), 'main')),
    loadCatalog: () => run<Catalog | undefined>('catalog', 'readonly', (store) => store.get('main')),
    putSale: (sale: QueuedSale) => run('queue', 'readwrite', (store) => store.put(plain(sale))),
    deleteSale: (uuid: string) => run('queue', 'readwrite', (store) => store.delete(uuid)),
    async allSales(): Promise<QueuedSale[]> {
        const rows = await run<QueuedSale[]>('queue', 'readonly', (store) => store.getAll());

        return rows.sort((a, b) => a.created_at.localeCompare(b.created_at));
    },
};

/** A device keeps one id for good: it prefixes the provisional receipt numbers so two terminals can never collide. */
export function deviceId(): string {
    const key = 'pos-offline-device';
    let id = window.localStorage.getItem(key);

    if (! id) {
        id = Math.random().toString(36).slice(2, 6).toUpperCase();
        window.localStorage.setItem(key, id);
    }

    return id;
}

export function nextReceiptNumber(device: string): string {
    const key = 'pos-offline-seq';
    const next = Number(window.localStorage.getItem(key) ?? '0') + 1;
    window.localStorage.setItem(key, String(next));

    return `OFF-${device}-${String(next).padStart(6, '0')}`;
}

export function newUuid(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;

            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
}
