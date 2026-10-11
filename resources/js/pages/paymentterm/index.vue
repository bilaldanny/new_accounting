<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Payment Terms',
            subtitle: 'Net days and early-payment discounts, and taking the discount on a document',
            breadcrumbs: [{ title: 'Payment Terms', href: 'NULL' }],
        },
    });

    type Term = { id: number; name: string; net_days: number; discount_percent: number; discount_days: number; is_active: boolean; contacts: number };
    type Eligible = {
        transaction_id: number; invoice_no: string; type: string; contact: string | null; term: string; discount_percent: number;
        discount_until: string; remaining: number; discount: number;
    };

    const today = new Date().toISOString().slice(0, 10);
    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const terms = ref<Term[]>([]);
    const eligible = ref<Eligible[]>([]);
    const editing = ref<number | null>(null);
    const blank = () => ({ name: '', net_days: 30, discount_percent: 0, discount_days: 0, is_active: true });
    const form = ref(blank());
    const assignment = ref({ contact_id: '' as number | '', payment_term_id: '' as number | '' });
    const discountAccount = ref<number | ''>('');

    async function load() {
        terms.value = (await run(() => window.axios.get('/api/payment-terms', { params: scope() })))?.data?.data ?? [];
        eligible.value = (await run(() => window.axios.get('/api/early-payment-discounts', { params: { ...scope(), on: today } })))?.data?.data ?? [];
    }

    function edit(term: Term) {
        editing.value = term.id;
        form.value = { name: term.name, net_days: term.net_days, discount_percent: term.discount_percent, discount_days: term.discount_days, is_active: term.is_active };
    }

    async function save() {
        const request = editing.value === null
            ? () => window.axios.post('/api/payment-terms', { ...scope(), ...form.value })
            : () => window.axios.put(`/api/payment-terms/${editing.value}`, { ...form.value });

        if (await run(request, 'Term saved')) {
            editing.value = null;
            form.value = blank();
            await load();
        }
    }

    async function remove(term: Term) {
        if (window.confirm(`Delete ${term.name}?`) && await run(() => window.axios.delete(`/api/payment-terms/${term.id}`), 'Deleted')) {
            await load();
        }
    }

    async function assign() {
        const body = { contact_id: assignment.value.contact_id, payment_term_id: assignment.value.payment_term_id === '' ? null : assignment.value.payment_term_id };

        if (await run(() => window.axios.post('/api/payment-terms/assign', body), 'Term assigned')) {
            await load();
        }
    }

    async function takeDiscount(row: Eligible) {
        if (discountAccount.value === '') {
            window.alert('Enter the account the discount is booked to first.');

            return;
        }

        if (await run(() => window.axios.post('/api/early-payment-discounts', { transaction_id: row.transaction_id, discount_account_id: discountAccount.value, applied_on: today }), 'Discount taken')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Payment Terms" />

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
        </div>

        <div class="row g-3 mb-4">
            <div v-if="can('/paymentterm/add') || (editing !== null && can('/paymentterm/:id/edit'))" class="col-lg-7">
                <div class="border rounded p-3 h-100">
                    <div class="row g-2">
                        <div class="col-md-4"><label class="form-label small" for="pt-name">Name</label><input id="pt-name" v-model="form.name" class="form-control form-control-sm" placeholder="2/10 net 30" /></div>
                        <div class="col-md-2"><label class="form-label small" for="pt-net">Net days</label><input id="pt-net" v-model.number="form.net_days" type="number" min="0" class="form-control form-control-sm" /></div>
                        <div class="col-md-2"><label class="form-label small" for="pt-disc">Discount %</label><input id="pt-disc" v-model.number="form.discount_percent" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                        <div class="col-md-2"><label class="form-label small" for="pt-days">Within days</label><input id="pt-days" v-model.number="form.discount_days" type="number" min="0" class="form-control form-control-sm" /></div>
                        <div class="col-md-2 d-flex align-items-end"><label class="form-check-label small"><input v-model="form.is_active" type="checkbox" class="form-check-input me-1" />Active</label></div>
                    </div>
                    <button type="button" class="btn btn-dark btn-sm mt-3 me-2" @click="save">{{ editing === null ? 'Add term' : 'Save term' }}</button>
                    <button v-if="editing !== null" type="button" class="btn btn-outline-dark btn-sm mt-3" @click="editing = null; form = blank()">Cancel</button>
                </div>
            </div>
            <div v-if="can('/paymentterm/assign')" class="col-lg-5">
                <div class="border rounded p-3 h-100">
                    <h6>Assign a term to a customer or supplier</h6>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label small" for="pa-contact">Contact id</label><input id="pa-contact" v-model.number="assignment.contact_id" type="number" class="form-control form-control-sm" /></div>
                        <div class="col-6"><label class="form-label small" for="pa-term">Term</label>
                            <select id="pa-term" v-model="assignment.payment_term_id" class="form-select form-select-sm"><option value="">None</option><option v-for="term in terms" :key="term.id" :value="term.id">{{ term.name }}</option></select>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-dark btn-sm mt-3" @click="assign">Assign</button>
                </div>
            </div>
        </div>

        <table class="table table-sm align-middle mb-4">
            <thead><tr><th>Term</th><th class="text-end">Net days</th><th class="text-end">Discount</th><th class="text-end">Within</th><th class="text-end">Contacts</th><th></th></tr></thead>
            <tbody>
                <tr v-for="term in terms" :key="term.id">
                    <td>{{ term.name }}<span v-if="! term.is_active" class="badge text-bg-secondary ms-1">off</span></td><td class="text-end">{{ term.net_days }}</td>
                    <td class="text-end">{{ term.discount_percent }}%</td><td class="text-end">{{ term.discount_days }} d</td><td class="text-end">{{ term.contacts }}</td>
                    <td class="text-end text-nowrap">
                        <button v-if="can('/paymentterm/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(term)">Edit</button>
                        <button v-if="can('/paymentterm/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(term)">Delete</button>
                    </td>
                </tr>
                <tr v-if="! terms.length"><td colspan="6" class="text-center text-muted">No payment terms.</td></tr>
            </tbody>
        </table>

        <h6>Discounts available today</h6>
        <div v-if="can('/paymentterm/discount')" class="d-flex align-items-center gap-2 mb-2">
            <label class="small" for="pd-account">Book the discount to account id</label>
            <input id="pd-account" v-model.number="discountAccount" type="number" class="form-control form-control-sm" style="width: 8rem" />
        </div>
        <table class="table table-sm align-middle">
            <thead><tr><th>Document</th><th>Party</th><th>Term</th><th>Until</th><th class="text-end">Balance</th><th class="text-end">Discount</th><th></th></tr></thead>
            <tbody>
                <tr v-for="row in eligible" :key="row.transaction_id">
                    <td>{{ row.invoice_no }} <small class="text-muted text-capitalize">{{ row.type }}</small></td><td>{{ row.contact }}</td><td>{{ row.term }}</td><td>{{ row.discount_until }}</td>
                    <td class="text-end">{{ money(row.remaining) }}</td><td class="text-end">{{ money(row.discount) }}</td>
                    <td class="text-end"><button v-if="can('/paymentterm/discount')" type="button" class="btn btn-dark btn-sm" @click="takeDiscount(row)">Take discount</button></td>
                </tr>
                <tr v-if="! eligible.length"><td colspan="7" class="text-center text-muted">No document is inside a discount window.</td></tr>
            </tbody>
        </table>
    </div>
</template>
