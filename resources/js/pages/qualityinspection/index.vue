<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Quality Inspection',
            subtitle: 'Accept or reject what a purchase received; stock counts only what was accepted',
            breadcrumbs: [{ title: 'Quality Inspection', href: 'NULL' }],
        },
    });

    type Inspection = { id: number; inspection_no: string; transaction_id: number; inspected_on: string; result: string; accepted_total: number | null; rejected_total: number | null; note: string | null };
    type Line = { id: number; product_name: string; quantity: number; quantity_received: number; quantity_rejected: number; accepted_qty: number; rejected_qty: number; reason: string };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Inspection[]>([]);
    const purchaseId = ref<number | ''>('');
    const lines = ref<Line[]>([]);
    const date = ref(new Date().toISOString().slice(0, 10));
    const note = ref('');

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/quality-inspections', { params: scope() })))?.data?.data?.data ?? [];
    }

    async function loadLines() {
        const response = await run(() => window.axios.get(`/api/quality-inspections/purchase/${purchaseId.value}/lines`));
        lines.value = (response?.data?.data ?? []).map((line: Line) => ({ ...line, accepted_qty: Number(line.quantity_received), rejected_qty: 0, reason: '' }));
    }

    async function save() {
        const body = {
            transaction_id: purchaseId.value, inspected_on: date.value, note: note.value,
            lines: lines.value.filter((line) => Number(line.quantity_received) > 0).map((line) => ({ purchase_line_id: line.id, accepted_qty: line.accepted_qty, rejected_qty: line.rejected_qty, reason: line.reason })),
        };

        if (await run(() => window.axios.post('/api/quality-inspections', body), 'Inspection saved')) {
            lines.value = [];
            await load();
        }
    }

    async function remove(row: Inspection) {
        if (window.confirm(`Delete ${row.inspection_no}? The rejected quantity goes back to stock.`) && await run(() => window.axios.delete(`/api/quality-inspections/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Quality Inspection" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div v-if="can('/qualityinspection/add')" class="border rounded p-3 mb-4">
            <div class="d-flex flex-wrap gap-2 mb-2">
                <input v-model.number="purchaseId" type="number" class="form-control form-control-sm" style="width: 12rem" placeholder="Purchase id" aria-label="Purchase id" />
                <button type="button" class="btn btn-outline-dark btn-sm" @click="loadLines">Load lines</button>
                <input v-model="date" type="date" class="form-control form-control-sm" style="width: 11rem" aria-label="Inspected on" />
                <input v-model="note" class="form-control form-control-sm" style="width: 18rem" placeholder="Note" aria-label="Note" />
            </div>
            <ClientTable v-if="lines.length" :columns="[{ key: 'c0', label: 'Product' }, { key: 'c1', label: 'Received' }, { key: 'c2', label: 'Already rejected' }, { key: 'c3', label: 'Accepted' }, { key: 'c4', label: 'Rejected' }, { key: 'c5', label: 'Reason' }]" :rows="(lines).map((line) => ({ __s: { line }, c0: (line.product_name), c1: (line.quantity_received), c2: (line.quantity_rejected), c3: '', c4: '', c5: '' }))" export-name="product">
<template #cell-c3="{ row: __m }"><template v-for="{ line } in [__m.__s]" :key="0"><input v-model.number="line.accepted_qty" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Accepted" /></template></template>
<template #cell-c4="{ row: __m }"><template v-for="{ line } in [__m.__s]" :key="0"><input v-model.number="line.rejected_qty" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Rejected" /></template></template>
<template #cell-c5="{ row: __m }"><template v-for="{ line } in [__m.__s]" :key="0"><input v-model="line.reason" class="form-control form-control-sm" aria-label="Reason" /></template></template>
</ClientTable>
            <button v-if="lines.length" type="button" class="btn btn-dark btn-sm" @click="save">Save inspection</button>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No inspections yet."  :columns="[{ key: 'c0', label: 'Inspection' }, { key: 'c1', label: 'Purchase' }, { key: 'c2', label: 'Date' }, { key: 'c3', label: 'Accepted' }, { key: 'c4', label: 'Rejected' }, { key: 'c5', label: 'Result' }, { key: 'c6', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.inspection_no), c1: `#${row.transaction_id}`, c2: (row.inspected_on), c3: (row.accepted_total ?? 0), c4: (row.rejected_total ?? 0), c5: (row.result), c6: '' }))" export-name="inspection">
<template #cell-c6="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><button v-if="can('/qualityinspection/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button></template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
