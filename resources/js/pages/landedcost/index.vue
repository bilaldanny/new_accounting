<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import { computed, onMounted, ref } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Landed Cost',
            subtitle: 'Add freight, duty, insurance and clearing to a purchase and see what each item really cost',
            breadcrumbs: [
                {
                    title: 'Landed Cost',
                    href: 'NULL',
                },
            ],
        },
    });

    type Cost = { type: string; description: string; amount: number | string; allocation_basis: string };

    const { Notify } = useCommons();
    const { props } = usePage();

    const authUser = computed(() => props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const canEdit = computed(() => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes('/landedcost/edit'));

    const types = ['freight', 'customs_duty', 'insurance', 'clearing', 'other'];

    const purchases = ref<Array<Record<string, any>>>([]);
    const search = ref('');
    const detail = ref<Record<string, any> | null>(null);
    const costs = ref<Cost[]>([]);
    const busy = ref(false);

    const money = (value: unknown): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const total = computed(() => costs.value.reduce((sum, cost) => sum + Number(cost.amount || 0), 0));

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function load() {
        purchases.value = (await window.axios.get('/api/landed-costs', { params: { search: search.value } })).data?.data?.data ?? [];
    }

    function use(data: Record<string, any>) {
        detail.value = data;
        costs.value = (data.costs as Cost[]).map((cost) => ({ ...cost }));
    }

    async function open(id: number) {
        try {
            use((await window.axios.get(`/api/landed-costs/${id}`)).data.data);
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    function addCost() {
        costs.value.push({ type: 'freight', description: '', amount: '', allocation_basis: 'value' });
    }

    async function save() {
        busy.value = true;

        try {
            const response = await window.axios.put(`/api/landed-costs/${detail.value?.purchase.id}`, { costs: costs.value });
            use(response.data.data);
            Notify('Landed costs saved', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    onMounted(async () => {
        try {
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    });
</script>

<template>
    <Head title="Landed Cost" />
    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">

    <div v-if="detail" class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h6>Purchase {{ detail.purchase.invoice_no }} · {{ detail.purchase.transaction_date }} · {{ detail.purchase.status }}</h6>
                <button type="button" class="btn-close" aria-label="Close" @click="detail = null" />
            </div>

            <table class="table table-sm align-middle">
                <thead><tr><th>Type</th><th>Description</th><th>Spread by</th><th class="text-end">Amount</th><th /></tr></thead>
                <tbody>
                    <tr v-for="(cost, index) in costs" :key="index">
                        <td><select v-model="cost.type" class="form-select form-select-sm" :disabled="! canEdit"><option v-for="type in types" :key="type" :value="type">{{ type.replace('_', ' ') }}</option></select></td>
                        <td><input v-model="cost.description" class="form-control form-control-sm" maxlength="255" :disabled="! canEdit"></td>
                        <td><select v-model="cost.allocation_basis" class="form-select form-select-sm" :disabled="! canEdit"><option value="value">Value</option><option value="quantity">Quantity</option></select></td>
                        <td><input v-model="cost.amount" type="number" min="0.01" step="0.01" class="form-control form-control-sm text-end" :disabled="! canEdit"></td>
                        <td><button v-if="canEdit" type="button" class="btn btn-link btn-sm text-danger" @click="costs.splice(index, 1)">Remove</button></td>
                    </tr>
                </tbody>
                <tfoot><tr><th colspan="3">Total landed costs</th><th class="text-end">{{ money(total) }}</th><th /></tr></tfoot>
            </table>

            <div v-if="canEdit" class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-outline-primary btn-sm" @click="addCost">Add cost</button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="save">Save and spread over the lines</button>
            </div>
            <p class="text-muted small">
                Saving changes the average cost used by stock valuation and item profit. It posts nothing to the ledger: book the freight bill with an expense or payment voucher as usual.
            </p>

            <ClientTable  :columns="[{ key: 'c0', label: 'Item' }, { key: 'c1', label: 'Qty' }, { key: 'c2', label: 'Rate' }, { key: 'c3', label: 'Line value' }, { key: 'c4', label: 'Landed cost' }, { key: 'c5', label: 'Landed unit cost' }]" :rows="(detail.lines).map((line) => ({ __s: { line }, c0: (line.product), c1: (line.quantity), c2: (money(line.purchase_rate)), c3: (money(line.line_value)), c4: (money(line.landed_cost)), c5: (line.landed_unit_cost === null ? '-' : money(line.landed_unit_cost)) }))" export-name="item">
</ClientTable>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form class="d-flex gap-2 mb-3" @submit.prevent="load">
                <input v-model="search" type="search" class="form-control" placeholder="Search purchase number" style="max-width: 320px">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
            </form>
            <p v-if="! purchases.length" class="text-muted mb-0">No purchases found.</p>
            <div v-else class="table-responsive">
                <ClientTable  :columns="[{ key: 'c0', label: 'Purchase' }, { key: 'c1', label: 'Supplier' }, { key: 'c2', label: 'Date' }, { key: 'c3', label: 'Status' }, { key: 'c4', label: 'Amount' }, { key: 'c5', label: 'Landed costs' }, { key: 'c6', label: '' }]" :rows="(purchases).map((purchase) => ({ __s: { purchase }, c0: (purchase.invoice_no), c1: (purchase.supplier), c2: (purchase.transaction_date), c3: (purchase.status), c4: (money(purchase.final_amount)), c5: (money(purchase.landed_total)), c6: '' }))" export-name="purchase">
<template #cell-c6="{ row: __m }"><template v-for="{ purchase } in [__m.__s]" :key="0"><button type="button" class="btn btn-outline-secondary btn-sm" @click="open(purchase.id)">{{ canEdit ? 'Costs' : 'View' }}</button></template></template>
</ClientTable>
            </div>
        </div>
    </div>
    </div></div></div>
</template>
