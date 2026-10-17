<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
    import type { Catalog, CatalogProduct, QueuedSale } from '@/lib/posOfflineStore';
    import { belongsTo, syncPosQueue } from '@/lib/offline/posAdapter';
    import { deviceId, newUuid, nextReceiptNumber, posOfflineStore } from '@/lib/posOfflineStore';

    defineOptions({
        layout: {
            title: 'Offline POS',
            subtitle: 'Cash and card sales that keep working without the network',
            breadcrumbs: [{ title: 'POS', href: 'NULL' }, { title: 'Offline POS', href: 'NULL' }],
        },
    });

    type CartLine = { product: CatalogProduct; quantity: number };

    const page = usePage();
    const me = computed(() => (page.props.auth as { user?: { id?: number; company_id?: number } } | undefined)?.user ?? {});
    const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
    const catalog = ref<Catalog | null>(null);
    const catalogNote = ref('');
    const search = ref('');
    const cart = ref<CartLine[]>([]);
    const method = ref<'cash' | 'card'>('cash');
    const accountId = ref<number | ''>('');
    const customerId = ref<number | ''>('');
    const queue = ref<QueuedSale[]>([]);
    const syncing = ref(false);
    const receipt = ref<QueuedSale | null>(null);
    const message = ref('');

    const products = computed(() => {
        const term = search.value.trim().toLowerCase();
        const all = catalog.value?.products ?? [];

        return (term === '' ? all : all.filter((product) => product.name.toLowerCase().includes(term) || product.sku.toLowerCase().includes(term))).slice(0, 60);
    });
    const total = computed(() => Math.round(cart.value.reduce((sum, line) => sum + line.quantity * line.product.price, 0) * 100) / 100);
    const waiting = computed(() => queue.value.filter((sale) => sale.status === 'pending').length);
    const needsReview = computed(() => queue.value.filter((sale) => sale.status === 'conflict' || sale.status === 'failed'));
    const money = (value: number): string => value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function setOnline(value: boolean) {
        online.value = value;

        if (value) {
            void syncQueue();
        }
    }

    async function refreshQueue() {
        queue.value = (await posOfflineStore.allSales()).filter((sale) => belongsTo(sale, Number(me.value.id ?? 0)));
    }

    async function loadCatalog() {
        catalog.value = (await posOfflineStore.loadCatalog()) ?? null;

        if (online.value) {
            try {
                const response = await window.axios.get('/api/pos/offline/catalog');
                catalog.value = response.data as Catalog;
                await posOfflineStore.saveCatalog(catalog.value);
                catalogNote.value = `Catalog saved on this device (${catalog.value.products.length} products)${catalog.value.truncated ? ', the first 5000 only' : ''}.`;
            } catch {
                catalogNote.value = catalog.value ? 'Using the catalog saved on this device.' : 'The catalog could not be loaded.';
            }
        } else {
            catalogNote.value = catalog.value ? `Offline: using the catalog saved ${catalog.value.generated_at.slice(0, 16).replace('T', ' ')}.` : 'Offline and no catalog saved on this device yet. Open this page once online.';
        }

        if (catalog.value && accountId.value === '') {
            accountId.value = catalog.value.payment_accounts[0]?.id ?? '';
        }

        if (catalog.value && customerId.value === '') {
            customerId.value = catalog.value.default_customer_id ?? catalog.value.customers?.[0]?.id ?? '';
        }
    }

    /** Warms the service worker's cache with the files this page needs, from the build manifest. */
    async function precacheShell(registration: ServiceWorkerRegistration) {
        try {
            const manifest = (await (await fetch('/build/manifest.json')).json()) as Record<string, { file: string; css?: string[]; imports?: string[]; assets?: string[] }>;
            const seen = new Set<string>();
            const urls = new Set<string>();
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

            ['resources/js/app.ts', 'resources/js/pages/sell/posoffline.vue'].forEach(walk);
            const worker = registration.active ?? (await navigator.serviceWorker.ready).active;
            worker?.postMessage({ type: 'precache', urls: [...urls] });
        } catch {
            // the next online visit warms it
        }
    }

    function addToCart(product: CatalogProduct) {
        const existing = cart.value.find((line) => line.product.variation_id === product.variation_id);

        if (existing) {
            existing.quantity += 1;
        } else {
            cart.value.push({ product, quantity: 1 });
        }
    }

    function removeFromCart(index: number) {
        cart.value.splice(index, 1);
    }

    async function completeSale() {
        message.value = '';

        if (! cart.value.length || accountId.value === '' || ! catalog.value) {
            message.value = 'Add a product and choose the account the payment goes to.';

            return;
        }

        const sale: QueuedSale = {
            client_uuid: newUuid(),
            device_id: deviceId(),
            offline_receipt_no: nextReceiptNumber(deviceId()),
            sold_at: new Date().toISOString(),
            catalog_version: catalog.value.catalog_version,
            contact_id: customerId.value === '' ? null : Number(customerId.value),
            total: total.value,
            lines: cart.value.map((line) => ({
                product_id: line.product.product_id, variation_id: line.product.variation_id, unit_id: line.product.unit_id,
                quantity: line.quantity, unit_price: line.product.price, name: line.product.name,
            })),
            payments: [{ method: method.value, payment_account: Number(accountId.value), amount: total.value }],
            user_id: Number(me.value.id ?? 0) || null,
            company_id: Number(me.value.company_id ?? 0) || null,
            status: 'pending',
            created_at: new Date().toISOString(),
        };

        await posOfflineStore.putSale(sale);
        receipt.value = sale;
        cart.value = [];
        await refreshQueue();

        if (online.value) {
            await syncQueue();
        } else {
            message.value = `Saved offline as ${sale.offline_receipt_no}. It is sent when the network is back.`;
        }
    }

    async function syncQueue() {
        if (syncing.value || ! navigator.onLine) {
            return;
        }

        syncing.value = true;

        try {
            // the same runner the header's offline engine uses (lib/offline/posAdapter.ts)
            const outcome = await syncPosQueue(Number(me.value.id ?? 0));
            message.value = outcome === 'login' ? 'Sign in again to send the waiting sales; they are kept on this device.' : '';
        } finally {
            syncing.value = false;
            await refreshQueue();
        }
    }

    async function discardLocal(sale: QueuedSale) {
        if (window.confirm(`Remove ${sale.offline_receipt_no} from this device? It will not be sent.`)) {
            await posOfflineStore.deleteSale(sale.client_uuid);
            await refreshQueue();
        }
    }

    async function retry(sale: QueuedSale) {
        await posOfflineStore.putSale({ ...sale, status: 'pending', message: null });
        await refreshQueue();
        await syncQueue();
    }

    function printReceipt() {
        window.print();
    }

    const handleOnline = () => setOnline(true);
    const handleOffline = () => setOnline(false);

    onMounted(async () => {
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        await refreshQueue();
        await loadCatalog();

        if ('serviceWorker' in navigator) {
            try {
                const registration = await navigator.serviceWorker.register('/pos-sw.js');
                await navigator.serviceWorker.ready;

                if (online.value) {
                    await precacheShell(registration);
                }
            } catch {
                // the terminal still works while the page is open
            }
        }

        if (online.value) {
            await syncQueue();
        }
    });

    onBeforeUnmount(() => {
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);
    });
</script>

<template>
    <Head title="Offline POS" />

    <div class="container-fluid py-3" dusk="offline-pos">
        <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
            <span class="badge" :class="online ? 'text-bg-success' : 'text-bg-danger'" dusk="network-state">{{ online ? 'Online' : 'Offline' }}</span>
            <span class="small text-muted" dusk="catalog-note">{{ catalogNote }}</span>
            <span class="small" dusk="waiting-count">{{ waiting }} waiting to be sent</span>
            <button type="button" class="btn btn-outline-dark btn-sm" :disabled="! online || syncing || ! waiting" dusk="sync-now" @click="syncQueue">Send waiting sales</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="! online" title="Closing the day needs the server" dusk="close-day">Close shift / day (online only)</button>
        </div>
        <div v-if="message" class="alert alert-info py-2 small" dusk="pos-message">{{ message }}</div>

        <div class="row g-3">
            <div class="col-lg-7">
                <input v-model="search" class="form-control mb-2" placeholder="Search by name or SKU" aria-label="Search products" dusk="product-search" />
                <div class="row g-2">
                    <div v-for="product in products" :key="product.variation_id" class="col-6 col-md-4">
                        <button type="button" class="btn btn-outline-dark w-100 text-start" :dusk="`product-${product.variation_id}`" @click="addToCart(product)">
                            <div class="fw-semibold small">{{ product.name }}</div>
                            <div class="small text-muted">{{ product.sku }} · {{ money(product.price) }}</div>
                        </button>
                    </div>
                    <div v-if="! products.length" class="col-12 text-muted small">No products to show.</div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="border rounded p-3 mb-3">
                    <h6>Sale</h6>
                    <table class="table table-sm">
                        <tbody>
                            <tr v-for="(line, index) in cart" :key="line.product.variation_id">
                                <td>{{ line.product.name }}</td>
                                <td style="width: 5rem"><input v-model.number="line.quantity" type="number" min="1" class="form-control form-control-sm" :aria-label="`Quantity of ${line.product.name}`" /></td>
                                <td class="text-end">{{ money(line.quantity * line.product.price) }}</td>
                                <td><button type="button" class="btn btn-link btn-sm text-danger" @click="removeFromCart(index)">x</button></td>
                            </tr>
                            <tr v-if="! cart.length"><td colspan="4" class="text-muted text-center">Nothing in the sale.</td></tr>
                        </tbody>
                    </table>
                    <div class="d-flex justify-content-between fw-semibold mb-2"><span>Total</span><span dusk="cart-total">{{ money(total) }}</span></div>
                    <select v-model="customerId" class="form-select form-select-sm mb-2" aria-label="Customer" dusk="customer">
                        <option v-for="customer in catalog?.customers ?? []" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                    </select>
                    <div class="row g-2 mb-2">
                        <div class="col-5">
                            <select v-model="method" class="form-select form-select-sm" aria-label="Payment method" dusk="pay-method"><option value="cash">Cash</option><option value="card">Card slip</option></select>
                        </div>
                        <div class="col-7">
                            <select v-model="accountId" class="form-select form-select-sm" aria-label="Payment account" dusk="pay-account">
                                <option v-for="account in catalog?.payment_accounts ?? []" :key="account.id" :value="account.id">{{ account.name }}</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="btn btn-dark w-100" :disabled="! cart.length" dusk="complete-sale" @click="completeSale">Complete sale</button>
                    <div class="small text-muted mt-2">Returns, credit sales and discounts need the network and are made on the normal POS.</div>
                </div>

                <div v-if="receipt" class="border rounded p-3 mb-3" dusk="receipt">
                    <div class="d-flex justify-content-between"><strong>{{ catalog?.company.name }}</strong><span class="small">{{ receipt.sold_at.slice(0, 16).replace('T', ' ') }}</span></div>
                    <div class="small">Provisional receipt <span dusk="receipt-no">{{ receipt.offline_receipt_no }}</span></div>
                    <table class="table table-sm mb-1"><tbody><tr v-for="line in receipt.lines" :key="line.variation_id"><td>{{ line.name }} x {{ line.quantity }}</td><td class="text-end">{{ money(line.quantity * line.unit_price) }}</td></tr></tbody></table>
                    <div class="d-flex justify-content-between fw-semibold"><span>Total ({{ receipt.payments[0].method }})</span><span>{{ money(receipt.total) }}</span></div>
                    <button type="button" class="btn btn-outline-dark btn-sm mt-2" @click="printReceipt">Print</button>
                </div>
            </div>
        </div>

        <h6 class="mt-4">Sales on this device</h6>
        <div class="table-responsive">
            <table class="table table-sm" dusk="queue-table">
                <thead><tr><th>Receipt</th><th>Time</th><th class="text-end">Total</th><th>Status</th><th>Invoice</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="sale in queue" :key="sale.client_uuid" :dusk="`sale-${sale.offline_receipt_no}`">
                        <td>{{ sale.offline_receipt_no }}</td><td>{{ sale.sold_at.slice(0, 16).replace('T', ' ') }}</td><td class="text-end">{{ money(sale.total) }}</td>
                        <td class="text-capitalize">
                            {{ sale.status }}<span v-if="sale.conflict_code" class="small text-muted"> ({{ sale.conflict_code.replace('_', ' ') }}, waiting for a supervisor)</span>
                            <div v-if="sale.message" class="small text-danger">{{ sale.message }}</div>
                            <div v-if="sale.oversold?.length" class="small text-warning">Sold past the stock on hand</div>
                        </td>
                        <td>{{ sale.invoice_no ?? '-' }}</td>
                        <td class="text-end">
                            <button v-if="sale.status === 'failed'" type="button" class="btn btn-outline-dark btn-sm me-1" @click="retry(sale)">Send again</button>
                            <button v-if="sale.status === 'failed' || sale.status === 'synced' || sale.status === 'discarded'" type="button" class="btn btn-outline-danger btn-sm" @click="discardLocal(sale)">Remove</button>
                        </td>
                    </tr>
                    <tr v-if="! queue.length"><td colspan="6" class="text-center text-muted">No offline sales on this device.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="needsReview.length" class="small text-muted">Sales marked conflict are held by the server for a supervisor (Offline POS Sales); they are not lost.</div>
    </div>
</template>
