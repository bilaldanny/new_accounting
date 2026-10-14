<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Recurring Invoices',
            subtitle: 'Standing invoices made on a schedule',
            breadcrumbs: [{ title: 'Recurring Invoices', href: 'NULL' }],
        },
    });

    type Schedule = {
        id: number; name: string; contact_name: string | null; frequency: string; interval: number; next_run_on: string; runs: number;
        max_runs: number | null; post_as: string; status: string; ends_on: string | null;
    };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Schedule[]>([]);
    const status = ref('all');
    const form = ref({ contact_id: '' as number | '', name: '', frequency: 'monthly', interval: 1, next_run_on: new Date().toISOString().slice(0, 10), ends_on: '', max_runs: '' as number | '', post_as: 'draft', note: '' });
    const lines = ref([{ product_id: '' as number | '', quantity: 1, unit_price: '' as number | '' }]);

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/recurring-invoices', { params: { ...scope(), status: status.value } })))?.data?.data?.data ?? [];
    }

    async function create() {
        const body: Record<string, unknown> = {
            ...scope(), ...form.value,
            lines: lines.value.filter((line) => line.product_id !== '').map((line) => ({ ...line, unit_price: line.unit_price === '' ? null : line.unit_price })),
        };

        for (const key of ['ends_on', 'max_runs', 'note']) {
            if (body[key] === '') {
                delete body[key];
            }
        }

        if (await run(() => window.axios.post('/api/recurring-invoices', body), 'Schedule saved')) {
            lines.value = [{ product_id: '', quantity: 1, unit_price: '' }];
            await load();
        }
    }

    async function setStatus(row: Schedule, next: string) {
        if (await run(() => window.axios.put(`/api/recurring-invoices/${row.id}`, { status: next }), 'Updated')) {
            await load();
        }
    }

    async function remove(row: Schedule) {
        if (window.confirm(`Delete ${row.name}?`) && await run(() => window.axios.delete(`/api/recurring-invoices/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    async function generate() {
        const result = await run(() => window.axios.post('/api/recurring-invoices/generate', scope()));

        if (result) {
            window.alert(result.data.message);
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Recurring Invoices" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <select v-model="status" class="form-select form-select-sm" style="width: auto" aria-label="Status" @change="load">
                <option value="all">All</option><option value="active">Active</option><option value="paused">Paused</option><option value="ended">Ended</option>
            </select>
            <button v-if="can('/recurringinvoice/generate')" type="button" class="btn btn-outline-dark btn-sm" @click="generate">Generate due invoices now</button>
        </div>

        <div v-if="can('/recurringinvoice/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="ri-customer">Customer id</label><input id="ri-customer" v-model.number="form.contact_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="ri-name">Name</label><input id="ri-name" v-model="form.name" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ri-freq">Every</label>
                    <div class="input-group input-group-sm">
                        <input v-model.number="form.interval" type="number" min="1" class="form-control" style="max-width: 4rem" aria-label="Interval" />
                        <select id="ri-freq" v-model="form.frequency" class="form-select"><option v-for="f in ['daily', 'weekly', 'monthly', 'quarterly', 'yearly']" :key="f" :value="f">{{ f }}</option></select>
                    </div>
                </div>
                <div class="col-md-2"><label class="form-label small" for="ri-next">First run</label><input id="ri-next" v-model="form.next_run_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ri-ends">Ends on</label><input id="ri-ends" v-model="form.ends_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-1"><label class="form-label small" for="ri-max">Max runs</label><input id="ri-max" v-model.number="form.max_runs" type="number" min="1" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="ri-post">Make as</label>
                    <select id="ri-post" v-model="form.post_as" class="form-select form-select-sm"><option value="draft">Draft</option><option value="final">Final invoice</option></select>
                </div>
            </div>
            <ClientTable  :columns="[{ key: 'c0', label: 'Product id' }, { key: 'c1', label: 'Quantity' }, { key: 'c2', label: 'Unit price' }, { key: 'c3', label: '' }]" :rows="(lines).map((line, index) => ({ __s: { line, index }, c0: '', c1: '', c2: '', c3: '' }))" export-name="product-id">
<template #cell-c0="{ row: __m }"><template v-for="{ line, index } in [__m.__s]" :key="0"><input v-model.number="line.product_id" type="number" class="form-control form-control-sm" aria-label="Product id" /></template></template>
<template #cell-c1="{ row: __m }"><template v-for="{ line, index } in [__m.__s]" :key="0"><input v-model.number="line.quantity" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Quantity" /></template></template>
<template #cell-c2="{ row: __m }"><template v-for="{ line, index } in [__m.__s]" :key="0"><input v-model.number="line.unit_price" type="number" min="0" step="any" class="form-control form-control-sm" placeholder="List price" aria-label="Unit price" /></template></template>
<template #cell-c3="{ row: __m }"><template v-for="{ line, index } in [__m.__s]" :key="0"><button type="button" class="btn btn-link btn-sm text-danger" @click="lines.splice(index, 1)">Remove</button></template></template>
</ClientTable>
            <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="lines.push({ product_id: '', quantity: 1, unit_price: '' })">Add line</button>
            <button type="button" class="btn btn-dark btn-sm" @click="create">Save schedule</button>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No recurring invoices."  :columns="[{ key: 'c0', label: 'Name' }, { key: 'c1', label: 'Customer' }, { key: 'c2', label: 'Repeats' }, { key: 'c3', label: 'Next run' }, { key: 'c4', label: 'Runs' }, { key: 'c5', label: 'Makes' }, { key: 'c6', label: 'Status' }, { key: 'c7', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.name), c1: (row.contact_name), c2: `every ${row.interval} ${row.frequency}`, c3: (row.next_run_on?.slice(0, 10)), c4: '', c5: (row.post_as), c6: (row.status), c7: '' }))" export-name="name">
<template #cell-c4="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ row.runs }}<span v-if="row.max_runs"> / {{ row.max_runs }}</span></template></template>
<template #cell-c7="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                            <template v-if="can('/recurringinvoice/:id/edit') && row.status !== 'ended'">
                                <button v-if="row.status === 'active'" type="button" class="btn btn-outline-dark btn-sm me-1" @click="setStatus(row, 'paused')">Pause</button>
                                <button v-else type="button" class="btn btn-outline-dark btn-sm me-1" @click="setStatus(row, 'active')">Resume</button>
                                <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="setStatus(row, 'ended')">End</button>
                            </template>
                            <button v-if="can('/recurringinvoice/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
