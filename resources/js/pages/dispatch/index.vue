<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Dispatch Board',
            subtitle: 'Assign riders to posted sales and follow them out and delivered',
            breadcrumbs: [{ title: 'Dispatch Board', href: 'NULL' }],
        },
    });

    type Sale = {
        id: number; invoice_no: string; customer: string | null; date: string | null; amount: number; address: string | null;
        shipping_status: string | null; held_by_layaway: boolean; rider_id: number | null; rider: string | null; dispatch_status: string;
        assignment_id?: number | null;
    };
    type Rider = { id: number; name: string; is_active: boolean };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const sales = ref<Sale[]>([]);
    const riders = ref<Rider[]>([]);
    const chosen = ref<Record<number, number | ''>>({});

    async function load() {
        sales.value = (await run(() => window.axios.get('/api/dispatch/board', { params: scope() })))?.data?.data ?? [];
        riders.value = ((await run(() => window.axios.get('/api/riders', { params: scope() })))?.data?.data ?? []).filter((rider: Rider) => rider.is_active);
    }

    async function assign(sale: Sale) {
        const riderId = chosen.value[sale.id];

        if (! riderId) {
            return;
        }

        if (await run(() => window.axios.post('/api/dispatch/assign', { transaction_id: sale.id, rider_id: riderId }), 'Rider assigned')) {
            await load();
        }
    }

    async function move(sale: Sale, status: string) {
        if (sale.assignment_id && await run(() => window.axios.post(`/api/dispatch/${sale.assignment_id}/move`, { status }), 'Updated')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Dispatch Board" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="Nothing to dispatch."  :columns="[{ key: 'c0', label: 'Invoice' }, { key: 'c1', label: 'Customer' }, { key: 'c2', label: 'Date' }, { key: 'c3', label: 'Amount' }, { key: 'c4', label: 'Address' }, { key: 'c5', label: 'Rider' }, { key: 'c6', label: 'Status' }, { key: 'c7', label: '' }]" :rows="(sales).map((sale) => ({ __s: { sale }, c0: (sale.invoice_no), c1: (sale.customer), c2: (sale.date), c3: (money(sale.amount)), c4: (sale.address), c5: (sale.rider ?? '-'), c6: '', c7: '' }))" export-name="invoice">
<template #cell-c6="{ row: __m }"><template v-for="{ sale } in [__m.__s]" :key="0">
                            <span class="text-capitalize">{{ sale.dispatch_status }}</span>
                            <span v-if="sale.held_by_layaway" class="badge text-bg-warning ms-1">held: layaway</span>
                        </template></template>
<template #cell-c7="{ row: __m }"><template v-for="{ sale } in [__m.__s]" :key="0">
                            <template v-if="can('/dispatch/assign') && ! sale.held_by_layaway">
                                <select v-model="chosen[sale.id]" class="form-select form-select-sm d-inline-block me-1" style="width: 10rem" :aria-label="`Rider for ${sale.invoice_no}`">
                                    <option value="">Rider...</option><option v-for="rider in riders" :key="rider.id" :value="rider.id">{{ rider.name }}</option>
                                </select>
                                <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="assign(sale)">Assign</button>
                                <template v-if="sale.dispatch_status !== 'unassigned'">
                                    <button v-if="sale.dispatch_status === 'assigned'" type="button" class="btn btn-outline-dark btn-sm me-1" @click="move(sale, 'out')">Out</button>
                                    <button v-if="['assigned', 'out'].includes(sale.dispatch_status)" type="button" class="btn btn-dark btn-sm me-1" @click="move(sale, 'delivered')">Delivered</button>
                                    <button v-if="['assigned', 'out'].includes(sale.dispatch_status)" type="button" class="btn btn-outline-danger btn-sm" @click="move(sale, 'failed')">Failed</button>
                                </template>
                            </template>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
