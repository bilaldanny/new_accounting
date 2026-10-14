<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';
    import { parseSpreadsheetFile, writeImportWorkbook } from '@/composables/importHelpers';

    defineOptions({
        layout: {
            title: 'Opening Stock',
            subtitle: 'The quantity and cost each branch starts with',
            breadcrumbs: [{ title: 'Opening Stock', href: 'NULL' }],
        },
    });

    type Entry = { id: number; branch_id: number; invoice_no: string; transaction_date: string; total_item: number; additional_note: string | null };
    type Row = { sku: string; quantity: number | ''; unit_cost: number | '' };

    const { can, companyId, isSuperadmin, scope, run, Notify } = useCloseout();
    const entries = ref<Entry[]>([]);
    const branchId = ref<number | ''>('');
    const date = ref(new Date().toISOString().slice(0, 10));
    const note = ref('');
    const rows = ref<Row[]>([{ sku: '', quantity: '', unit_cost: '' }]);
    const problems = ref<string[]>([]);
    const fileInput = ref<HTMLInputElement | null>(null);

    async function load() {
        entries.value = (await run(() => window.axios.get('/api/opening-stock', { params: scope() })))?.data?.data?.data ?? [];
    }

    function body(dryRun: boolean) {
        return {
            ...scope(), branch_id: branchId.value === '' ? null : branchId.value, date: date.value, note: note.value, dry_run: dryRun,
            rows: rows.value.filter((row) => row.sku.trim() !== '').map((row) => ({ sku: row.sku.trim(), quantity: row.quantity, unit_cost: row.unit_cost === '' ? null : row.unit_cost })),
        };
    }

    async function submit(dryRun: boolean) {
        problems.value = [];

        try {
            const response = await window.axios.post('/api/opening-stock', body(dryRun));
            Notify(response.data.message, 'success');

            if (! dryRun) {
                rows.value = [{ sku: '', quantity: '', unit_cost: '' }];
                await load();
            }
        } catch (error: unknown) {
            problems.value = window.axios.isAxiosError(error) ? (error.response?.data?.errors?.length ? error.response.data.errors : [error.response?.data?.message ?? 'The request failed.']) : ['The request failed.'];
        }
    }

    async function pickFile() {
        const file = fileInput.value?.files?.[0];

        if (! file) {
            return;
        }

        const parsed = await parseSpreadsheetFile(file);
        rows.value = parsed.map((row) => ({
            sku: String(row.sku ?? row.SKU ?? ''), quantity: row.quantity === undefined || row.quantity === '' ? '' : Number(row.quantity),
            unit_cost: row.unit_cost === undefined || row.unit_cost === '' ? '' : Number(row.unit_cost),
        }));
        Notify(`${rows.value.length} rows read. Check them, then save.`, 'success');
    }

    async function template() {
        await writeImportWorkbook(['sku', 'quantity', 'unit_cost'], [['SKU-001', 10, 95.5]], 'Opening stock', 'opening-stock-template.xlsx');
    }

    onMounted(load);
</script>

<template>
    <Head title="Opening Stock" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div v-if="can('/openingstock/add')" class="border rounded p-3 mb-4">
            <div class="row g-2 mb-2">
                <div v-if="isSuperadmin" class="col-md-2"><label class="form-label small" for="os-company">Company id</label><input id="os-company" v-model.number="companyId" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="os-branch">Branch id</label><input id="os-branch" v-model.number="branchId" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="os-date">As of</label><input id="os-date" v-model="date" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-4"><label class="form-label small" for="os-note">Note</label><input id="os-note" v-model="note" class="form-control form-control-sm" /></div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="button" class="btn btn-outline-dark btn-sm" @click="template">Template</button>
                </div>
            </div>
            <div class="mb-2"><label class="form-label small" for="os-file">Import from a spreadsheet (sku, quantity, unit_cost)</label><input id="os-file" ref="fileInput" type="file" accept=".xlsx,.xls,.csv" class="form-control form-control-sm" @change="pickFile" /></div>

            <table class="table table-sm align-middle">
                <thead><tr><th>SKU or barcode</th><th style="width: 10rem">Quantity</th><th style="width: 10rem">Unit cost</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="index">
                        <td><input v-model="row.sku" class="form-control form-control-sm" aria-label="SKU" /></td>
                        <td><input v-model.number="row.quantity" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Quantity" /></td>
                        <td><input v-model.number="row.unit_cost" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Unit cost" /></td>
                        <td class="text-end"><button type="button" class="btn btn-link btn-sm text-danger" @click="rows.splice(index, 1)">Remove</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="rows.push({ sku: '', quantity: '', unit_cost: '' })">Add row</button>
            <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="submit(true)">Check</button>
            <button type="button" class="btn btn-dark btn-sm" @click="submit(false)">Save opening stock</button>
            <ul v-if="problems.length" class="text-danger small mt-2 mb-0"><li v-for="(problem, index) in problems" :key="index">{{ problem }}</li></ul>
        </div>

        <h6>Opening stock entries</h6>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Number</th><th>Date</th><th>Branch</th><th class="text-end">Lines</th><th>Note</th></tr></thead>
                <tbody>
                    <tr v-for="entry in entries" :key="entry.id"><td>{{ entry.invoice_no }}</td><td>{{ entry.transaction_date }}</td><td>{{ entry.branch_id }}</td><td class="text-end">{{ entry.total_item }}</td><td>{{ entry.additional_note }}</td></tr>
                    <tr v-if="! entries.length"><td colspan="5" class="text-center text-muted">No opening stock entered yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div></div></div>
</template>
