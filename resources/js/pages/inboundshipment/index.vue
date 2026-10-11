<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Inbound Shipments',
            subtitle: 'Where each supplier order is on its way',
            breadcrumbs: [{ title: 'Inbound Shipments', href: 'NULL' }],
        },
    });

    type Shipment = {
        id: number; shipment_no: string; transaction_id: number; carrier: string | null; tracking_no: string | null; container_no: string | null; status: string;
        shipped_on: string | null; eta: string | null; arrived_on: string | null; is_late?: boolean;
    };

    const statuses = ['ordered', 'shipped', 'in_transit', 'customs', 'arrived', 'received', 'cancelled'];

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Shipment[]>([]);
    const lateOnly = ref(false);
    const form = ref({ transaction_id: '' as number | '', carrier: '', tracking_no: '', container_no: '', shipped_on: '', eta: '' });

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/inbound-shipments', { params: { ...scope(), late: lateOnly.value ? 1 : 0 } })))?.data?.data?.data ?? [];
    }

    async function create() {
        const clean = (value: string) => (value === '' ? null : value);
        const body = { ...form.value, shipped_on: clean(form.value.shipped_on), eta: clean(form.value.eta) };

        if (await run(() => window.axios.post('/api/inbound-shipments', body), 'Shipment added')) {
            await load();
        }
    }

    async function setStatus(row: Shipment, status: string) {
        const location = window.prompt('Where is it now? (optional)', '') ?? '';

        if (await run(() => window.axios.put(`/api/inbound-shipments/${row.id}`, { status, location }), 'Updated')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Inbound Shipments" />

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-3 mb-3">
            <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
            <label class="small align-self-center"><input v-model="lateOnly" type="checkbox" class="me-1" @change="load" />Late only (ETA passed, not arrived)</label>
        </div>

        <div v-if="can('/inboundshipment/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="sh-po">Purchase id</label><input id="sh-po" v-model.number="form.transaction_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="sh-carrier">Carrier</label><input id="sh-carrier" v-model="form.carrier" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="sh-track">Tracking no.</label><input id="sh-track" v-model="form.tracking_no" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="sh-container">Container</label><input id="sh-container" v-model="form.container_no" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="sh-shipped">Shipped</label><input id="sh-shipped" v-model="form.shipped_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="sh-eta">ETA</label><input id="sh-eta" v-model="form.eta" type="date" class="form-control form-control-sm" /></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3" @click="create">Add shipment</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Shipment</th><th>Purchase</th><th>Carrier / tracking</th><th>Container</th><th>Shipped</th><th>ETA</th><th>Arrived</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" :class="{ 'table-warning': row.is_late }">
                        <td>{{ row.shipment_no }}</td><td>#{{ row.transaction_id }}</td><td>{{ row.carrier }} <span class="text-muted small">{{ row.tracking_no }}</span></td><td>{{ row.container_no }}</td>
                        <td>{{ row.shipped_on }}</td><td>{{ row.eta }}<span v-if="row.is_late" class="badge bg-warning text-dark ms-1">Late</span></td><td>{{ row.arrived_on }}</td><td class="text-capitalize">{{ row.status.replace('_', ' ') }}</td>
                        <td class="text-end">
                            <select v-if="can('/inboundshipment/:id/edit')" class="form-select form-select-sm" style="width: auto; display: inline-block" :value="row.status" aria-label="Status" @change="setStatus(row, ($event.target as HTMLSelectElement).value)">
                                <option v-for="status in statuses" :key="status" :value="status">{{ status.replace('_', ' ') }}</option>
                            </select>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="9" class="text-center text-muted">No shipments.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
