<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Import Sales',
            subtitle: 'Bring sales in from a spreadsheet, one row per invoice line',
            breadcrumbs: [{ title: 'Import Sales', href: 'NULL' }],
        },
    });

    type Result = {
        message: string; dry_run: boolean; errors: string[];
        summary: { rows: number; invoices: number; created: number; duplicates: number; failed: number };
        invoices: { invoice_ref: string; status: string; invoice_no: string | null; total: number | null; errors: string[] }[];
    };

    const columns = ['invoice_ref', 'date', 'customer_code', 'customer_mobile', 'customer', 'sku', 'quantity', 'unit_price', 'discount'];
    const { can, companyId, isSuperadmin, scope, run, errorMessage, money, Notify } = useCloseout();
    const text = ref('');
    const status = ref('draft');
    const result = ref<Result | null>(null);

    function parse(): Record<string, string>[] {
        const lines = text.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);

        if (lines.length < 2) {
            return [];
        }

        const delimiter = lines[0].includes('\t') ? '\t' : ',';
        const header = lines[0].split(delimiter).map((cell) => cell.trim().toLowerCase().replace(/\s+/g, '_'));

        return lines.slice(1).map((line) => {
            const cells = line.split(delimiter);

            return Object.fromEntries(header.map((name, index) => [name, (cells[index] ?? '').trim()]));
        });
    }

    async function send(dryRun: boolean) {
        const rows = parse();

        if (! rows.length) {
            Notify('Paste the header row and at least one data row.', 'alert');

            return;
        }

        try {
            const response = await window.axios.post('/api/sells/import', { ...scope(), rows, status: status.value, dry_run: dryRun });
            result.value = response.data;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error) && error.response?.data?.summary) {
                result.value = error.response.data;
            } else {
                Notify(errorMessage(error), 'alert');
            }
        }
    }
</script>

<template>
    <Head title="Import Sales" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" />
            <select v-model="status" class="form-select form-select-sm" style="width: auto" aria-label="Import as">
                <option value="draft">Import as drafts</option><option value="final">Import as final invoices</option>
            </select>
        </div>

        <p class="small text-muted">
            Paste rows copied from a spreadsheet (tab or comma separated) with a header row. Columns: <code>{{ columns.join(', ') }}</code>.
            Rows with the same <code>invoice_ref</code> become one invoice. Customers are matched by code, mobile or name; products by SKU or barcode.
            Nothing is saved unless every row is valid.
        </p>
        <textarea v-model="text" class="form-control mb-3" rows="8" aria-label="Rows to import" :placeholder="columns.join('\t')"></textarea>
        <div v-if="can('/sell/import')">
            <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="send(true)">Check</button>
            <button type="button" class="btn btn-dark btn-sm" @click="send(false)">Import</button>
        </div>

        <div v-if="result" class="mt-4">
            <div class="alert py-2" :class="result.summary.failed ? 'alert-danger' : 'alert-success'">{{ result.message }}</div>
            <ClientTable  :columns="[{ key: 'c0', label: 'Invoice ref' }, { key: 'c1', label: 'Result' }, { key: 'c2', label: 'Invoice' }, { key: 'c3', label: 'Total' }, { key: 'c4', label: 'Problems' }]" :rows="(result.invoices).map((invoice) => ({ __s: { invoice }, c0: (invoice.invoice_ref), c1: (invoice.status), c2: (invoice.invoice_no), c3: (invoice.total === null ? '' : money(invoice.total)), c4: (invoice.errors.join(' ')) }))" export-name="invoice-ref">
</ClientTable>
        </div>
    </div></div></div>
</template>
