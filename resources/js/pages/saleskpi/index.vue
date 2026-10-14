<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Sales KPI & Commission',
            subtitle: 'Net sales against targets, with the commission each person earned',
            breadcrumbs: [{ title: 'Sales KPI & Commission', href: 'NULL' }],
        },
    });

    type Row = {
        subject_id: number; name: string; sales: number; gross: number; returned: number; net: number; target: number | null;
        achievement_percent: number | null; rate_percent: number | null; commission: number | null;
    };
    type Target = { id: number; subject_type: string; subject_id: number; period: string; target_amount: number };
    type Tier = { id: number; commission_agent_id: number | null; threshold_amount: number; rate_percent: number };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const period = ref(new Date().toISOString().slice(0, 7));
    const subject = ref('agent');
    const rows = ref<Row[]>([]);
    const totals = ref({ net: 0, commission: 0 });
    const targets = ref<Target[]>([]);
    const tiers = ref<Tier[]>([]);
    const target = ref({ subject_type: 'user', subject_id: '' as number | '', period: new Date().toISOString().slice(0, 7), target_amount: '' as number | '' });
    const tier = ref({ commission_agent_id: '' as number | '', threshold_amount: '' as number | '', rate_percent: '' as number | '' });

    async function load() {
        const statement = (await run(() => window.axios.get('/api/sales-kpi/statement', { params: { ...scope(), period: period.value, subject: subject.value } })))?.data;
        rows.value = statement?.data ?? [];
        totals.value = statement?.totals ?? { net: 0, commission: 0 };
        targets.value = (await run(() => window.axios.get('/api/sales-kpi/targets', { params: { ...scope(), period: period.value } })))?.data?.data ?? [];
        tiers.value = (await run(() => window.axios.get('/api/sales-kpi/tiers', { params: scope() })))?.data?.data ?? [];
    }

    async function saveTarget() {
        if (await run(() => window.axios.post('/api/sales-kpi/targets', { ...scope(), ...target.value }), 'Target saved')) {
            await load();
        }
    }

    async function saveTier() {
        const body: Record<string, unknown> = { ...scope(), ...tier.value };

        if (body.commission_agent_id === '') {
            delete body.commission_agent_id;
        }

        if (await run(() => window.axios.post('/api/sales-kpi/tiers', body), 'Tier saved')) {
            await load();
        }
    }

    async function removeTier(row: Tier) {
        if (await run(() => window.axios.delete(`/api/sales-kpi/tiers/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Sales KPI & Commission" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <input v-model="period" type="month" class="form-control form-control-sm" style="width: auto" aria-label="Period" @change="load" />
            <select v-model="subject" class="form-select form-select-sm" style="width: auto" aria-label="Group by" @change="load">
                <option value="agent">Commission agent</option><option value="user">Salesperson</option><option value="cashier">Cashier</option>
            </select>
        </div>

        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead><tr><th>Name</th><th class="text-end">Sales</th><th class="text-end">Gross</th><th class="text-end">Returned</th><th class="text-end">Net</th><th class="text-end">Target</th><th class="text-end">Achieved %</th><th class="text-end">Rate %</th><th class="text-end">Commission</th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.subject_id">
                        <td>{{ row.name }}</td><td class="text-end">{{ row.sales }}</td><td class="text-end">{{ money(row.gross) }}</td><td class="text-end">{{ money(row.returned) }}</td><td class="text-end">{{ money(row.net) }}</td>
                        <td class="text-end">{{ row.target === null ? '-' : money(row.target) }}</td><td class="text-end">{{ row.achievement_percent ?? '-' }}</td>
                        <td class="text-end">{{ row.rate_percent ?? '-' }}</td><td class="text-end">{{ row.commission === null ? '-' : money(row.commission) }}</td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="9" class="text-center text-muted">No sales in this period.</td></tr>
                </tbody>
                <tfoot v-if="rows.length"><tr><th colspan="4">Total</th><th class="text-end">{{ money(totals.net) }}</th><th colspan="3"></th><th class="text-end">{{ money(totals.commission) }}</th></tr></tfoot>
            </table>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <h6>Targets of {{ period }}</h6>
                <div v-if="can('/saleskpi/edit')" class="row g-2 mb-2">
                    <div class="col-3"><select v-model="target.subject_type" class="form-select form-select-sm" aria-label="Target for"><option value="user">User</option><option value="agent">Agent</option><option value="cashier">Cashier</option></select></div>
                    <div class="col-3"><input v-model.number="target.subject_id" type="number" class="form-control form-control-sm" placeholder="Id" aria-label="Subject id" /></div>
                    <div class="col-3"><input v-model="target.period" type="month" class="form-control form-control-sm" aria-label="Target period" /></div>
                    <div class="col-3"><input v-model.number="target.target_amount" type="number" min="0" step="any" class="form-control form-control-sm" placeholder="Amount" aria-label="Target amount" /></div>
                    <div class="col-12"><button type="button" class="btn btn-dark btn-sm" @click="saveTarget">Save target</button></div>
                </div>
                <ClientTable empty-text="No targets set."  :columns="[{ key: 'c0', label: 'For' }, { key: 'c1', label: 'Id' }, { key: 'c2', label: 'Target' }]" :rows="(targets).map((row) => ({ __s: { row }, c0: (row.subject_type), c1: (row.subject_id), c2: (money(row.target_amount)) }))" export-name="for">
</ClientTable>
            </div>
            <div class="col-lg-6">
                <h6>Commission tiers</h6>
                <p class="small text-muted mb-2">A tier replaces the agent's own rate once net sales reach its threshold (only when higher).</p>
                <div v-if="can('/saleskpi/edit')" class="row g-2 mb-2">
                    <div class="col-4"><input v-model.number="tier.commission_agent_id" type="number" class="form-control form-control-sm" placeholder="Agent id (all)" aria-label="Agent id" /></div>
                    <div class="col-4"><input v-model.number="tier.threshold_amount" type="number" min="0" step="any" class="form-control form-control-sm" placeholder="Threshold" aria-label="Threshold" /></div>
                    <div class="col-4"><input v-model.number="tier.rate_percent" type="number" min="0" step="any" class="form-control form-control-sm" placeholder="Rate %" aria-label="Rate" /></div>
                    <div class="col-12"><button type="button" class="btn btn-dark btn-sm" @click="saveTier">Add tier</button></div>
                </div>
                <ClientTable empty-text="No tiers."  :columns="[{ key: 'c0', label: 'Agent' }, { key: 'c1', label: 'Threshold' }, { key: 'c2', label: 'Rate %' }, { key: 'c3', label: '' }]" :rows="(tiers).map((row) => ({ __s: { row }, c0: (row.commission_agent_id ?? 'All agents'), c1: (money(row.threshold_amount)), c2: (row.rate_percent), c3: '' }))" export-name="agent">
<template #cell-c3="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><button v-if="can('/saleskpi/edit')" type="button" class="btn btn-outline-danger btn-sm" @click="removeTier(row)">Delete</button></template></template>
</ClientTable>
            </div>
        </div>
    </div></div></div>
</template>
