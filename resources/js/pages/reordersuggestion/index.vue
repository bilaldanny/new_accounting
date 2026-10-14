<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Reorder Suggestions',
            subtitle: 'Products at or below their reorder point, with a suggested quantity and supplier',
            breadcrumbs: [{ title: 'Reorder Suggestions', href: 'NULL' }],
        },
    });

    type Row = {
        product_id: number; name: string; sku: string | null; on_hand: number; reorder_point: number; avg_daily_sales: number; lead_time_days: number;
        suggested_qty: number; last_rate: number | null; supplier_id: number | null; supplier_name: string | null; selected?: boolean; quantity?: number;
    };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const rows = ref<Row[]>([]);
    const branchId = ref<number | ''>('');
    const created = ref<{ id: number; invoice_no: string; total: number; lines: number }[]>([]);

    async function load() {
        const response = await run(() => window.axios.get('/api/reorder-suggestions', { params: { ...scope(), branch_id: branchId.value || undefined } }));
        rows.value = (response?.data?.data ?? []).map((row: Row) => ({ ...row, selected: row.supplier_id !== null, quantity: row.suggested_qty }));
    }

    async function createOrders() {
        const items = rows.value.filter((row) => row.selected).map((row) => ({ product_id: row.product_id, quantity: row.quantity, supplier_id: row.supplier_id }));
        const response = await run(() => window.axios.post('/api/reorder-suggestions/purchase-orders', { ...scope(), branch_id: branchId.value || undefined, items }), 'Draft orders created');

        if (response) {
            created.value = response.data.data;
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Reorder Suggestions" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <input v-model.number="branchId" type="number" class="form-control form-control-sm" style="width: 10rem" placeholder="Branch id" aria-label="Branch id" @change="load" />
            <button type="button" class="btn btn-outline-dark btn-sm" @click="load">Refresh</button>
            <button v-if="can('/reordersuggestion/add')" type="button" class="btn btn-dark btn-sm" @click="createOrders">Create draft purchase orders</button>
        </div>

        <div v-if="created.length" class="alert alert-success py-2">
            Created: <span v-for="order in created" :key="order.id" class="me-3">{{ order.invoice_no }} ({{ order.lines }} lines, {{ money(order.total) }})</span>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="Nothing is below its reorder point. Set reorder levels on the product form."  :columns="[{ key: 'c0', label: '' }, { key: 'c1', label: 'Product' }, { key: 'c2', label: 'On hand' }, { key: 'c3', label: 'Reorder point' }, { key: 'c4', label: 'Daily sales' }, { key: 'c5', label: 'Lead time' }, { key: 'c6', label: 'Order qty' }, { key: 'c7', label: 'Last rate' }, { key: 'c8', label: 'Supplier' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: '', c1: '', c2: (row.on_hand), c3: (row.reorder_point), c4: (row.avg_daily_sales), c5: `${row.lead_time_days} d`, c6: '', c7: (row.last_rate === null ? '-' : money(row.last_rate)), c8: (row.supplier_name ?? 'Choose when ordering') }))" export-name="table">
<template #cell-c0="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><input v-model="row.selected" type="checkbox" :aria-label="`Order ${row.name}`" /></template></template>
<template #cell-c1="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ row.name }} <span class="text-muted small">{{ row.sku }}</span></template></template>
<template #cell-c6="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><input v-model.number="row.quantity" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Order quantity" /></template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
