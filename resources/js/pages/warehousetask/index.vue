<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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
            <table class="table table-sm align-middle">
                <thead><tr><th>Product</th><th class="text-end">To do</th><th style="width: 9rem">Done</th><th v-if="open.type === 'putaway'" style="width: 9rem">Location id</th><th>Note</th></tr></thead>
                <tbody>
                    <tr v-for="line in open.lines" :key="line.id">
                        <td>{{ line.product?.name }}</td><td class="text-end">{{ line.quantity }}</td>
                        <td><input v-model.number="line.quantity_done" type="number" min="0" :max="line.quantity" step="any" class="form-control form-control-sm" aria-label="Done" /></td>
                        <td v-if="open.type === 'putaway'"><input v-model.number="line.location_id" type="number" class="form-control form-control-sm" aria-label="Location id" /></td>
                        <td><input v-model="line.note" class="form-control form-control-sm" aria-label="Note" /></td>
                    </tr>
                </tbody>
            </table>
            <template v-if="can('/warehousetask/:id/edit') && ! ['done', 'cancelled'].includes(open.status)">
                <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="save">Save progress</button>
                <button type="button" class="btn btn-dark btn-sm me-2" @click="complete(false)">Complete</button>
                <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="complete(true)">Complete as short</button>
                <button type="button" class="btn btn-outline-danger btn-sm" @click="cancel">Cancel list</button>
            </template>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>List</th><th>Type</th><th>Document</th><th class="text-end">Lines</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.task_no }}</td><td class="text-capitalize">{{ row.type }}</td><td>{{ row.transaction_id }}</td><td class="text-end">{{ row.lines_count }}</td>
                        <td class="text-capitalize">{{ row.status.replace('_', ' ') }}</td>
                        <td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="show(row)">Open</button></td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="6" class="text-center text-muted">No lists.</td></tr>
                </tbody>
            </table>
        </div>
    </div></div></div>
</template>
