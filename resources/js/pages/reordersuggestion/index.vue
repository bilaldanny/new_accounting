<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
            <input v-model.number="branchId" type="number" class="form-control form-control-sm" style="width: 10rem" placeholder="Branch id" aria-label="Branch id" @change="load" />
            <button type="button" class="btn btn-outline-dark btn-sm" @click="load">Refresh</button>
            <button v-if="can('/reordersuggestion/add')" type="button" class="btn btn-dark btn-sm" @click="createOrders">Create draft purchase orders</button>
        </div>

        <div v-if="created.length" class="alert alert-success py-2">
            Created: <span v-for="order in created" :key="order.id" class="me-3">{{ order.invoice_no }} ({{ order.lines }} lines, {{ money(order.total) }})</span>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th></th><th>Product</th><th class="text-end">On hand</th><th class="text-end">Reorder point</th><th class="text-end">Daily sales</th><th class="text-end">Lead time</th><th style="width: 8rem">Order qty</th><th class="text-end">Last rate</th><th>Supplier</th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.product_id">
                        <td><input v-model="row.selected" type="checkbox" :aria-label="`Order ${row.name}`" /></td>
                        <td>{{ row.name }} <span class="text-muted small">{{ row.sku }}</span></td>
                        <td class="text-end">{{ row.on_hand }}</td><td class="text-end">{{ row.reorder_point }}</td><td class="text-end">{{ row.avg_daily_sales }}</td><td class="text-end">{{ row.lead_time_days }} d</td>
                        <td><input v-model.number="row.quantity" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Order quantity" /></td>
                        <td class="text-end">{{ row.last_rate === null ? '-' : money(row.last_rate) }}</td>
                        <td>{{ row.supplier_name ?? 'Choose when ordering' }}</td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="9" class="text-center text-muted">Nothing is below its reorder point. Set reorder levels on the product form.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
