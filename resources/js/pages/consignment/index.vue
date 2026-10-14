<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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
            <table class="table table-sm align-middle">
                <thead><tr><th>Supplier</th><th>Product</th><th class="text-end">On hand</th><th class="text-end">Sold, not settled</th><th class="text-end">Owed</th><th>Since</th></tr></thead>
                <tbody>
                    <tr v-for="row in stock" :key="row.product_id">
                        <td>{{ row.supplier_name }}</td><td>{{ row.name }} <span class="text-muted small">{{ row.sku }}</span></td>
                        <td class="text-end">{{ row.on_hand }}</td><td class="text-end">{{ row.sold_unsettled }}</td><td class="text-end">{{ money(row.owed) }}</td><td>{{ row.unsettled_from ?? 'start' }}</td>
                    </tr>
                    <tr v-if="! stock.length"><td colspan="6" class="text-center text-muted">No consignment products. Set a product's ownership to consignment and name its supplier.</td></tr>
                    <tr v-else class="fw-semibold"><td colspan="4" class="text-end">Total owed</td><td class="text-end">{{ money(owed) }}</td><td></td></tr>
                </tbody>
            </table>
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
            <table class="table table-sm align-middle">
                <thead><tr><th>Number</th><th>Period</th><th class="text-end">Amount</th><th>Status</th><th>Reference</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in settlements" :key="row.id">
                        <td>{{ row.settlement_no }}</td><td>{{ row.period_from }} to {{ row.period_to }}</td><td class="text-end">{{ money(row.total_amount) }}</td>
                        <td class="text-capitalize">{{ row.status }}</td><td>{{ row.reference }}</td>
                        <td class="text-end text-nowrap">
                            <button v-if="row.status === 'draft' && can('/consignment/:id/edit')" type="button" class="btn btn-dark btn-sm me-1" @click="settle(row)">Mark settled</button>
                            <button v-if="row.status === 'draft' && can('/consignment/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="! settlements.length"><td colspan="6" class="text-center text-muted">No settlements yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div></div></div>
</template>
