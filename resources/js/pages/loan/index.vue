<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Loans & Financing',
            subtitle: 'Loan register with its repayment schedule and instalment vouchers',
            breadcrumbs: [{ title: 'Loans & Financing', href: 'NULL' }],
        },
    });

    type Loan = {
        id: number; name: string; lender: string | null; principal: number; annual_rate: number; installments: number; frequency: string; start_date: string;
        status: string; outstanding_principal: number; paid_instalments: number; next_due_on: string | null; next_amount: number | null;
    };
    type Row = { id: number; number: number; due_on: string; principal: number; interest: number; total: number; paid_on: string | null; voucher_status: string | null };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const rows = ref<Loan[]>([]);
    const open = ref<{ loan: Loan; schedule: Row[] } | null>(null);
    const form = ref({
        name: '', lender: '', principal: '' as number | '', annual_rate: 0, installments: 12, frequency: 'monthly', start_date: new Date().toISOString().slice(0, 10),
        loan_account_id: '' as number | '', interest_account_id: '' as number | '', bank_account_id: '' as number | '', post_disbursement: true,
    });
    const paidOn = ref(new Date().toISOString().slice(0, 10));

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/loans', { params: scope() })))?.data?.data ?? [];
    }

    async function show(loan: Loan) {
        const result = (await run(() => window.axios.get(`/api/loans/${loan.id}`)))?.data;

        if (result) {
            const { schedule, ...rest } = result;
            open.value = { loan: { ...loan, ...rest }, schedule };
        }
    }

    async function create() {
        if (await run(() => window.axios.post('/api/loans', { ...scope(), ...form.value }), 'Loan saved')) {
            await load();
        }
    }

    async function pay(row?: Row) {
        if (! open.value) {
            return;
        }

        const body: Record<string, unknown> = { paid_on: paidOn.value };

        if (row) {
            body.row_id = row.id;
        }

        if (await run(() => window.axios.post(`/api/loans/${open.value?.loan.id}/pay`, body), 'Instalment paid')) {
            await load();
            const loan = rows.value.find((item) => item.id === open.value?.loan.id);

            if (loan) {
                await show(loan);
            }
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Loans & Financing" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div v-if="can('/loan/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label small" for="ln-name">Name</label><input id="ln-name" v-model="form.name" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="ln-lender">Lender</label><input id="ln-lender" v-model="form.lender" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ln-principal">Principal</label><input id="ln-principal" v-model.number="form.principal" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ln-rate">Annual rate %</label><input id="ln-rate" v-model.number="form.annual_rate" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ln-start">Start date</label><input id="ln-start" v-model="form.start_date" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ln-count">Instalments</label><input id="ln-count" v-model.number="form.installments" type="number" min="1" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ln-freq">Frequency</label>
                    <select id="ln-freq" v-model="form.frequency" class="form-select form-select-sm"><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option></select>
                </div>
                <div class="col-md-2"><label class="form-label small" for="ln-loan">Loan account id</label><input id="ln-loan" v-model.number="form.loan_account_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ln-int">Interest account id</label><input id="ln-int" v-model.number="form.interest_account_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ln-bank">Bank account id</label><input id="ln-bank" v-model.number="form.bank_account_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-12"><label class="form-check-label small"><input v-model="form.post_disbursement" type="checkbox" class="form-check-input me-1" />Post the disbursement voucher (bank debited, loan account credited)</label></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3" @click="create">Add loan</button>
        </div>

        <div class="row g-3">
            <div :class="open ? 'col-lg-6' : 'col-12'">
                <ClientTable empty-text="No loans."  :columns="[{ key: 'c0', label: 'Loan' }, { key: 'c1', label: 'Principal' }, { key: 'c2', label: 'Outstanding' }, { key: 'c3', label: 'Next due' }, { key: 'c4', label: 'Status' }, { key: 'c5', label: '' }]" :rows="(rows).map((loan) => ({ __s: { loan }, c0: '', c1: (money(loan.principal)), c2: (money(loan.outstanding_principal)), c3: '', c4: (loan.status), c5: '' }))" export-name="loan">
<template #cell-c0="{ row: __m }"><template v-for="{ loan } in [__m.__s]" :key="0">{{ loan.name }}<small class="text-muted d-block">{{ loan.lender }}</small></template></template>
<template #cell-c3="{ row: __m }"><template v-for="{ loan } in [__m.__s]" :key="0">{{ loan.next_due_on?.slice(0, 10) }} <small class="text-muted">{{ loan.next_amount === null ? '' : money(loan.next_amount) }}</small></template></template>
<template #cell-c5="{ row: __m }"><template v-for="{ loan } in [__m.__s]" :key="0"><button type="button" class="btn btn-outline-dark btn-sm" @click="show(loan)">Schedule</button></template></template>
</ClientTable>
            </div>
            <div v-if="open" class="col-lg-6">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h6 class="mb-0">{{ open.loan.name }}</h6>
                    <template v-if="can('/loan/pay') && open.loan.status === 'active'">
                        <input v-model="paidOn" type="date" class="form-control form-control-sm" style="width: auto" aria-label="Paid on" />
                        <button type="button" class="btn btn-dark btn-sm" @click="pay()">Pay next instalment</button>
                    </template>
                </div>
                <ClientTable  :columns="[{ key: 'c0', label: '#' }, { key: 'c1', label: 'Due' }, { key: 'c2', label: 'Principal' }, { key: 'c3', label: 'Interest' }, { key: 'c4', label: 'Total' }, { key: 'c5', label: 'Paid' }]" :rows="(open.schedule).map((row) => ({ __s: { row }, c0: (row.number), c1: (row.due_on?.slice(0, 10)), c2: (money(row.principal)), c3: (money(row.interest)), c4: (money(row.total)), c5: '' }))" export-name="table">
<template #cell-c5="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ row.paid_on?.slice(0, 10) }} <small class="text-muted text-capitalize">{{ row.voucher_status }}</small></template></template>
</ClientTable>
            </div>
        </div>
    </div></div></div>
</template>
