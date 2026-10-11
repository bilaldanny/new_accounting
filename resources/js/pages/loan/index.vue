<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
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
                <table class="table table-sm align-middle">
                    <thead><tr><th>Loan</th><th class="text-end">Principal</th><th class="text-end">Outstanding</th><th>Next due</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="loan in rows" :key="loan.id">
                            <td>{{ loan.name }}<small class="text-muted d-block">{{ loan.lender }}</small></td><td class="text-end">{{ money(loan.principal) }}</td>
                            <td class="text-end">{{ money(loan.outstanding_principal) }}</td><td>{{ loan.next_due_on?.slice(0, 10) }} <small class="text-muted">{{ loan.next_amount === null ? '' : money(loan.next_amount) }}</small></td>
                            <td class="text-capitalize">{{ loan.status }}</td>
                            <td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="show(loan)">Schedule</button></td>
                        </tr>
                        <tr v-if="! rows.length"><td colspan="6" class="text-center text-muted">No loans.</td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="open" class="col-lg-6">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h6 class="mb-0">{{ open.loan.name }}</h6>
                    <template v-if="can('/loan/pay') && open.loan.status === 'active'">
                        <input v-model="paidOn" type="date" class="form-control form-control-sm" style="width: auto" aria-label="Paid on" />
                        <button type="button" class="btn btn-dark btn-sm" @click="pay()">Pay next instalment</button>
                    </template>
                </div>
                <table class="table table-sm">
                    <thead><tr><th>#</th><th>Due</th><th class="text-end">Principal</th><th class="text-end">Interest</th><th class="text-end">Total</th><th>Paid</th></tr></thead>
                    <tbody>
                        <tr v-for="row in open.schedule" :key="row.id">
                            <td>{{ row.number }}</td><td>{{ row.due_on?.slice(0, 10) }}</td><td class="text-end">{{ money(row.principal) }}</td><td class="text-end">{{ money(row.interest) }}</td><td class="text-end">{{ money(row.total) }}</td>
                            <td>{{ row.paid_on?.slice(0, 10) }} <small class="text-muted text-capitalize">{{ row.voucher_status }}</small></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
