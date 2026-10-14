<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Cheque Management',
            subtitle: 'Received and issued cheques through deposit, clearing and bounce',
            breadcrumbs: [{ title: 'Cheque Management', href: 'NULL' }],
        },
    });

    type Cheque = {
        id: number; direction: string; cheque_no: string; bank_name: string | null; contact_name: string | null; amount: number;
        cheque_date: string | null; due_date: string | null; status: string; payment_id: number | null;
    };

    const flow: Record<string, string[]> = {
        received: ['deposited', 'bounced', 'cancelled'], issued: ['cleared', 'bounced', 'cancelled'], deposited: ['cleared', 'bounced'], cleared: [], bounced: [], cancelled: [],
    };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const rows = ref<Cheque[]>([]);
    const status = ref('all');
    const dueSoon = ref(false);
    const form = ref({ direction: 'received', cheque_no: '', bank_name: '', contact_id: '' as number | '', amount: '' as number | '', cheque_date: '', due_date: '', payment_id: '' as number | '', note: '' });

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/cheque-records', { params: { ...scope(), status: status.value, due_soon: dueSoon.value ? 1 : undefined } })))?.data?.data?.data ?? [];
    }

    async function create() {
        const body: Record<string, unknown> = { ...scope(), ...form.value };

        for (const key of ['contact_id', 'payment_id', 'cheque_date', 'due_date', 'bank_name', 'note']) {
            if (body[key] === '') {
                delete body[key];
            }
        }

        if (await run(() => window.axios.post('/api/cheque-records', body), 'Cheque saved')) {
            form.value.cheque_no = '';
            form.value.amount = '';
            await load();
        }
    }

    async function move(row: Cheque, next: string) {
        const body: Record<string, unknown> = { status: next };

        if (next === 'bounced') {
            const reason = window.prompt('Reason for the bounce', '');

            if (reason === null) {
                return;
            }

            body.reason = reason;

            if (row.payment_id !== null && ! window.confirm('This removes the payment linked to the cheque and makes the invoice due again. Continue?')) {
                return;
            }
        }

        if (await run(() => window.axios.post(`/api/cheque-records/${row.id}/status`, body), 'Updated')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Cheque Management" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <select v-model="status" class="form-select form-select-sm" style="width: auto" aria-label="Status" @change="load">
                <option value="all">All</option><option v-for="key in Object.keys(flow)" :key="key" :value="key">{{ key }}</option>
            </select>
            <label class="form-check-label small"><input v-model="dueSoon" type="checkbox" class="form-check-input me-1" @change="load" />Due within 7 days</label>
        </div>

        <div v-if="can('/chequemanagement/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="ch-dir">Direction</label>
                    <select id="ch-dir" v-model="form.direction" class="form-select form-select-sm"><option value="received">Received</option><option value="issued">Issued</option></select>
                </div>
                <div class="col-md-2"><label class="form-label small" for="ch-no">Cheque no.</label><input id="ch-no" v-model="form.cheque_no" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ch-bank">Bank</label><input id="ch-bank" v-model="form.bank_name" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ch-amount">Amount</label><input id="ch-amount" v-model.number="form.amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ch-date">Cheque date</label><input id="ch-date" v-model="form.cheque_date" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ch-due">Due date</label><input id="ch-due" v-model="form.due_date" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ch-contact">Party id</label><input id="ch-contact" v-model.number="form.contact_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ch-payment">Payment id</label><input id="ch-payment" v-model.number="form.payment_id" type="number" class="form-control form-control-sm" placeholder="Optional" /></div>
                <div class="col-md-6"><label class="form-label small" for="ch-note">Note</label><input id="ch-note" v-model="form.note" class="form-control form-control-sm" /></div>
                <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-dark btn-sm" @click="create">Add cheque</button></div>
            </div>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No cheques."  :columns="[{ key: 'c0', label: 'Cheque' }, { key: 'c1', label: 'Direction' }, { key: 'c2', label: 'Party' }, { key: 'c3', label: 'Bank' }, { key: 'c4', label: 'Amount' }, { key: 'c5', label: 'Due' }, { key: 'c6', label: 'Status' }, { key: 'c7', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.cheque_no), c1: (row.direction), c2: (row.contact_name), c3: (row.bank_name), c4: (money(row.amount)), c5: (row.due_date), c6: (row.status), c7: '' }))" export-name="cheque">
<template #cell-c7="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                            <template v-if="can('/chequemanagement/:id/edit')">
                                <button v-for="next in flow[row.status]" :key="next" type="button" class="btn btn-sm me-1 text-capitalize" :class="next === 'bounced' ? 'btn-outline-danger' : 'btn-outline-dark'" @click="move(row, next)">{{ next }}</button>
                            </template>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
