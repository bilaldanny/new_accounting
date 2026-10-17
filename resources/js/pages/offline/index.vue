<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import { CONFLICT_TEXT, KIND_LABEL, discardEntry, enqueue, isStale, listEntries, loadReference, offline, refreshReference, retryEntry, syncAll } from '@/lib/offline/engine';
    import type { OfflineEntry, OfflineKind, Reference } from '@/lib/offline/store';

    defineOptions({
        layout: {
            title: 'Offline entries',
            subtitle: 'Entries made without internet, and what the server did with them',
            breadcrumbs: [{ title: 'Offline entries', href: 'NULL' }],
        },
    });

    type Line = { variation_id: number | ''; quantity: number; price: number };

    const reference = ref<Reference | null>(null);
    const entries = ref<OfflineEntry[]>([]);
    const message = ref('');
    const failure = ref('');
    const kind = ref<OfflineKind>('sale');
    const editing = ref<OfflineEntry | null>(null);
    const productFilter = ref('');
    const today = new Date().toISOString().slice(0, 10);

    const form = reactive({
        date: today, contact_id: '' as number | '', lines: [{ variation_id: '', quantity: 1, price: 0 }] as Line[],
        payNow: false, method: 'cash', payment_account: '' as number | '', direction: 'receipt', transaction_id: '' as number | '', amount: 0,
        expense_account_id: '' as number | '', description: '', contactType: 'customer', ntn_number: '', first_name: '', business_name: '', mobile: '', address: '', note: '',
    });

    const kinds = computed(() => (Object.keys(KIND_LABEL) as OfflineKind[]).filter((key) => (reference.value?.allowed?.[key] ?? []).length > 0));
    const products = computed(() => {
        const needle = productFilter.value.trim().toLowerCase();
        const all = reference.value?.products ?? [];

        return (needle === '' ? all : all.filter((p) => p.name.toLowerCase().includes(needle) || p.sku.toLowerCase().includes(needle) || p.barcodes.some((b) => b.includes(needle)))).slice(0, 300);
    });
    const contacts = computed(() => (reference.value?.contacts ?? []).filter((c) => (kind.value === 'purchase' ? c.type === 'supplier' : c.type === 'customer')));
    const invoices = computed(() => (reference.value?.open_invoices ?? []).filter((i) => i.direction === form.direction));
    const lineTotal = computed(() => form.lines.reduce((sum, l) => sum + Number(l.quantity || 0) * Number(l.price || 0), 0));
    const pendingTotal = computed(() => entries.value.filter((e) => e.status === 'pending').reduce((sum, e) => sum + e.total, 0));
    const stale = computed(() => isStale(offline.referenceAt));
    const money = (value: number) => value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function product(variationId: number | '') {
        return reference.value?.products.find((p) => p.variation_id === variationId);
    }

    function pickProduct(line: Line) {
        const picked = product(line.variation_id);

        if (picked) {
            line.price = kind.value === 'purchase' ? picked.purchase_price : kind.value === 'stock_adjustment' ? 0 : picked.sell_price;
        }
    }

    function reset() {
        editing.value = null;
        Object.assign(form, { date: today, contact_id: '', lines: [{ variation_id: '', quantity: 1, price: 0 }], payNow: false, amount: 0, description: '', ntn_number: '', first_name: '', business_name: '', mobile: '', address: '', note: '', transaction_id: '' });
    }

    function payload(): { data: Record<string, unknown>; total: number } {
        const lines = form.lines.filter((l) => l.variation_id !== '').map((l) => {
            const p = product(l.variation_id);

            return { product_id: p?.product_id, variation_id: l.variation_id, unit_id: p?.unit_id, quantity: Number(l.quantity), unit_price: Number(l.price), unit_cost: Number(l.price), name: p?.name };
        });
        const account = form.payment_account === '' ? reference.value?.payment_accounts[0]?.id : form.payment_account;

        switch (kind.value) {
            case 'sale':
            case 'purchase':
                return {
                    total: lineTotal.value,
                    data: { contact_id: form.contact_id, date: form.date, lines, payment: form.payNow ? { amount: lineTotal.value, method: form.method, payment_account: account } : null },
                };
            case 'payment':
                return { total: Number(form.amount), data: { direction: form.direction, transaction_id: form.transaction_id, amount: Number(form.amount), date: form.date, method: form.method, payment_account: account } };
            case 'expense':
                return { total: Number(form.amount), data: { expense_account_id: form.expense_account_id, payment_account_id: account, amount: Number(form.amount), description: form.description, date: form.date } };
            case 'contact':
                return { total: 0, data: { type: form.contactType, first_name: form.first_name, business_name: form.business_name, mobile: form.mobile, address: form.address, ntn_number: form.ntn_number } };
            default:
                return { total: 0, data: { date: form.date, note: form.note, lines: lines.map((l) => ({ ...l, quantity: Number(l.quantity) })) } };
        }
    }

    function fill(entry: OfflineEntry) {
        const p = entry.payload as Record<string, any>;
        kind.value = entry.kind;
        editing.value = entry;
        form.date = p.date ?? today;
        form.contact_id = p.contact_id ?? '';
        form.lines = (p.lines ?? [{ variation_id: '', quantity: 1, unit_price: 0 }]).map((l: any) => ({ variation_id: l.variation_id ?? '', quantity: l.quantity ?? 1, price: kind.value === 'purchase' ? (l.unit_cost ?? 0) : (l.unit_price ?? 0) }));
        form.payNow = Boolean(p.payment);
        form.direction = p.direction ?? 'receipt';
        form.transaction_id = p.transaction_id ?? '';
        form.amount = p.amount ?? 0;
        form.expense_account_id = p.expense_account_id ?? '';
        form.description = p.description ?? '';
        form.contactType = p.type ?? 'customer';
        form.first_name = p.first_name ?? '';
        form.business_name = p.business_name ?? '';
        form.mobile = p.mobile ?? '';
        form.address = p.address ?? '';
        form.ntn_number = p.ntn_number ?? '';
        form.note = p.note ?? '';
        window.scrollTo({ top: 0 });
    }

    function valid(): string {
        const data = payload().data as Record<string, any>;

        if ((kind.value === 'sale' || kind.value === 'purchase') && (! data.contact_id || ! data.lines.length)) {
            return 'Choose the party and at least one product.';
        }

        if (kind.value === 'payment' && (! data.transaction_id || ! (data.amount > 0))) {
            return 'Choose the invoice and an amount.';
        }

        if (kind.value === 'expense' && (! data.expense_account_id || ! (data.amount > 0))) {
            return 'Choose the expense account and an amount.';
        }

        if (kind.value === 'contact' && (! String(data.business_name).trim() || ! String(data.first_name).trim() || ! String(data.address).trim() || ! String(data.mobile).trim() || ! String(data.ntn_number).trim())) {
            return 'A contact person, business name, mobile, address and NTN are needed.';
        }

        if (kind.value === 'stock_adjustment' && ! data.lines.length) {
            return 'Add at least one product.';
        }

        return '';
    }

    async function save() {
        failure.value = valid();
        message.value = '';

        if (failure.value) {
            return;
        }

        const { data, total } = payload();

        try {
            if (editing.value) {
                await retryEntry(editing.value, data, total);
                message.value = 'Changed and sent again.';
            } else {
                const entry = await enqueue(kind.value, data, total);
                message.value = `Saved on this device as ${entry.provisional_ref}. It is sent when the internet is available.`;
            }

            reset();
        } catch (error: unknown) {
            failure.value = (error as Error).message || 'It could not be saved.';
        }

        await load();
    }

    async function discard(entry: OfflineEntry) {
        const reason = window.prompt('Why is this entry being discarded? (it goes in the audit log)', '');

        if (reason === null || reason.trim().length < 3) {
            return;
        }

        if (! window.confirm(`Discard ${entry.provisional_ref}? It will never be posted.`)) {
            return;
        }

        try {
            await discardEntry(entry, reason.trim());
            message.value = 'Discarded.';
        } catch {
            failure.value = 'The discard could not be recorded (are you online?).';
        }

        await load();
    }

    async function load() {
        entries.value = await listEntries();
        reference.value = await loadReference();

        if (kinds.value.length && ! kinds.value.includes(kind.value)) {
            kind.value = kinds.value[0];
        }
    }

    async function refresh() {
        await refreshReference(true);
        await load();
    }

    watch(() => [offline.pending, offline.conflicts, offline.referenceAt, offline.syncing], load);
    watch(kind, () => {
        if (! editing.value) {
            reset();
        }
    });
    onMounted(async () => {
        await load();
        await refreshReference();
        await load();
    });
</script>

<template>
    <Head title="Offline entries" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3 small" data-test="offline-summary">
            <span><strong>{{ offline.online ? 'Online' : 'Offline' }}</strong></span>
            <span data-test="offline-pending-count">{{ offline.pending }} waiting ({{ money(pendingTotal) }})</span>
            <span>{{ offline.conflicts }} need a decision</span>
            <span v-if="offline.posPending || offline.posConflicts">POS terminal: {{ offline.posPending }} waiting, {{ offline.posConflicts }} to review (<a href="/sell/pos/offline">open</a>)</span>
            <span>Last synced: {{ offline.lastSyncedAt ? offline.lastSyncedAt.slice(0, 16).replace('T', ' ') : 'never' }}</span>
            <span :class="stale ? 'text-warning' : 'text-muted'" data-test="offline-reference-age">
                Reference data: {{ offline.referenceAt ? offline.referenceAt.slice(0, 16).replace('T', ' ') : 'not loaded' }}{{ stale ? ' (old - connect to refresh)' : '' }}
            </span>
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="! offline.online" @click="refresh">Refresh data</button>
            <button type="button" class="btn btn-dark btn-sm" data-test="offline-page-sync" :disabled="! offline.online || offline.syncing" @click="syncAll">Sync now</button>
        </div>
        <p v-if="offline.error" class="alert alert-warning py-2">{{ offline.error }} <a href="/login">Sign in</a></p>

        <div v-if="! reference" class="alert alert-info" data-test="offline-no-reference">Open this page once while connected so the products, customers and accounts are saved on this device. Then entries can be made without internet.</div>

        <form v-else class="mb-4" data-test="offline-form" @submit.prevent="save">
            <h2 class="h6">{{ editing ? `Edit ${editing.provisional_ref}` : 'New entry' }}</h2>
            <div v-if="! kinds.length" class="text-muted">Your role has no entries that can be made offline.</div>
            <div v-else class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small" for="offline-kind">Kind</label>
                    <select id="offline-kind" v-model="kind" class="form-select form-select-sm" :disabled="editing !== null">
                        <option v-for="k in kinds" :key="k" :value="k">{{ KIND_LABEL[k] }}</option>
                    </select>
                </div>
                <div v-if="kind !== 'contact'" class="col-md-2">
                    <label class="form-label small" for="offline-date">Date</label>
                    <input id="offline-date" v-model="form.date" type="date" class="form-control form-control-sm">
                </div>

                <template v-if="kind === 'sale' || kind === 'purchase'">
                    <div class="col-md-4">
                        <label class="form-label small" for="offline-contact">{{ kind === 'sale' ? 'Customer' : 'Supplier' }}</label>
                        <select id="offline-contact" v-model="form.contact_id" class="form-select form-select-sm">
                            <option value="">Choose</option>
                            <option v-for="c in contacts" :key="c.id" :value="c.id">{{ c.name }}{{ c.blocked ? ' (blocked)' : '' }}</option>
                        </select>
                    </div>
                </template>

                <template v-if="kind === 'payment'">
                    <div class="col-md-2">
                        <label class="form-label small" for="offline-direction">Type</label>
                        <select id="offline-direction" v-model="form.direction" class="form-select form-select-sm"><option value="receipt">Receipt (from a customer)</option><option value="payment">Payment (to a supplier)</option></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="offline-invoice">Invoice</label>
                        <select id="offline-invoice" v-model="form.transaction_id" class="form-select form-select-sm" @change="form.amount = invoices.find((i) => i.id === form.transaction_id)?.due ?? 0">
                            <option value="">Choose</option>
                            <option v-for="i in invoices" :key="i.id" :value="i.id">{{ i.invoice_no }} - due {{ money(i.due) }}</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" for="offline-amount">Amount</label>
                        <input id="offline-amount" v-model.number="form.amount" type="number" step="0.01" min="0" class="form-control form-control-sm">
                    </div>
                </template>

                <template v-if="kind === 'expense'">
                    <div class="col-md-4">
                        <label class="form-label small" for="offline-expense-account">Expense account</label>
                        <select id="offline-expense-account" v-model="form.expense_account_id" class="form-select form-select-sm">
                            <option value="">Choose</option>
                            <option v-for="a in reference.expense_accounts" :key="a.id" :value="a.id">{{ a.code }} {{ a.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" for="offline-expense-amount">Amount</label>
                        <input id="offline-expense-amount" v-model.number="form.amount" type="number" step="0.01" min="0" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="offline-description">Description</label>
                        <input id="offline-description" v-model="form.description" type="text" class="form-control form-control-sm" maxlength="200">
                    </div>
                </template>

                <template v-if="kind === 'contact'">
                    <div class="col-md-2">
                        <label class="form-label small" for="offline-contact-type">Type</label>
                        <select id="offline-contact-type" v-model="form.contactType" class="form-select form-select-sm"><option value="customer">Customer</option><option value="supplier">Supplier</option></select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" for="offline-first-name">Contact person</label>
                        <input id="offline-first-name" v-model="form.first_name" type="text" class="form-control form-control-sm" maxlength="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="offline-name">Business name</label>
                        <input id="offline-name" v-model="form.business_name" type="text" class="form-control form-control-sm" maxlength="150">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" for="offline-mobile">Mobile</label>
                        <input id="offline-mobile" v-model="form.mobile" type="text" class="form-control form-control-sm" maxlength="30">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" for="offline-ntn">NTN</label>
                        <input id="offline-ntn" v-model="form.ntn_number" type="text" class="form-control form-control-sm" maxlength="50">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="offline-address">Address</label>
                        <input id="offline-address" v-model="form.address" type="text" class="form-control form-control-sm" maxlength="250">
                    </div>
                </template>

                <template v-if="kind === 'payment' || kind === 'expense' || ((kind === 'sale' || kind === 'purchase') && form.payNow)">
                    <div class="col-md-2">
                        <label class="form-label small" for="offline-method">Method</label>
                        <select id="offline-method" v-model="form.method" class="form-select form-select-sm"><option v-for="m in reference.methods" :key="m" :value="m">{{ m }}</option></select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="offline-account">Paid {{ kind === 'payment' || kind === 'expense' ? 'from / into' : 'through' }}</label>
                        <select id="offline-account" v-model="form.payment_account" class="form-select form-select-sm"><option v-for="a in reference.payment_accounts" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                    </div>
                </template>

                <div v-if="kind === 'sale' || kind === 'purchase'" class="col-md-3">
                    <div class="form-check mt-3">
                        <input id="offline-paynow" v-model="form.payNow" type="checkbox" class="form-check-input">
                        <label class="form-check-label small" for="offline-paynow">Paid now (otherwise on credit)</label>
                    </div>
                </div>
                <div v-if="kind === 'stock_adjustment'" class="col-md-4">
                    <label class="form-label small" for="offline-note">Note</label>
                    <input id="offline-note" v-model="form.note" type="text" class="form-control form-control-sm" maxlength="200">
                    <div class="form-text">Sent as pending: posting it is the approver's decision.</div>
                </div>
            </div>

            <div v-if="kind === 'sale' || kind === 'purchase' || kind === 'stock_adjustment'" class="mt-3">
                <div class="row g-2 align-items-end mb-1">
                    <div class="col-md-4"><label class="form-label small" for="offline-find">Find a product (name, SKU, barcode)</label><input id="offline-find" v-model="productFilter" type="search" class="form-control form-control-sm"></div>
                </div>
                <div v-for="(line, index) in form.lines" :key="index" class="row g-2 align-items-end mb-1" data-test="offline-line">
                    <div class="col-md-5">
                        <select v-model="line.variation_id" class="form-select form-select-sm" :aria-label="`Product ${index + 1}`" @change="pickProduct(line)">
                            <option value="">Product</option>
                            <option v-for="p in products" :key="p.variation_id" :value="p.variation_id">{{ p.name }} ({{ p.sku }})</option>
                        </select>
                    </div>
                    <div class="col-md-2"><input v-model.number="line.quantity" type="number" step="0.01" class="form-control form-control-sm" :aria-label="`Quantity ${index + 1}`"></div>
                    <div v-if="kind !== 'stock_adjustment'" class="col-md-2"><input v-model.number="line.price" type="number" step="0.01" min="0" class="form-control form-control-sm" :aria-label="`Price ${index + 1}`"></div>
                    <div class="col-auto"><button type="button" class="btn btn-outline-danger btn-sm" :disabled="form.lines.length === 1" @click="form.lines.splice(index, 1)">Remove</button></div>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm mt-1" @click="form.lines.push({ variation_id: '', quantity: 1, price: 0 })">Add a line</button>
                <span v-if="kind !== 'stock_adjustment'" class="ms-3 small">Total {{ money(lineTotal) }}</span>
            </div>

            <div class="mt-3 d-flex gap-2 align-items-center">
                <button type="submit" class="btn btn-dark btn-sm" data-test="offline-save">{{ editing ? 'Save changes and retry' : 'Save on this device' }}</button>
                <button v-if="editing" type="button" class="btn btn-outline-secondary btn-sm" @click="reset">Cancel edit</button>
                <span v-if="message" class="text-success small" data-test="offline-message">{{ message }}</span>
                <span v-if="failure" class="text-danger small" data-test="offline-failure">{{ failure }}</span>
            </div>
        </form>

        <h2 class="h6">Entries on this device</h2>
        <div class="table-responsive">
            <table class="table table-sm" data-test="offline-entries">
                <thead><tr><th>Provisional no.</th><th>Kind</th><th>Made</th><th>What</th><th class="text-end">Total</th><th>Status</th><th>Real no.</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="entry in entries" :key="entry.uuid" :data-status="entry.status">
                        <td>{{ entry.provisional_ref }} <span class="badge text-bg-secondary">provisional</span></td>
                        <td>{{ KIND_LABEL[entry.kind] }}</td>
                        <td>{{ entry.created_at.slice(0, 16).replace('T', ' ') }}</td>
                        <td>{{ entry.summary }}</td>
                        <td class="text-end">{{ money(entry.total) }}</td>
                        <td>
                            <span v-if="entry.status === 'conflict'" class="text-danger" data-test="offline-conflict">Needs a decision: {{ CONFLICT_TEXT[entry.conflict_code ?? 'rejected'] ?? entry.conflict_code }}<span v-if="entry.message" class="d-block small text-muted">{{ entry.message }}</span></span>
                            <span v-else-if="entry.status === 'synced'" class="text-success">Sent</span>
                            <span v-else>Waiting</span>
                        </td>
                        <td>{{ entry.real_ref ?? '-' }}</td>
                        <td class="text-end">
                            <template v-if="entry.status === 'conflict'">
                                <button type="button" class="btn btn-dark btn-sm me-1" data-test="offline-edit" @click="fill(entry)">Edit and retry</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" data-test="offline-discard" @click="discard(entry)">Discard</button>
                            </template>
                            <button v-else-if="entry.status === 'pending'" type="button" class="btn btn-outline-danger btn-sm" @click="discard(entry)">Remove</button>
                        </td>
                    </tr>
                    <tr v-if="! entries.length"><td colspan="8" class="text-center text-muted">Nothing is waiting on this device.</td></tr>
                </tbody>
            </table>
        </div>
    </div></div></div>
</template>
