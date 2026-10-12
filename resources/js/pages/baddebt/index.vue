<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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

    <div class="container-fluid py-3">
        <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm mb-3" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />

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
            <table v-if="preview" class="table table-sm mb-0">
                <thead><tr><th>Bucket</th><th class="text-end">Owed</th><th class="text-end">Rate %</th><th class="text-end">Allowance</th></tr></thead>
                <tbody>
                    <tr v-for="(bucket, key) in preview.buckets" :key="key"><td>{{ key }}</td><td class="text-end">{{ money(bucket.amount) }}</td><td class="text-end">{{ bucket.rate }}</td><td class="text-end">{{ money(bucket.required) }}</td></tr>
                    <tr class="fw-semibold"><td>Required</td><td colspan="2" class="text-end">Held now {{ money(preview.existing) }}</td><td class="text-end">{{ money(preview.required) }} (change {{ money(preview.change) }})</td></tr>
                </tbody>
            </table>
        </div>

        <h6>Overdue invoices</h6>
        <div class="table-responsive mb-4">
            <table class="table table-sm">
                <thead><tr><th>Customer</th><th>Invoice</th><th>Due</th><th class="text-end">Days</th><th class="text-end">Owed</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="invoice in invoices" :key="invoice.id">
                        <td>{{ invoice.contact_name }}</td><td>{{ invoice.invoice_no }}</td><td>{{ invoice.due_date }}</td><td class="text-end">{{ invoice.days_past_due }}</td><td class="text-end">{{ money(invoice.open_amount) }}</td>
                        <td v-if="can('/baddebt/add')" class="text-end">
                            <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="request(invoice, 'direct')">Write off</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" @click="request(invoice, 'allowance')">Against allowance</button>
                        </td>
                    </tr>
                    <tr v-if="! invoices.length"><td colspan="6" class="text-center text-muted">No overdue invoices.</td></tr>
                </tbody>
            </table>
        </div>

        <h6>Write-offs</h6>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Date</th><th>Invoice id</th><th class="text-end">Amount</th><th>Method</th><th>Reason</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in writeoffs" :key="row.id">
                        <td>{{ row.written_off_on }}</td><td>{{ row.transaction_id }}</td><td class="text-end">{{ money(row.amount) }}</td><td class="text-capitalize">{{ row.method }}</td><td>{{ row.reason }}</td><td class="text-capitalize">{{ row.status }}</td>
                        <td class="text-end">
                            <template v-if="row.status === 'pending' && can('/baddebt/approve')">
                                <button type="button" class="btn btn-dark btn-sm me-1" @click="decide(row, 'approve')">Approve</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="decide(row, 'reject')">Reject</button>
                            </template>
                            <button v-if="row.status === 'posted' && can('/baddebt/reverse')" type="button" class="btn btn-outline-danger btn-sm" @click="decide(row, 'reverse')">Reverse</button>
                        </td>
                    </tr>
                    <tr v-if="! writeoffs.length"><td colspan="7" class="text-center text-muted">None yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
