<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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

    <div class="container-fluid py-3">
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
                <table class="table table-sm">
                    <thead><tr><th>Reference</th><th class="text-end">Amount</th><th class="text-end">Left</th></tr></thead>
                    <tbody>
                        <tr v-for="advance in result.advances" :key="advance.id"><td>{{ advance.reference }} <small class="text-muted">{{ advance.date }}</small></td><td class="text-end">{{ money(advance.amount) }}</td><td class="text-end">{{ money(advance.remaining) }}</td></tr>
                        <tr v-if="! result.advances.length"><td colspan="3" class="text-center text-muted">No advance to use.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-lg-4">
                <h6>Open documents</h6>
                <table class="table table-sm">
                    <thead><tr><th>Document</th><th>Due</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                        <tr v-for="document in result.documents" :key="document.id"><td>{{ document.invoice_no }}</td><td>{{ document.due_date }}</td><td class="text-end">{{ money(document.remaining) }}</td></tr>
                        <tr v-if="! result.documents.length"><td colspan="3" class="text-center text-muted">Nothing open.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-lg-4">
                <h6>Suggested settlement</h6>
                <table class="table table-sm">
                    <thead><tr><th>Document</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        <tr v-for="(pair, index) in result.suggestion" :key="index"><td>{{ pair.invoice_no }} <small class="text-muted">from advance #{{ pair.source_id }}</small></td><td class="text-end">{{ money(pair.amount) }}</td></tr>
                        <tr v-if="! result.suggestion.length"><td colspan="2" class="text-center text-muted">No match to make.</td></tr>
                    </tbody>
                </table>
                <p class="small text-muted">Left over, not allocated: {{ money(result.unallocated) }}</p>
                <div v-if="can('/allocation/apply') && result.suggestion.length" class="d-flex gap-2">
                    <input v-model="paidOn" type="date" class="form-control form-control-sm" style="width: auto" aria-label="Date" />
                    <button type="button" class="btn btn-dark btn-sm" @click="apply">Apply</button>
                </div>
            </div>
        </div>
        <p v-else class="text-muted">Choose a customer or supplier to see their advances and open documents.</p>
    </div>
</template>
