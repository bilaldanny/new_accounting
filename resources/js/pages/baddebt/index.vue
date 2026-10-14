<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Bad Debt',
            subtitle: 'Write-offs with approval and the allowance for doubtful debts',
            breadcrumbs: [{ title: 'Bad Debt', href: 'NULL' }],
        },
    });

    type Invoice = { id: number; contact_name: string; invoice_no: string | null; due_date: string; open_amount: number; days_past_due: number; bucket: string };
    type Writeoff = { id: number; transaction_id: number; amount: number; written_off_on: string; method: string; reason: string; status: string };
    type Preview = { as_of: string; buckets: Record<string, { amount: number; rate: number; required: number }>; required: number; existing: number; change: number; ready: boolean };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const invoices = ref<Invoice[]>([]);
    const writeoffs = ref<Writeoff[]>([]);
    const preview = ref<Preview | null>(null);
    const asOf = ref(new Date().toISOString().slice(0, 10));
    const settings = ref({ expense_account_id: '' as number | '', allowance_account_id: '' as number | '', rates: { not_due: 0, days_0_30: 1, days_31_60: 5, days_61_90: 20, days_90_plus: 50 } as Record<string, number> });

    async function load() {
        const [open, list, current] = await Promise.all([
            run(() => window.axios.get('/api/bad-debt/invoices', { params: scope() })),
            run(() => window.axios.get('/api/bad-debt/writeoffs', { params: scope() })),
            run(() => window.axios.get('/api/bad-debt/settings', { params: scope() })),
        ]);
        invoices.value = open?.data?.data ?? [];
        writeoffs.value = list?.data?.data ?? [];
        const data = current?.data?.data;

        if (data) {
            settings.value = { expense_account_id: data.expense_account_id ?? '', allowance_account_id: data.allowance_account_id ?? '', rates: { ...settings.value.rates, ...(data.rates ?? {}) } };
        }
    }

    async function request(invoice: Invoice, method: 'direct' | 'allowance') {
        const reason = window.prompt(`Why is ${invoice.invoice_no ?? 'this invoice'} written off?`, '');

        if (! reason) {
            return;
        }

        const body = { ...scope(), transaction_id: invoice.id, amount: invoice.open_amount, written_off_on: new Date().toISOString().slice(0, 10), method, reason };

        if (await run(() => window.axios.post('/api/bad-debt/writeoffs', body), 'Write-off requested')) {
            await load();
        }
    }

    async function decide(row: Writeoff, action: 'approve' | 'reject' | 'reverse') {
        if (await run(() => window.axios.post(`/api/bad-debt/writeoffs/${row.id}/${action}`, scope()), 'Saved')) {
            await load();
        }
    }

    async function saveSettings() {
        const body = { ...scope(), expense_account_id: settings.value.expense_account_id || null, allowance_account_id: settings.value.allowance_account_id || null, rates: settings.value.rates };

        await run(() => window.axios.put('/api/bad-debt/settings', body), 'Settings saved');
    }

    async function showPreview() {
        const response = await run(() => window.axios.get('/api/bad-debt/provision', { params: { ...scope(), as_of: asOf.value } }));
        preview.value = response?.data?.data ?? null;
    }

    async function provision() {
        if (await run(() => window.axios.post('/api/bad-debt/provision', { ...scope(), as_of: asOf.value }), 'Allowance set')) {
            await showPreview();
        }
    }

    onMounted(async () => {
        await load();
        await showPreview();
    });
</script>

<template>
    <Head title="Bad Debt" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div v-if="can('/baddebt/settings')" class="border rounded p-3 mb-4">
            <h6>Settings</h6>
            <div class="row g-2 align-items-end">
                <div class="col-md-2"><label class="form-label small" for="bd-exp">Bad debt expense account id</label><input id="bd-exp" v-model.number="settings.expense_account_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="bd-alw">Allowance account id</label><input id="bd-alw" v-model.number="settings.allowance_account_id" type="number" class="form-control form-control-sm" /></div>
                <div v-for="(label, key) in { not_due: 'Not due %', days_0_30: '0-30 %', days_31_60: '31-60 %', days_61_90: '61-90 %', days_90_plus: '90+ %' }" :key="key" class="col-md-1">
                    <label class="form-label small" :for="`bd-${key}`">{{ label }}</label><input :id="`bd-${key}`" v-model.number="settings.rates[key]" type="number" class="form-control form-control-sm" />
                </div>
                <div class="col-auto"><button type="button" class="btn btn-dark btn-sm" @click="saveSettings">Save</button></div>
            </div>
        </div>

        <div class="border rounded p-3 mb-4">
            <h6>Allowance for doubtful debts</h6>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-auto"><input v-model="asOf" type="date" class="form-control form-control-sm" aria-label="As of" @change="showPreview" /></div>
                <div v-if="can('/baddebt/provision')" class="col-auto"><button type="button" class="btn btn-dark btn-sm" :disabled="! preview?.ready" @click="provision">Set allowance</button></div>
            </div>
            <ClientTable v-if="preview" :columns="[{ key: 'c0', label: 'Bucket' }, { key: 'c1', label: 'Owed' }, { key: 'c2', label: 'Rate %' }, { key: 'c3', label: 'Allowance' }]" :rows="(preview.buckets).map((bucket, key) => ({ __s: { bucket, key }, c0: (key), c1: (money(bucket.amount)), c2: (bucket.rate), c3: (money(bucket.required)) }))" export-name="bucket">
</ClientTable>
        </div>

        <h6>Overdue invoices</h6>
        <div class="table-responsive mb-4">
            <ClientTable empty-text="No overdue invoices."  :columns="[{ key: 'c0', label: 'Customer' }, { key: 'c1', label: 'Invoice' }, { key: 'c2', label: 'Due' }, { key: 'c3', label: 'Days' }, { key: 'c4', label: 'Owed' }, { key: 'c5', label: '' }]" :rows="(invoices).map((invoice) => ({ __s: { invoice }, c0: (invoice.contact_name), c1: (invoice.invoice_no), c2: (invoice.due_date), c3: (invoice.days_past_due), c4: (money(invoice.open_amount)), c5: '' }))" export-name="customer">
<template #cell-c5="{ row: __m }"><template v-for="{ invoice } in [__m.__s]" :key="0">
                            <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="request(invoice, 'direct')">Write off</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" @click="request(invoice, 'allowance')">Against allowance</button>
                        </template></template>
</ClientTable>
        </div>

        <h6>Write-offs</h6>
        <div class="table-responsive">
            <ClientTable empty-text="None yet."  :columns="[{ key: 'c0', label: 'Date' }, { key: 'c1', label: 'Invoice id' }, { key: 'c2', label: 'Amount' }, { key: 'c3', label: 'Method' }, { key: 'c4', label: 'Reason' }, { key: 'c5', label: 'Status' }, { key: 'c6', label: '' }]" :rows="(writeoffs).map((row) => ({ __s: { row }, c0: (row.written_off_on), c1: (row.transaction_id), c2: (money(row.amount)), c3: (row.method), c4: (row.reason), c5: (row.status), c6: '' }))" export-name="date">
<template #cell-c6="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                            <template v-if="row.status === 'pending' && can('/baddebt/approve')">
                                <button type="button" class="btn btn-dark btn-sm me-1" @click="decide(row, 'approve')">Approve</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="decide(row, 'reject')">Reject</button>
                            </template>
                            <button v-if="row.status === 'posted' && can('/baddebt/reverse')" type="button" class="btn btn-outline-danger btn-sm" @click="decide(row, 'reverse')">Reverse</button>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
