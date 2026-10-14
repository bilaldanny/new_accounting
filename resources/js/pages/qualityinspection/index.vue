<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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

    <div class="container-fluid py-3">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div v-if="can('/qualityinspection/add')" class="border rounded p-3 mb-4">
            <div class="d-flex flex-wrap gap-2 mb-2">
                <input v-model.number="purchaseId" type="number" class="form-control form-control-sm" style="width: 12rem" placeholder="Purchase id" aria-label="Purchase id" />
                <button type="button" class="btn btn-outline-dark btn-sm" @click="loadLines">Load lines</button>
                <input v-model="date" type="date" class="form-control form-control-sm" style="width: 11rem" aria-label="Inspected on" />
                <input v-model="note" class="form-control form-control-sm" style="width: 18rem" placeholder="Note" aria-label="Note" />
            </div>
            <table v-if="lines.length" class="table table-sm align-middle">
                <thead><tr><th>Product</th><th class="text-end">Received</th><th class="text-end">Already rejected</th><th style="width: 9rem">Accepted</th><th style="width: 9rem">Rejected</th><th>Reason</th></tr></thead>
                <tbody>
                    <tr v-for="line in lines" :key="line.id">
                        <td>{{ line.product_name }}</td><td class="text-end">{{ line.quantity_received }}</td><td class="text-end">{{ line.quantity_rejected }}</td>
                        <td><input v-model.number="line.accepted_qty" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Accepted" /></td>
                        <td><input v-model.number="line.rejected_qty" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Rejected" /></td>
                        <td><input v-model="line.reason" class="form-control form-control-sm" aria-label="Reason" /></td>
                    </tr>
                </tbody>
            </table>
            <button v-if="lines.length" type="button" class="btn btn-dark btn-sm" @click="save">Save inspection</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Inspection</th><th>Purchase</th><th>Date</th><th class="text-end">Accepted</th><th class="text-end">Rejected</th><th>Result</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.inspection_no }}</td><td>#{{ row.transaction_id }}</td><td>{{ row.inspected_on }}</td>
                        <td class="text-end">{{ row.accepted_total ?? 0 }}</td><td class="text-end">{{ row.rejected_total ?? 0 }}</td><td class="text-capitalize">{{ row.result }}</td>
                        <td class="text-end"><button v-if="can('/qualityinspection/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button></td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="7" class="text-center text-muted">No inspections yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
