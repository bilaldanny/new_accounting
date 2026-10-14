<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Sales Orders',
            subtitle: 'Order first, approve, then invoice in one or more steps',
            breadcrumbs: [{ title: 'Sales Orders', href: 'NULL' }],
        },
    });

    type Order = { id: number; order_no: string; contact_name: string | null; order_date: string; total: number; status: string };

    const statuses = ['draft', 'pending_approval', 'confirmed', 'partially_invoiced', 'invoiced', 'cancelled'];
    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const rows = ref<Order[]>([]);
    const status = ref('all');
    const form = ref({ contact_id: '' as number | '', order_date: new Date().toISOString().slice(0, 10), expected_date: '', note: '', as_draft: false });
    const lines = ref([{ product_id: '' as number | '', quantity: 1, unit_price: '' as number | '' }]);

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/sales-orders', { params: { ...scope(), status: status.value } })))?.data?.data?.data ?? [];
    }

    async function create() {
        const body = {
            ...scope(), ...form.value, expected_date: form.value.expected_date || null,
            lines: lines.value.filter((line) => line.product_id !== '').map((line) => ({ ...line, unit_price: line.unit_price === '' ? null : line.unit_price })),
        };

        if (await run(() => window.axios.post('/api/sales-orders', body), 'Sales order saved')) {
            lines.value = [{ product_id: '', quantity: 1, unit_price: '' }];
            await load();
        }
    }

    async function act(row: Order, action: 'submit' | 'approve' | 'cancel' | 'invoice') {
        if (action === 'cancel' && ! window.confirm(`Cancel ${row.order_no}?`)) {
            return;
        }

        const body = action === 'invoice' ? { status: window.confirm('Post the invoice as final? Cancel makes it a draft.') ? 'final' : 'draft' } : {};

        if (await run(() => window.axios.post(`/api/sales-orders/${row.id}/${action}`, body), 'Done')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Sales Orders" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <select v-model="status" class="form-select form-select-sm" style="width: auto" aria-label="Status" @change="load">
                <option value="all">All</option><option v-for="key in statuses" :key="key" :value="key">{{ key.replace('_', ' ') }}</option>
            </select>
        </div>

        <div v-if="can('/salesorder/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="so-customer">Customer id</label><input id="so-customer" v-model.number="form.contact_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="so-date">Order date</label><input id="so-date" v-model="form.order_date" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="so-expected">Expected</label><input id="so-expected" v-model="form.expected_date" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-4"><label class="form-label small" for="so-note">Note</label><input id="so-note" v-model="form.note" class="form-control form-control-sm" /></div>
                <div class="col-md-2 d-flex align-items-end"><label class="form-check-label small"><input v-model="form.as_draft" type="checkbox" class="form-check-input me-1" />Save as draft</label></div>
            </div>
            <ClientTable  :columns="[{ key: 'c0', label: 'Product id' }, { key: 'c1', label: 'Quantity' }, { key: 'c2', label: 'Unit price' }, { key: 'c3', label: '' }]" :rows="(lines).map((line, index) => ({ __s: { line, index }, c0: '', c1: '', c2: '', c3: '' }))" export-name="product-id">
<template #cell-c0="{ row: __m }"><template v-for="{ line, index } in [__m.__s]" :key="0"><input v-model.number="line.product_id" type="number" class="form-control form-control-sm" aria-label="Product id" /></template></template>
<template #cell-c1="{ row: __m }"><template v-for="{ line, index } in [__m.__s]" :key="0"><input v-model.number="line.quantity" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Quantity" /></template></template>
<template #cell-c2="{ row: __m }"><template v-for="{ line, index } in [__m.__s]" :key="0"><input v-model.number="line.unit_price" type="number" min="0" step="any" class="form-control form-control-sm" placeholder="List price" aria-label="Unit price" /></template></template>
<template #cell-c3="{ row: __m }"><template v-for="{ line, index } in [__m.__s]" :key="0"><button type="button" class="btn btn-link btn-sm text-danger" @click="lines.splice(index, 1)">Remove</button></template></template>
</ClientTable>
            <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="lines.push({ product_id: '', quantity: 1, unit_price: '' })">Add line</button>
            <button type="button" class="btn btn-dark btn-sm" @click="create">Save order</button>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No sales orders."  :columns="[{ key: 'c0', label: 'Order' }, { key: 'c1', label: 'Customer' }, { key: 'c2', label: 'Date' }, { key: 'c3', label: 'Total' }, { key: 'c4', label: 'Status' }, { key: 'c5', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.order_no), c1: (row.contact_name), c2: (row.order_date), c3: (money(row.total)), c4: (row.status.replace('_', ' ')), c5: '' }))" export-name="order">
<template #cell-c5="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                            <button v-if="row.status === 'draft' && can('/salesorder/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="act(row, 'submit')">Submit</button>
                            <button v-if="row.status === 'pending_approval' && can('/salesorder/approve')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="act(row, 'approve')">Approve</button>
                            <button v-if="['confirmed', 'partially_invoiced'].includes(row.status) && can('/salesorder/invoice')" type="button" class="btn btn-dark btn-sm me-1" @click="act(row, 'invoice')">Invoice</button>
                            <button v-if="! ['invoiced', 'cancelled', 'partially_invoiced'].includes(row.status) && can('/salesorder/:id/edit')" type="button" class="btn btn-outline-danger btn-sm" @click="act(row, 'cancel')">Cancel</button>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
