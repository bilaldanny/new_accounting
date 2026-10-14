<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Riders',
            subtitle: 'Delivery riders for the dispatch board',
            breadcrumbs: [{ title: 'Riders', href: 'NULL' }],
        },
    });

    type Rider = { id: number; name: string; phone: string | null; vehicle_no: string | null; is_active: boolean };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Rider[]>([]);
    const editing = ref<number | null>(null);
    const blank = () => ({ name: '', phone: '', vehicle_no: '', is_active: true });
    const form = ref(blank());

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/riders', { params: scope() })))?.data?.data ?? [];
    }

    function edit(row: Rider) {
        editing.value = row.id;
        form.value = { name: row.name, phone: row.phone ?? '', vehicle_no: row.vehicle_no ?? '', is_active: row.is_active };
    }

    async function save() {
        const body = { ...scope(), ...form.value };
        const request = editing.value === null ? () => window.axios.post('/api/riders', body) : () => window.axios.put(`/api/riders/${editing.value}`, body);

        if (await run(request, 'Saved')) {
            editing.value = null;
            form.value = blank();
            await load();
        }
    }

    async function remove(row: Rider) {
        if (window.confirm(`Delete rider ${row.name}?`) && await run(() => window.axios.delete(`/api/riders/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Riders" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div v-if="can('/rider/add') || (editing !== null && can('/rider/:id/edit'))" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label small" for="rd-name">Name</label><input id="rd-name" v-model="form.name" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="rd-phone">Phone</label><input id="rd-phone" v-model="form.phone" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="rd-vehicle">Vehicle no.</label><input id="rd-vehicle" v-model="form.vehicle_no" class="form-control form-control-sm" /></div>
                <div class="col-md-2 d-flex align-items-end"><label class="form-check-label small"><input v-model="form.is_active" type="checkbox" class="form-check-input me-1" />Active</label></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3 me-2" @click="save">{{ editing === null ? 'Add rider' : 'Save rider' }}</button>
            <button v-if="editing !== null" type="button" class="btn btn-outline-dark btn-sm mt-3" @click="editing = null; form = blank()">Cancel</button>
        </div>

        <ClientTable empty-text="No riders."  :columns="[{ key: 'c0', label: 'Name' }, { key: 'c1', label: 'Phone' }, { key: 'c2', label: 'Vehicle' }, { key: 'c3', label: 'Active' }, { key: 'c4', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.name), c1: (row.phone), c2: (row.vehicle_no), c3: (row.is_active ? 'Yes' : 'No'), c4: '' }))" export-name="name">
<template #cell-c4="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                        <button v-if="can('/rider/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(row)">Edit</button>
                        <button v-if="can('/rider/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                    </template></template>
</ClientTable>
    </div></div></div>
</template>
