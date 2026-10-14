<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Supplier Payments',
            subtitle: 'One payment across several bills, with a supplier advance for the rest',
            breadcrumbs: [{ title: 'Supplier Payments', href: 'NULL' }],
        },
    });

    type Batch = { id: number; reference: string; contact_id: number; payment_account: number; paid_on: string; amount: number; advance_amount: number; advance_remaining: number; status: string; allocations_count: number };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const rows = ref<Batch[]>([]);
    const form = ref({ contact_id: '' as number | '', paid_on: new Date().toISOString().slice(0, 10), payment_account: '' as number | '', method: 'cash', amount: 0, keep_advance: false, note: '' });
    const allocations = ref<{ transaction_id: number | ''; amount: number }[]>([{ transaction_id: '', amount: 0 }]);

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/supplier-payments', { params: scope() })))?.data?.data?.data ?? [];
    }

    async function save() {
        const body = { ...form.value, allocations: allocations.value.filter((row) => row.transaction_id !== '' && row.amount > 0) };

        if (await run(() => window.axios.post('/api/supplier-payments', body), 'Payment saved')) {
            allocations.value = [{ transaction_id: '', amount: 0 }];
            await load();
        }
    }

    async function useAdvance(batch: Batch) {
        const billId = window.prompt('Bill (purchase) id to settle from the advance', '');
        const amount = window.prompt(`Amount (up to ${batch.advance_remaining})`, String(batch.advance_remaining));

        if (! billId || ! amount) {
            return;
        }

        const body = { paid_on: new Date().toISOString().slice(0, 10), allocations: [{ transaction_id: Number(billId), amount: Number(amount) }] };

        if (await run(() => window.axios.post(`/api/supplier-payments/${batch.id}/apply-advance`, body), 'Bill settled from the advance')) {
            await load();
        }
    }

    async function refundAdvance(batch: Batch) {
        const amount = window.prompt(`Amount the supplier pays back (up to ${batch.advance_remaining})`, String(batch.advance_remaining));

        if (! amount) {
            return;
        }

        const body = { refunded_on: new Date().toISOString().slice(0, 10), amount: Number(amount), payment_account: batch.payment_account };

        if (await run(() => window.axios.post(`/api/supplier-payments/${batch.id}/refund-advance`, body), 'Refund recorded')) {
            await load();
        }
    }

    async function reverse(batch: Batch) {
        if (window.confirm(`Reverse ${batch.reference}? Its payments and vouchers are removed.`) && await run(() => window.axios.post(`/api/supplier-payments/${batch.id}/reverse`), 'Reversed')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Supplier Payments" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div v-if="can('/supplierpayment/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="sp-supplier">Supplier id</label><input id="sp-supplier" v-model.number="form.contact_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="sp-date">Paid on</label><input id="sp-date" v-model="form.paid_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="sp-account">Cash / bank account id</label><input id="sp-account" v-model.number="form.payment_account" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2">
                    <label class="form-label small" for="sp-method">Method</label>
                    <select id="sp-method" v-model="form.method" class="form-select form-select-sm"><option value="cash">Cash</option><option value="bank">Bank</option><option value="cheque">Cheque</option><option value="card">Card</option><option value="online">Online</option><option value="other">Other</option></select>
                </div>
                <div class="col-md-2"><label class="form-label small" for="sp-amount">Amount paid</label><input id="sp-amount" v-model.number="form.amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2 d-flex align-items-end"><label class="small"><input v-model="form.keep_advance" type="checkbox" class="me-1" />Keep the rest as advance</label></div>
            </div>
            <ClientTable  :columns="[{ key: 'c0', label: 'Bill (purchase id)' }, { key: 'c1', label: 'Amount' }, { key: 'c2', label: '' }]" :rows="(allocations).map((row, index) => ({ __s: { row, index }, c0: '', c1: '', c2: '' }))" export-name="bill-purchase-id">
<template #cell-c0="{ row: __m }"><template v-for="{ row, index } in [__m.__s]" :key="0"><input v-model.number="row.transaction_id" type="number" class="form-control form-control-sm" aria-label="Bill id" /></template></template>
<template #cell-c1="{ row: __m }"><template v-for="{ row, index } in [__m.__s]" :key="0"><input v-model.number="row.amount" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Amount" /></template></template>
<template #cell-c2="{ row: __m }"><template v-for="{ row, index } in [__m.__s]" :key="0"><button type="button" class="btn btn-link btn-sm text-danger" @click="allocations.splice(index, 1)">Remove</button></template></template>
</ClientTable>
            <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="allocations.push({ transaction_id: '', amount: 0 })">Add bill</button>
            <button type="button" class="btn btn-dark btn-sm" @click="save">Pay</button>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No supplier payments yet."  :columns="[{ key: 'c0', label: 'Reference' }, { key: 'c1', label: 'Supplier' }, { key: 'c2', label: 'Paid on' }, { key: 'c3', label: 'Amount' }, { key: 'c4', label: 'Bills' }, { key: 'c5', label: 'Advance left' }, { key: 'c6', label: 'Status' }, { key: 'c7', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.reference), c1: `#${row.contact_id}`, c2: (row.paid_on), c3: (money(row.amount)), c4: (row.allocations_count), c5: (money(row.advance_remaining)), c6: (row.status), c7: '' }))" export-name="reference">
<template #cell-c7="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                            <button v-if="row.status === 'completed' && row.advance_remaining > 0 && can('/supplierpayment/add')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="useAdvance(row)">Use advance</button>
                            <button v-if="row.status === 'completed' && row.advance_remaining > 0 && can('/supplierpayment/refund')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="refundAdvance(row)">Refund advance</button>
                            <button v-if="row.status === 'completed' && can('/supplierpayment/reverse')" type="button" class="btn btn-outline-danger btn-sm" @click="reverse(row)">Reverse</button>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
