<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
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

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-3 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
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
            <ClientTable empty-text="No shipments."  :columns="[{ key: 'c0', label: 'Shipment' }, { key: 'c1', label: 'Purchase' }, { key: 'c2', label: 'Carrier / tracking' }, { key: 'c3', label: 'Container' }, { key: 'c4', label: 'Shipped' }, { key: 'c5', label: 'ETA' }, { key: 'c6', label: 'Arrived' }, { key: 'c7', label: 'Status' }, { key: 'c8', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.shipment_no), c1: `#${row.transaction_id}`, c2: '', c3: (row.container_no), c4: (row.shipped_on), c5: '', c6: (row.arrived_on), c7: (row.status.replace('_', ' ')), c8: '' }))" export-name="shipment">
<template #cell-c2="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ row.carrier }} <span class="text-muted small">{{ row.tracking_no }}</span></template></template>
<template #cell-c5="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ row.eta }}<span v-if="row.is_late" class="badge bg-warning text-dark ms-1">Late</span></template></template>
<template #cell-c8="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                            <select v-if="can('/inboundshipment/:id/edit')" class="form-select form-select-sm" style="width: auto; display: inline-block" :value="row.status" aria-label="Status" @change="setStatus(row, ($event.target as HTMLSelectElement).value)">
                                <option v-for="status in statuses" :key="status" :value="status">{{ status.replace('_', ' ') }}</option>
                            </select>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
