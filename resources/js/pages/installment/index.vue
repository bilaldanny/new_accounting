<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Installments & Layaway',
            subtitle: 'Pay a posted sale in instalments, or hold the goods until a layaway is paid up',
            breadcrumbs: [{ title: 'Installments & Layaway', href: 'NULL' }],
        },
    });

    type Plan = {
        id: number; transaction_id: number; kind: string; status: string; total_amount: number; down_payment: number; installments: number;
        frequency: string; paid: number; balance: number; overdue_amount: number;
    };
    type Row = { number: number; due_on: string; amount: number; paid: number; status: string };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const rows = ref<Plan[]>([]);
    const status = ref('all');
    const open = ref<{ plan: Plan; schedule: Row[] } | null>(null);
    const form = ref({ transaction_id: '' as number | '', kind: 'installment', down_payment: 0, installments: 3, frequency: 'monthly', first_due_on: new Date().toISOString().slice(0, 10) });
    const payment = ref({ amount: '' as number | '', paid_on: new Date().toISOString().slice(0, 10), method: 'cash', payment_account: '' as number | '' });

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/installment-plans', { params: { ...scope(), status: status.value } })))?.data?.data?.data ?? [];
    }

    async function show(plan: Plan) {
        const result = (await run(() => window.axios.get(`/api/installment-plans/${plan.id}`)))?.data;

        if (result) {
            const { schedule, ...rest } = result;
            open.value = { plan: rest as Plan, schedule };
        }
    }

    async function create() {
        if (await run(() => window.axios.post('/api/installment-plans', { ...form.value }), 'Plan saved')) {
            await load();
        }
    }

    async function pay() {
        if (open.value && await run(() => window.axios.post(`/api/installment-plans/${open.value?.plan.id}/pay`, { ...payment.value }), 'Payment recorded')) {
            await load();
            await show(open.value.plan);
        }
    }

    async function cancel(plan: Plan) {
        if (window.confirm('Cancel this plan? Payments already taken stay on the sale.') && await run(() => window.axios.post(`/api/installment-plans/${plan.id}/cancel`), 'Plan cancelled')) {
            open.value = null;
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Installments & Layaway" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <select v-model="status" class="form-select form-select-sm" style="width: auto" aria-label="Status" @change="load">
                <option value="all">All</option><option v-for="s in ['active', 'completed', 'defaulted', 'cancelled']" :key="s" :value="s">{{ s }}</option>
            </select>
        </div>

        <div v-if="can('/installment/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="in-sale">Sale (invoice id)</label><input id="in-sale" v-model.number="form.transaction_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="in-kind">Kind</label>
                    <select id="in-kind" v-model="form.kind" class="form-select form-select-sm"><option value="installment">Installment</option><option value="layaway">Layaway (hold goods)</option></select>
                </div>
                <div class="col-md-2"><label class="form-label small" for="in-down">Down payment</label><input id="in-down" v-model.number="form.down_payment" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="in-count">Instalments</label><input id="in-count" v-model.number="form.installments" type="number" min="1" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="in-freq">Every</label>
                    <select id="in-freq" v-model="form.frequency" class="form-select form-select-sm"><option value="monthly">Month</option><option value="weekly">Week</option></select>
                </div>
                <div class="col-md-2"><label class="form-label small" for="in-first">First due</label><input id="in-first" v-model="form.first_due_on" type="date" class="form-control form-control-sm" /></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3" @click="create">Create plan</button>
        </div>

        <div class="row g-3">
            <div :class="open ? 'col-lg-7' : 'col-12'">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Sale</th><th>Kind</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th class="text-end">Overdue</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td>#{{ row.transaction_id }}</td><td class="text-capitalize">{{ row.kind }}</td><td class="text-end">{{ money(row.total_amount) }}</td>
                            <td class="text-end">{{ money(row.paid) }}</td><td class="text-end">{{ money(row.balance) }}</td><td class="text-end">{{ money(row.overdue_amount) }}</td>
                            <td class="text-capitalize">{{ row.status }}</td>
                            <td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="show(row)">Open</button></td>
                        </tr>
                        <tr v-if="! rows.length"><td colspan="8" class="text-center text-muted">No plans.</td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="open" class="col-lg-5">
                <div class="border rounded p-3">
                    <h6>Plan for sale #{{ open.plan.transaction_id }} <span class="text-muted text-capitalize">({{ open.plan.kind }}, {{ open.plan.status }})</span></h6>
                    <table class="table table-sm">
                        <thead><tr><th>#</th><th>Due</th><th class="text-end">Amount</th><th class="text-end">Paid</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr v-for="row in open.schedule" :key="row.number"><td>{{ row.number }}</td><td>{{ row.due_on }}</td><td class="text-end">{{ money(row.amount) }}</td><td class="text-end">{{ money(row.paid) }}</td><td class="text-capitalize">{{ row.status }}</td></tr>
                        </tbody>
                    </table>
                    <div v-if="open.plan.status === 'active' && can('/installment/pay')" class="row g-2">
                        <div class="col-6"><label class="form-label small" for="ip-amount">Amount</label><input id="ip-amount" v-model.number="payment.amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                        <div class="col-6"><label class="form-label small" for="ip-date">Paid on</label><input id="ip-date" v-model="payment.paid_on" type="date" class="form-control form-control-sm" /></div>
                        <div class="col-6"><label class="form-label small" for="ip-method">Method</label>
                            <select id="ip-method" v-model="payment.method" class="form-select form-select-sm"><option v-for="m in ['cash', 'bank', 'cheque', 'card', 'online', 'other']" :key="m" :value="m">{{ m }}</option></select>
                        </div>
                        <div class="col-6"><label class="form-label small" for="ip-account">Cash / bank account id</label><input id="ip-account" v-model.number="payment.payment_account" type="number" class="form-control form-control-sm" /></div>
                        <div class="col-12">
                            <button type="button" class="btn btn-dark btn-sm me-2" @click="pay">Record payment</button>
                            <button v-if="can('/installment/:id/edit')" type="button" class="btn btn-outline-danger btn-sm" @click="cancel(open.plan)">Cancel plan</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div></div></div>
</template>
