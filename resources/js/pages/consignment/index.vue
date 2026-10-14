<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Consignment Stock',
            subtitle: 'Supplier-owned stock you sell and settle for',
            breadcrumbs: [{ title: 'Consignment Stock', href: 'NULL' }],
        },
    });

    type StockRow = { product_id: number; name: string; sku: string | null; supplier_id: number; supplier_name: string; on_hand: number; sold_unsettled: number; owed: number; unsettled_from: string | null };
    type Settlement = { id: number; settlement_no: string; supplier_id: number; period_from: string; period_to: string; status: string; total_amount: number; reference: string | null; settled_on: string | null };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const stock = ref<StockRow[]>([]);
    const owed = ref(0);
    const settlements = ref<Settlement[]>([]);
    const form = ref({ supplier_id: '' as number | '', period_from: '', period_to: new Date().toISOString().slice(0, 10), note: '' });

    async function load() {
        const [stockResponse, listResponse] = await Promise.all([
            run(() => window.axios.get('/api/consignment/stock', { params: scope() })),
            run(() => window.axios.get('/api/consignment/settlements', { params: scope() })),
        ]);
        stock.value = stockResponse?.data?.data ?? [];
        owed.value = stockResponse?.data?.total_owed ?? 0;
        settlements.value = listResponse?.data?.data?.data ?? listResponse?.data?.data ?? [];
    }

    async function create() {
        if (await run(() => window.axios.post('/api/consignment/settlements', { ...scope(), ...form.value }), 'Settlement drafted')) {
            await load();
        }
    }

    async function settle(row: Settlement) {
        const reference = window.prompt('Payment reference (cheque, transfer or voucher number)', '');

        if (reference === null) {
            return;
        }

        const settled = await run(() => window.axios.post(`/api/consignment/settlements/${row.id}/settle`, { reference, settled_on: new Date().toISOString().slice(0, 10) }), 'Settled');

        if (settled) {
            await load();
        }
    }

    async function remove(row: Settlement) {
        if (window.confirm(`Delete ${row.settlement_no}?`) && await run(() => window.axios.delete(`/api/consignment/settlements/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Consignment Stock" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <h6>Stock held for suppliers</h6>
        <div class="table-responsive mb-4">
            <ClientTable empty-text="No consignment products. Set a product's ownership to consignment and name its supplier."  :columns="[{ key: 'c0', label: 'Supplier' }, { key: 'c1', label: 'Product' }, { key: 'c2', label: 'On hand' }, { key: 'c3', label: 'Sold, not settled' }, { key: 'c4', label: 'Owed' }, { key: 'c5', label: 'Since' }]" :rows="(stock).map((row) => ({ __s: { row }, c0: (row.supplier_name), c1: '', c2: (row.on_hand), c3: (row.sold_unsettled), c4: (money(row.owed)), c5: (row.unsettled_from ?? 'start') }))" export-name="supplier">
<template #cell-c1="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ row.name }} <span class="text-muted small">{{ row.sku }}</span></template></template>
</ClientTable>
        </div>

        <div v-if="can('/consignment/add')" class="border rounded p-3 mb-4">
            <h6>Draft a settlement</h6>
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label small" for="cons-supplier">Supplier id</label><input id="cons-supplier" v-model.number="form.supplier_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="cons-from">Sold from</label><input id="cons-from" v-model="form.period_from" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="cons-to">Sold to</label><input id="cons-to" v-model="form.period_to" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="cons-note">Note</label><input id="cons-note" v-model="form.note" class="form-control form-control-sm" /></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3" @click="create">Draft settlement</button>
        </div>

        <h6>Settlements</h6>
        <div class="table-responsive">
            <ClientTable empty-text="No settlements yet."  :columns="[{ key: 'c0', label: 'Number' }, { key: 'c1', label: 'Period' }, { key: 'c2', label: 'Amount' }, { key: 'c3', label: 'Status' }, { key: 'c4', label: 'Reference' }, { key: 'c5', label: '' }]" :rows="(settlements).map((row) => ({ __s: { row }, c0: (row.settlement_no), c1: `${row.period_from} to ${row.period_to}`, c2: (money(row.total_amount)), c3: (row.status), c4: (row.reference), c5: '' }))" export-name="number">
<template #cell-c5="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                            <button v-if="row.status === 'draft' && can('/consignment/:id/edit')" type="button" class="btn btn-dark btn-sm me-1" @click="settle(row)">Mark settled</button>
                            <button v-if="row.status === 'draft' && can('/consignment/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
