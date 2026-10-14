<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Store Credit & Refunds',
            subtitle: 'Keep a sale return as store credit, pay it out, or apply it to an invoice',
            breadcrumbs: [{ title: 'Store Credit & Refunds', href: 'NULL' }],
        },
    });

    type Balance = { contact_id: number; customer: string | null; balance: number; entries: number };
    type Entry = { id: number; entry_type: string; amount: number; reference: string | null; note: string | null; created_at: string };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const rows = ref<Balance[]>([]);
    const lookup = ref<number | ''>('');
    const entries = ref<Entry[]>([]);
    const balance = ref<number | null>(null);

    const issue = ref({ sell_return_id: '' as number | '', amount: '' as number | '', note: '' });
    const refund = ref({ contact_id: '' as number | '', amount: '' as number | '', paid_on: new Date().toISOString().slice(0, 10), payment_account: '' as number | '', method: 'cash', note: '' });
    const apply = ref({ contact_id: '' as number | '', transaction_id: '' as number | '', amount: '' as number | '', paid_on: new Date().toISOString().slice(0, 10) });

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/store-credits', { params: scope() })))?.data?.data ?? [];
    }

    async function show(contactId: number | '') {
        if (contactId === '') {
            return;
        }

        const result = (await run(() => window.axios.get('/api/store-credit', { params: { contact_id: contactId } })))?.data;
        entries.value = result?.entries ?? [];
        balance.value = result?.balance ?? null;
    }

    async function submit(url: string, body: Record<string, unknown>, success: string) {
        if (await run(() => window.axios.post(url, body), success)) {
            await load();
            await show(lookup.value);
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Store Credit & Refunds" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div class="row g-3 mb-4">
            <div v-if="can('/storecredit/add')" class="col-lg-4">
                <div class="border rounded p-3 h-100">
                    <h6>Keep a return as store credit</h6>
                    <label class="form-label small" for="sc-return">Sale return id</label><input id="sc-return" v-model.number="issue.sell_return_id" type="number" class="form-control form-control-sm mb-2" />
                    <label class="form-label small" for="sc-amount">Amount</label><input id="sc-amount" v-model.number="issue.amount" type="number" min="0" step="any" class="form-control form-control-sm mb-2" />
                    <label class="form-label small" for="sc-note">Note</label><input id="sc-note" v-model="issue.note" class="form-control form-control-sm mb-2" />
                    <button type="button" class="btn btn-dark btn-sm" @click="submit('/api/store-credit/issue', { ...issue }, 'Store credit issued')">Issue credit</button>
                </div>
            </div>
            <div v-if="can('/storecredit/refund')" class="col-lg-4">
                <div class="border rounded p-3 h-100">
                    <h6>Refund store credit</h6>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label small" for="rf-customer">Customer id</label><input id="rf-customer" v-model.number="refund.contact_id" type="number" class="form-control form-control-sm" /></div>
                        <div class="col-6"><label class="form-label small" for="rf-amount">Amount</label><input id="rf-amount" v-model.number="refund.amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                        <div class="col-6"><label class="form-label small" for="rf-date">Paid on</label><input id="rf-date" v-model="refund.paid_on" type="date" class="form-control form-control-sm" /></div>
                        <div class="col-6"><label class="form-label small" for="rf-method">Method</label>
                            <select id="rf-method" v-model="refund.method" class="form-select form-select-sm"><option v-for="m in ['cash', 'bank', 'cheque', 'card', 'online', 'other']" :key="m" :value="m">{{ m }}</option></select>
                        </div>
                        <div class="col-12"><label class="form-label small" for="rf-account">Cash / bank account id</label><input id="rf-account" v-model.number="refund.payment_account" type="number" class="form-control form-control-sm" /></div>
                    </div>
                    <button type="button" class="btn btn-dark btn-sm mt-2" @click="submit('/api/store-credit/refund', { ...refund }, 'Refund paid')">Pay refund</button>
                </div>
            </div>
            <div v-if="can('/storecredit/apply')" class="col-lg-4">
                <div class="border rounded p-3 h-100">
                    <h6>Apply credit to an invoice</h6>
                    <label class="form-label small" for="ap-customer">Customer id</label><input id="ap-customer" v-model.number="apply.contact_id" type="number" class="form-control form-control-sm mb-2" />
                    <label class="form-label small" for="ap-invoice">Invoice id</label><input id="ap-invoice" v-model.number="apply.transaction_id" type="number" class="form-control form-control-sm mb-2" />
                    <label class="form-label small" for="ap-amount">Amount</label><input id="ap-amount" v-model.number="apply.amount" type="number" min="0" step="any" class="form-control form-control-sm mb-2" />
                    <label class="form-label small" for="ap-date">Date</label><input id="ap-date" v-model="apply.paid_on" type="date" class="form-control form-control-sm mb-2" />
                    <button type="button" class="btn btn-dark btn-sm" @click="submit('/api/store-credit/apply', { ...apply }, 'Store credit applied')">Apply</button>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <h6>Customers with a balance</h6>
                <ClientTable empty-text="No store credit outstanding."  :columns="[{ key: 'c0', label: 'Customer' }, { key: 'c1', label: 'Balance' }, { key: 'c2', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.customer), c1: (money(row.balance)), c2: '' }))" export-name="customer">
<template #cell-c2="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><button type="button" class="btn btn-outline-dark btn-sm" @click="lookup = row.contact_id; show(row.contact_id)">History</button></template></template>
</ClientTable>
            </div>
            <div class="col-lg-6">
                <h6>History <span v-if="balance !== null" class="text-muted">(balance {{ money(balance) }})</span></h6>
                <div class="input-group input-group-sm mb-2" style="max-width: 18rem">
                    <input v-model.number="lookup" type="number" class="form-control" placeholder="Customer id" aria-label="Customer id" />
                    <button type="button" class="btn btn-outline-dark" @click="show(lookup)">Show</button>
                </div>
                <ClientTable empty-text="Pick a customer."  :columns="[{ key: 'c0', label: 'Date' }, { key: 'c1', label: 'Type' }, { key: 'c2', label: 'Reference' }, { key: 'c3', label: 'Amount' }]" :rows="(entries).map((entry) => ({ __s: { entry }, c0: (entry.created_at?.slice(0, 10)), c1: (entry.entry_type), c2: (entry.reference), c3: (money(entry.amount)) }))" export-name="date">
</ClientTable>
            </div>
        </div>
    </div></div></div>
</template>
