<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Advance Allocation',
            subtitle: 'Match a customer\'s or supplier\'s advances to their open invoices or bills',
            breadcrumbs: [{ title: 'Advance Allocation', href: 'NULL' }],
        },
    });

    type Advance = { id: number; reference: string; date: string | null; amount: number; remaining: number };
    type Document = { id: number; invoice_no: string; date: string; due_date: string; total: number; remaining: number };
    type Pair = { source_id: number; transaction_id: number; invoice_no: string; amount: number };
    type Result = { advances: Advance[]; documents: Document[]; suggestion: Pair[]; unallocated: number };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const form = ref({ contact_id: '' as number | '', role: 'customer', strategy: 'fifo' });
    const paidOn = ref(new Date().toISOString().slice(0, 10));
    const result = ref<Result | null>(null);

    async function suggest() {
        result.value = (await run(() => window.axios.get('/api/allocation/suggest', { params: { ...scope(), ...form.value } })))?.data?.data ?? null;
    }

    async function apply() {
        if (! result.value || ! result.value.suggestion.length) {
            return;
        }

        const body = { contact_id: form.value.contact_id, role: form.value.role, paid_on: paidOn.value, allocations: result.value.suggestion.map(({ source_id, transaction_id, amount }) => ({ source_id, transaction_id, amount })) };

        if (await run(() => window.axios.post('/api/allocation/apply', body), 'Allocated')) {
            await suggest();
        }
    }
</script>

<template>
    <Head title="Advance Allocation" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-end">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" />
            <select v-model="form.role" class="form-select form-select-sm" style="width: auto" aria-label="Customer or supplier"><option value="customer">Customer</option><option value="supplier">Supplier</option></select>
            <input v-model.number="form.contact_id" type="number" class="form-control form-control-sm" style="width: 9rem" placeholder="Contact id" aria-label="Contact id" />
            <select v-model="form.strategy" class="form-select form-select-sm" style="width: auto" aria-label="Order">
                <option value="fifo">Oldest document first</option><option value="due_date">Earliest due first</option><option value="smallest">Smallest balance first</option>
            </select>
            <button type="button" class="btn btn-outline-dark btn-sm" @click="suggest">Suggest</button>
        </div>

        <div v-if="result" class="row g-3">
            <div class="col-lg-4">
                <h6>Advances</h6>
                <ClientTable empty-text="No advance to use."  :columns="[{ key: 'c0', label: 'Reference' }, { key: 'c1', label: 'Amount' }, { key: 'c2', label: 'Left' }]" :rows="(result.advances).map((advance) => ({ __s: { advance }, c0: '', c1: (money(advance.amount)), c2: (money(advance.remaining)) }))" export-name="reference">
<template #cell-c0="{ row: __m }"><template v-for="{ advance } in [__m.__s]" :key="0">{{ advance.reference }} <small class="text-muted">{{ advance.date }}</small></template></template>
</ClientTable>
            </div>
            <div class="col-lg-4">
                <h6>Open documents</h6>
                <ClientTable empty-text="Nothing open."  :columns="[{ key: 'c0', label: 'Document' }, { key: 'c1', label: 'Due' }, { key: 'c2', label: 'Balance' }]" :rows="(result.documents).map((document) => ({ __s: { document }, c0: (document.invoice_no), c1: (document.due_date), c2: (money(document.remaining)) }))" export-name="document">
</ClientTable>
            </div>
            <div class="col-lg-4">
                <h6>Suggested settlement</h6>
                <ClientTable empty-text="No match to make."  :columns="[{ key: 'c0', label: 'Document' }, { key: 'c1', label: 'Amount' }]" :rows="(result.suggestion).map((pair, index) => ({ __s: { pair, index }, c0: '', c1: (money(pair.amount)) }))" export-name="document">
<template #cell-c0="{ row: __m }"><template v-for="{ pair, index } in [__m.__s]" :key="0">{{ pair.invoice_no }} <small class="text-muted">from advance #{{ pair.source_id }}</small></template></template>
</ClientTable>
                <p class="small text-muted">Left over, not allocated: {{ money(result.unallocated) }}</p>
                <div v-if="can('/allocation/apply') && result.suggestion.length" class="d-flex gap-2">
                    <input v-model="paidOn" type="date" class="form-control form-control-sm" style="width: auto" aria-label="Date" />
                    <button type="button" class="btn btn-dark btn-sm" @click="apply">Apply</button>
                </div>
            </div>
        </div>
        <p v-else class="text-muted">Choose a customer or supplier to see their advances and open documents.</p>
    </div></div></div>
</template>
