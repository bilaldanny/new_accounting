<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Import Purchases',
            subtitle: 'LC, customs clearing and foreign currency details of import purchases',
            breadcrumbs: [{ title: 'Import Purchases', href: 'NULL' }],
        },
    });

    type Detail = {
        transaction_id: number; invoice_no: string | null; lc_no: string | null; lc_bank: string | null; lc_amount: number | null; lc_opened_on: string | null; lc_expires_on: string | null;
        bl_no: string | null; customs_ref: string | null; customs_cleared_on: string | null; clearing_agent: string | null; currency_code: string | null;
        exchange_rate: number | null; foreign_total: number | null; notes: string | null; purchase_total: number | null; local_equivalent: number | null; variance: number | null;
    };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const rows = ref<Detail[]>([]);
    const purchaseId = ref<number | ''>('');
    const form = ref<Record<string, string | number | null>>({});

    const fields: [string, string, string][] = [
        ['lc_no', 'LC number', 'text'], ['lc_bank', 'LC bank', 'text'], ['lc_amount', 'LC amount', 'number'], ['lc_opened_on', 'LC opened', 'date'], ['lc_expires_on', 'LC expires', 'date'],
        ['bl_no', 'Bill of lading', 'text'], ['customs_ref', 'Customs reference', 'text'], ['customs_cleared_on', 'Customs cleared', 'date'], ['clearing_agent', 'Clearing agent', 'text'],
        ['currency_code', 'Currency', 'text'], ['exchange_rate', 'Exchange rate', 'number'], ['foreign_total', 'Total in that currency', 'number'], ['notes', 'Notes', 'text'],
    ];

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/import-purchases', { params: scope() })))?.data?.data ?? [];
    }

    async function open(id: number) {
        const response = await run(() => window.axios.get(`/api/import-purchases/${id}`));

        if (response) {
            purchaseId.value = id;
            form.value = { ...(response.data.data ?? {}) };
        }
    }

    async function save() {
        if (purchaseId.value === '') {
            return;
        }

        const body: Record<string, unknown> = {};
        fields.forEach(([key]) => {
            body[key] = form.value[key] === '' ? null : (form.value[key] ?? null);
        });

        if (await run(() => window.axios.put(`/api/import-purchases/${purchaseId.value}`, body), 'Saved')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Import Purchases" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <input v-model.number="purchaseId" type="number" class="form-control form-control-sm" style="width: 12rem" placeholder="Purchase id" aria-label="Purchase id" />
            <button type="button" class="btn btn-outline-dark btn-sm" @click="open(Number(purchaseId))">Open details</button>
        </div>

        <div v-if="purchaseId !== '' && Object.keys(form).length >= 0 && can('/importpurchase/:id/edit')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div v-for="[key, label, type] in fields" :key="key" class="col-md-3">
                    <label class="form-label small" :for="`ip-${key}`">{{ label }}</label>
                    <input :id="`ip-${key}`" v-model="form[key]" :type="type" step="any" class="form-control form-control-sm" />
                </div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3" @click="save">Save details</button>
            <span class="small text-muted ms-3">The purchase keeps posting in the local currency; these details travel with it.</span>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No import details yet. Enter a purchase id above."  :columns="[{ key: 'c0', label: 'Purchase' }, { key: 'c1', label: 'LC' }, { key: 'c2', label: 'Bill of lading' }, { key: 'c3', label: 'Customs' }, { key: 'c4', label: 'Currency' }, { key: 'c5', label: 'Foreign total' }, { key: 'c6', label: 'Local equivalent' }, { key: 'c7', label: 'Difference' }, { key: 'c8', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.invoice_no), c1: '', c2: (row.bl_no), c3: '', c4: `${row.currency_code} ${row.exchange_rate}`, c5: (row.foreign_total === null ? '-' : money(row.foreign_total)), c6: (row.local_equivalent === null ? '-' : money(row.local_equivalent)), c7: (row.variance === null ? '-' : money(row.variance)), c8: '' }))" export-name="purchase">
<template #cell-c1="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ row.lc_no }} <span class="text-muted small">{{ row.lc_bank }}</span></template></template>
<template #cell-c3="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ row.customs_ref }} <span class="text-muted small">{{ row.customs_cleared_on }}</span></template></template>
<template #cell-c8="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><button type="button" class="btn btn-outline-dark btn-sm" @click="open(row.transaction_id)">Open</button></template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
