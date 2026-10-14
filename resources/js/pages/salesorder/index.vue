<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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

    <div class="container-fluid py-3">
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
            <table class="table table-sm align-middle mt-3">
                <thead><tr><th>Product id</th><th style="width: 9rem">Quantity</th><th style="width: 10rem">Unit price</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="(line, index) in lines" :key="index">
                        <td><input v-model.number="line.product_id" type="number" class="form-control form-control-sm" aria-label="Product id" /></td>
                        <td><input v-model.number="line.quantity" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Quantity" /></td>
                        <td><input v-model.number="line.unit_price" type="number" min="0" step="any" class="form-control form-control-sm" placeholder="List price" aria-label="Unit price" /></td>
                        <td class="text-end"><button type="button" class="btn btn-link btn-sm text-danger" @click="lines.splice(index, 1)">Remove</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="lines.push({ product_id: '', quantity: 1, unit_price: '' })">Add line</button>
            <button type="button" class="btn btn-dark btn-sm" @click="create">Save order</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.order_no }}</td><td>{{ row.contact_name }}</td><td>{{ row.order_date }}</td><td class="text-end">{{ money(row.total) }}</td>
                        <td class="text-capitalize">{{ row.status.replace('_', ' ') }}</td>
                        <td class="text-end text-nowrap">
                            <button v-if="row.status === 'draft' && can('/salesorder/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="act(row, 'submit')">Submit</button>
                            <button v-if="row.status === 'pending_approval' && can('/salesorder/approve')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="act(row, 'approve')">Approve</button>
                            <button v-if="['confirmed', 'partially_invoiced'].includes(row.status) && can('/salesorder/invoice')" type="button" class="btn btn-dark btn-sm me-1" @click="act(row, 'invoice')">Invoice</button>
                            <button v-if="! ['invoiced', 'cancelled', 'partially_invoiced'].includes(row.status) && can('/salesorder/:id/edit')" type="button" class="btn btn-outline-danger btn-sm" @click="act(row, 'cancel')">Cancel</button>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="6" class="text-center text-muted">No sales orders.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
