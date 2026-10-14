<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Tax Payments',
            subtitle: 'Taxes paid to the authority, with the challan of each payment',
            breadcrumbs: [{ title: 'Tax Payments', href: 'NULL' }],
        },
    });

    type Payment = {
        id: number; tax_type: string; period: string | null; challan_no: string; bank_name: string | null; amount: number; paid_on: string;
        tax_account: string | null; voucher_status: string | null;
    };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const rows = ref<Payment[]>([]);
    const summary = ref<{ tax_type: string; payments: number; total: number }[]>([]);
    const range = ref({ from: '', to: '' });
    const form = ref({ tax_type: '', period: '', challan_no: '', bank_name: '', amount: '' as number | '', paid_on: new Date().toISOString().slice(0, 10), tax_account_id: '' as number | '', bank_account_id: '' as number | '', note: '' });

    async function load() {
        const params: Record<string, unknown> = { ...scope() };

        for (const key of ['from', 'to'] as const) {
            if (range.value[key]) {
                params[key] = range.value[key];
            }
        }

        rows.value = (await run(() => window.axios.get('/api/tax-payments', { params })))?.data?.data?.data ?? [];
        summary.value = (await run(() => window.axios.get('/api/tax-payments/summary', { params })))?.data?.data ?? [];
    }

    async function create() {
        if (await run(() => window.axios.post('/api/tax-payments', { ...scope(), ...form.value }), 'Tax payment recorded')) {
            form.value = { ...form.value, challan_no: '', amount: '', note: '' };
            await load();
        }
    }

    async function remove(row: Payment) {
        if (window.confirm(`Delete challan ${row.challan_no} and its voucher?`) && await run(() => window.axios.delete(`/api/tax-payments/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Tax Payments" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <input v-model="range.from" type="date" class="form-control form-control-sm" style="width: auto" aria-label="From" @change="load" />
            <input v-model="range.to" type="date" class="form-control form-control-sm" style="width: auto" aria-label="To" @change="load" />
        </div>

        <div v-if="can('/taxpayment/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label small" for="tp-type">Tax</label><input id="tp-type" v-model="form.tax_type" class="form-control form-control-sm" placeholder="Sales tax, Income tax withholding..." /></div>
                <div class="col-md-2"><label class="form-label small" for="tp-period">Period</label><input id="tp-period" v-model="form.period" class="form-control form-control-sm" placeholder="2026-09" /></div>
                <div class="col-md-2"><label class="form-label small" for="tp-challan">Challan no.</label><input id="tp-challan" v-model="form.challan_no" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="tp-bank">Bank</label><input id="tp-bank" v-model="form.bank_name" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="tp-date">Paid on</label><input id="tp-date" v-model="form.paid_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="tp-amount">Amount</label><input id="tp-amount" v-model.number="form.amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="tp-tax">Tax payable account id</label><input id="tp-tax" v-model.number="form.tax_account_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="tp-from">Bank account id</label><input id="tp-from" v-model.number="form.bank_account_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-4"><label class="form-label small" for="tp-note">Note</label><input id="tp-note" v-model="form.note" class="form-control form-control-sm" /></div>
                <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-dark btn-sm" @click="create">Record</button></div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-8">
                <ClientTable empty-text="No tax payments."  :columns="[{ key: 'c0', label: 'Date' }, { key: 'c1', label: 'Tax' }, { key: 'c2', label: 'Period' }, { key: 'c3', label: 'Challan' }, { key: 'c4', label: 'Bank' }, { key: 'c5', label: 'Amount' }, { key: 'c6', label: 'Voucher' }, { key: 'c7', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.paid_on?.slice(0, 10)), c1: (row.tax_type), c2: (row.period), c3: (row.challan_no), c4: (row.bank_name), c5: (money(row.amount)), c6: (row.voucher_status), c7: '' }))" export-name="date">
<template #cell-c7="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><button v-if="can('/taxpayment/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button></template></template>
</ClientTable>
            </div>
            <div class="col-lg-4">
                <h6>By tax</h6>
                <ClientTable empty-text="-"  :columns="[{ key: 'c0', label: 'Tax' }, { key: 'c1', label: 'Payments' }, { key: 'c2', label: 'Total' }]" :rows="(summary).map((row) => ({ __s: { row }, c0: (row.tax_type), c1: (row.payments), c2: (money(row.total)) }))" export-name="tax">
</ClientTable>
            </div>
        </div>
    </div></div></div>
</template>
