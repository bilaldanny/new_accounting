<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Stock Reservations',
            subtitle: 'Hold stock for a customer or an order, and release it',
            breadcrumbs: [{ title: 'Stock Reservations', href: 'NULL' }],
        },
    });

    type Row = {
        id: number; product_id: number; quantity: number; reference: string | null; status: string; expires_on: string | null; note: string | null;
        product?: { name: string; sku: string | null } | null; contact?: { business_name: string | null; first_name: string | null } | null;
    };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Row[]>([]);
    const status = ref('active');
    const form = ref({ product_id: '' as number | '', variation_id: '' as number | '', contact_id: '' as number | '', branch_id: '' as number | '', quantity: 1, reference: '', expires_on: '', note: '' });
    const availability = ref<{ on_hand: number; reserved: number; available: number; enforced: boolean } | null>(null);

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/stock-reservations', { params: { ...scope(), status: status.value } })))?.data?.data?.data ?? [];
    }

    async function check() {
        availability.value = (await run(() => window.axios.get('/api/stock-reservations/availability', {
            params: { ...scope(), product_id: form.value.product_id, variation_id: form.value.variation_id || undefined, branch_id: form.value.branch_id || undefined },
        })))?.data ?? null;
    }

    async function create() {
        const clean = (value: number | string) => (value === '' ? null : value);
        const body = {
            ...scope(), ...form.value, variation_id: clean(form.value.variation_id), contact_id: clean(form.value.contact_id),
            branch_id: clean(form.value.branch_id), expires_on: clean(form.value.expires_on),
        };

        if (await run(() => window.axios.post('/api/stock-reservations', body), 'Stock reserved')) {
            await load();
            await check();
        }
    }

    async function close(row: Row, outcome: 'released' | 'fulfilled') {
        if (await run(() => window.axios.post(`/api/stock-reservations/${row.id}/close`, { outcome }), outcome === 'released' ? 'Released' : 'Marked fulfilled')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Stock Reservations" />

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <select v-model="status" class="form-select form-select-sm" style="width: auto" aria-label="Status" @change="load">
                <option value="active">Active</option><option value="released">Released</option><option value="fulfilled">Fulfilled</option><option value="all">All</option>
            </select>
        </div>

        <div v-if="can('/stockreservation/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="res-product">Product id</label><input id="res-product" v-model.number="form.product_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="res-variation">Variation id</label><input id="res-variation" v-model.number="form.variation_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="res-branch">Branch id</label><input id="res-branch" v-model.number="form.branch_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="res-contact">Customer id</label><input id="res-contact" v-model.number="form.contact_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="res-qty">Quantity (base units)</label><input id="res-qty" v-model.number="form.quantity" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="res-expires">Holds until</label><input id="res-expires" v-model="form.expires_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-4"><label class="form-label small" for="res-ref">Reference (order, customer request)</label><input id="res-ref" v-model="form.reference" class="form-control form-control-sm" /></div>
                <div class="col-md-8"><label class="form-label small" for="res-note">Note</label><input id="res-note" v-model="form.note" class="form-control form-control-sm" /></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3 me-2" @click="create">Reserve</button>
            <button type="button" class="btn btn-outline-dark btn-sm mt-3" @click="check">Check availability</button>
            <span v-if="availability" class="ms-3 small">On hand {{ availability.on_hand }}, held {{ availability.reserved }}, available {{ availability.available }}<span v-if="! availability.enforced" class="text-muted"> (holds do not block sales: reservation mode is off)</span></span>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Product</th><th>Customer</th><th class="text-end">Quantity</th><th>Reference</th><th>Until</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.product?.name }} <span class="text-muted small">{{ row.product?.sku }}</span></td>
                        <td>{{ row.contact?.business_name || row.contact?.first_name }}</td>
                        <td class="text-end">{{ row.quantity }}</td><td>{{ row.reference }}</td><td>{{ row.expires_on }}</td><td class="text-capitalize">{{ row.status }}</td>
                        <td class="text-end text-nowrap">
                            <template v-if="row.status === 'active' && can('/stockreservation/:id/edit')">
                                <button type="button" class="btn btn-dark btn-sm me-1" @click="close(row, 'fulfilled')">Fulfilled</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="close(row, 'released')">Release</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="7" class="text-center text-muted">No reservations.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
