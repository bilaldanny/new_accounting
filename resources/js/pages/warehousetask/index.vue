<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Pick, Pack & Put-away',
            subtitle: 'Work lists made from a sale or a receipt',
            breadcrumbs: [{ title: 'Pick, Pack & Put-away', href: 'NULL' }],
        },
    });

    type Line = { id: number; product_id: number; quantity: number; quantity_done: number; location_id: number | null; note: string | null; product?: { name: string } | null };
    type Task = { id: number; task_no: string; type: string; status: string; transaction_id: number | null; lines_count?: number; note: string | null; lines?: Line[] };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Task[]>([]);
    const type = ref('all');
    const status = ref('all');
    const open = ref<Task | null>(null);
    const form = ref({ type: 'pick', transaction_id: '' as number | '', note: '' });

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/warehouse-tasks', { params: { ...scope(), type: type.value, status: status.value } })))?.data?.data?.data ?? [];
    }

    async function create() {
        if (await run(() => window.axios.post('/api/warehouse-tasks', { ...form.value }), 'List created')) {
            await load();
        }
    }

    async function show(row: Task) {
        open.value = (await run(() => window.axios.get(`/api/warehouse-tasks/${row.id}`)))?.data ?? null;
    }

    async function save() {
        if (open.value === null) {
            return;
        }

        const lines = (open.value.lines ?? []).map((line) => ({ id: line.id, quantity_done: line.quantity_done, location_id: line.location_id, note: line.note }));
        const done = await run(() => window.axios.post(`/api/warehouse-tasks/${open.value!.id}/progress`, { lines }), 'Saved');

        if (done) {
            open.value = done.data.data;
            await load();
        }
    }

    async function complete(allowShort = false) {
        if (open.value === null) {
            return;
        }

        if (await run(() => window.axios.post(`/api/warehouse-tasks/${open.value!.id}/complete`, { allow_short: allowShort }), 'Completed')) {
            open.value = null;
            await load();
        }
    }

    async function cancel() {
        if (open.value !== null && window.confirm('Cancel this list?') && await run(() => window.axios.post(`/api/warehouse-tasks/${open.value!.id}/cancel`), 'Cancelled')) {
            open.value = null;
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Pick, Pack & Put-away" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <select v-model="type" class="form-select form-select-sm" style="width: auto" aria-label="Type" @change="load">
                <option value="all">All types</option><option value="pick">Pick</option><option value="pack">Pack</option><option value="putaway">Put-away</option>
            </select>
            <select v-model="status" class="form-select form-select-sm" style="width: auto" aria-label="Status" @change="load">
                <option value="all">All</option><option value="open">Open</option><option value="in_progress">In progress</option><option value="done">Done</option><option value="cancelled">Cancelled</option>
            </select>
        </div>

        <div v-if="can('/warehousetask/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2">
                    <label class="form-label small" for="task-type">List</label>
                    <select id="task-type" v-model="form.type" class="form-select form-select-sm"><option value="pick">Pick (from a sale)</option><option value="pack">Pack (from a sale)</option><option value="putaway">Put-away (from a purchase / receipt)</option></select>
                </div>
                <div class="col-md-2"><label class="form-label small" for="task-doc">Document id</label><input id="task-doc" v-model.number="form.transaction_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-6"><label class="form-label small" for="task-note">Note</label><input id="task-note" v-model="form.note" class="form-control form-control-sm" /></div>
                <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-dark btn-sm" @click="create">Create list</button></div>
            </div>
        </div>

        <div v-if="open" class="border rounded p-3 mb-4">
            <div class="d-flex justify-content-between"><h6>{{ open.task_no }} <span class="text-muted text-capitalize">({{ open.type }}, {{ open.status.replace('_', ' ') }})</span></h6><button type="button" class="btn btn-link btn-sm" @click="open = null">Close</button></div>
            <ClientTable  :columns="[{ key: 'c0', label: 'Product' }, { key: 'c1', label: 'To do' }, { key: 'c2', label: 'Done' }, { key: 'c3', label: 'Location id' }, { key: 'c4', label: 'Note' }]" :rows="(open.lines).map((line) => ({ __s: { line }, c0: (line.product?.name), c1: (line.quantity), c2: '', c3: '', c4: '' }))" export-name="product">
<template #cell-c2="{ row: __m }"><template v-for="{ line } in [__m.__s]" :key="0"><input v-model.number="line.quantity_done" type="number" min="0" :max="line.quantity" step="any" class="form-control form-control-sm" aria-label="Done" /></template></template>
<template #cell-c3="{ row: __m }"><template v-for="{ line } in [__m.__s]" :key="0"><input v-model.number="line.location_id" type="number" class="form-control form-control-sm" aria-label="Location id" /></template></template>
<template #cell-c4="{ row: __m }"><template v-for="{ line } in [__m.__s]" :key="0"><input v-model="line.note" class="form-control form-control-sm" aria-label="Note" /></template></template>
</ClientTable>
            <template v-if="can('/warehousetask/:id/edit') && ! ['done', 'cancelled'].includes(open.status)">
                <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="save">Save progress</button>
                <button type="button" class="btn btn-dark btn-sm me-2" @click="complete(false)">Complete</button>
                <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="complete(true)">Complete as short</button>
                <button type="button" class="btn btn-outline-danger btn-sm" @click="cancel">Cancel list</button>
            </template>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No lists."  :columns="[{ key: 'c0', label: 'List' }, { key: 'c1', label: 'Type' }, { key: 'c2', label: 'Document' }, { key: 'c3', label: 'Lines' }, { key: 'c4', label: 'Status' }, { key: 'c5', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.task_no), c1: (row.type), c2: (row.transaction_id), c3: (row.lines_count), c4: (row.status.replace('_', ' ')), c5: '' }))" export-name="list">
<template #cell-c5="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><button type="button" class="btn btn-outline-dark btn-sm" @click="show(row)">Open</button></template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
