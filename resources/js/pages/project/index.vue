<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Projects',
            subtitle: 'Job costing: revenue, direct cost and margin per project',
            breadcrumbs: [{ title: 'Projects', href: 'NULL' }],
        },
    });

    type Row = { id: number; code: string; name: string; status: string; revenue: number; cost: number; margin: number; margin_percent: number | null; budget_revenue: number; budget_cost: number; cost_over_budget: number | null };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const rows = ref<Row[]>([]);
    const loading = ref(true);
    const from = ref('');
    const to = ref('');
    const form = ref({ code: '', name: '', budget_revenue: 0, budget_cost: 0 });
    const tag = ref({ project_id: '' as number | '', transaction_ids: '', voucher_ids: '' });

    const totals = computed(() => ({
        count: rows.value.length,
        revenue: rows.value.reduce((sum, row) => sum + Number(row.revenue ?? 0), 0),
        cost: rows.value.reduce((sum, row) => sum + Number(row.cost ?? 0), 0),
        margin: rows.value.reduce((sum, row) => sum + Number(row.margin ?? 0), 0),
        over: rows.value.filter((row) => Number(row.cost_over_budget ?? 0) > 0).length,
    }));

    async function load() {
        // a superadmin sees the projects of the company chosen above; nothing is asked of the server before one is chosen
        if (isSuperadmin.value && companyId.value === '') {
            rows.value = [];
            loading.value = false;

            return;
        }

        loading.value = true;
        const response = await run(() => window.axios.get('/api/projects/report', { params: { ...scope(), from: from.value || undefined, to: to.value || undefined } }));
        rows.value = response?.data?.rows ?? [];
        loading.value = false;
    }

    async function save() {
        if (await run(() => window.axios.post('/api/projects', { ...scope(), ...form.value }), 'Project saved')) {
            form.value = { code: '', name: '', budget_revenue: 0, budget_cost: 0 };
            await load();
        }
    }

    const ids = (text: string): number[] => text.split(/[\s,]+/).filter(Boolean).map(Number).filter((id) => Number.isInteger(id) && id > 0);

    async function applyTag() {
        const body = { ...scope(), project_id: tag.value.project_id === '' ? null : tag.value.project_id, transaction_ids: ids(tag.value.transaction_ids), voucher_ids: ids(tag.value.voucher_ids) };

        if (await run(() => window.axios.post('/api/projects/tag', body), 'Tagged')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Projects" />

    <div class="container-fluid py-3 pj">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div class="pj-kpis">
            <article class="pj-kpi"><small>Projects</small><strong>{{ totals.count }}</strong></article>
            <article class="pj-kpi"><small>Revenue</small><strong>{{ money(totals.revenue) }}</strong></article>
            <article class="pj-kpi"><small>Direct cost</small><strong>{{ money(totals.cost) }}</strong></article>
            <article class="pj-kpi pj-kpi--accent"><small>Margin</small><strong>{{ money(totals.margin) }}</strong></article>
            <article class="pj-kpi" :class="{ 'pj-kpi--danger': totals.over > 0 }"><small>Over cost budget</small><strong>{{ totals.over }}</strong></article>
        </div>

        <div v-if="can('/project/add') || can('/project/tag')" class="pj-forms">
            <section v-if="can('/project/add')" class="pj-card">
                <header><h5>New project</h5><p>Give the job a code and an optional budget; sales, purchases and vouchers can then be tagged to it.</p></header>
                <div class="pj-grid">
                    <label class="pj-field pj-field--third"><span>Code</span><input v-model="form.code" class="form-control form-control-sm" placeholder="e.g. PRJ-001"></label>
                    <label class="pj-field pj-field--twothirds"><span>Project name</span><input v-model="form.name" class="form-control form-control-sm" placeholder="e.g. Mall Road branch fit-out"></label>
                    <label class="pj-field pj-field--half"><span>Revenue budget</span><input v-model.number="form.budget_revenue" type="number" min="0" class="form-control form-control-sm"></label>
                    <label class="pj-field pj-field--half"><span>Cost budget</span><input v-model.number="form.budget_cost" type="number" min="0" class="form-control form-control-sm"></label>
                </div>
                <button type="button" class="pj-primary" @click="save">Save project</button>
            </section>

            <section v-if="can('/project/tag')" class="pj-card">
                <header><h5>Tag documents and vouchers</h5><p>Put sales, purchases or vouchers on a project (or clear their tag) by their ids.</p></header>
                <div class="pj-grid">
                    <label class="pj-field pj-field--full">
                        <span>Project</span>
                        <select v-model="tag.project_id" class="form-select form-select-sm">
                            <option value="">No project (clears the tag)</option>
                            <option v-for="row in rows" :key="row.id" :value="row.id">{{ row.code }} - {{ row.name }}</option>
                        </select>
                    </label>
                    <label class="pj-field pj-field--half"><span>Sale / purchase ids</span><input v-model="tag.transaction_ids" class="form-control form-control-sm" placeholder="e.g. 101, 102"></label>
                    <label class="pj-field pj-field--half"><span>Voucher ids</span><input v-model="tag.voucher_ids" class="form-control form-control-sm" placeholder="e.g. 45"></label>
                </div>
                <button type="button" class="pj-secondary" @click="applyTag">Apply tag</button>
            </section>
        </div>

        <section class="pj-card pj-card--table">
            <header class="pj-table-head">
                <div><h5>Project results</h5><p>Revenue and direct cost tagged to each project in the period.</p></div>
                <div class="pj-dates">
                    <label class="pj-field"><span>From</span><input id="pj-from" v-model="from" type="date" class="form-control form-control-sm" @change="load"></label>
                    <label class="pj-field"><span>To</span><input id="pj-to" v-model="to" type="date" class="form-control form-control-sm" @change="load"></label>
                </div>
            </header>

            <div v-if="loading" class="pj-empty">Loading...</div>
            <div v-else class="pj-table-wrap">
                <table class="pj-table">
                    <thead>
                        <tr><th>Code</th><th>Project</th><th>Status</th><th class="num">Revenue</th><th class="num">Cost</th><th class="num">Margin</th><th class="num">Margin %</th><th class="num">Cost budget</th><th class="num">Over budget</th></tr>
                    </thead>
                    <tbody>
                        <tr v-if="! rows.length">
                            <td colspan="9" class="pj-empty-cell">
                                <strong>No projects yet</strong>
                                <span>{{ isSuperadmin && companyId === '' ? 'Choose a company above to see its projects.' : 'Create a project above to start tracking its margin.' }}</span>
                            </td>
                        </tr>
                        <tr v-for="row in rows" :key="row.id">
                            <td><strong>{{ row.code }}</strong></td>
                            <td>{{ row.name }}</td>
                            <td><span class="pj-status">{{ row.status.replace('_', ' ') }}</span></td>
                            <td class="num">{{ money(row.revenue) }}</td>
                            <td class="num">{{ money(row.cost) }}</td>
                            <td class="num" :class="Number(row.margin) < 0 ? 'pj-neg' : 'pj-pos'">{{ money(row.margin) }}</td>
                            <td class="num">{{ row.margin_percent === null ? '-' : `${row.margin_percent}%` }}</td>
                            <td class="num">{{ money(row.budget_cost) }}</td>
                            <td class="num"><span v-if="row.cost_over_budget !== null && Number(row.cost_over_budget) > 0" class="pj-over">{{ money(row.cost_over_budget) }}</span><span v-else>-</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

<style scoped>
.pj {
    --pj-accent: #199683;
    --pj-accent-dark: #0f766e;
}

.pj-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr));
    gap: 0.75rem;
    margin-bottom: 1.1rem;
}

.pj-kpi {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.8rem 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.95rem;
    background: #fff;
}

.pj-kpi small {
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #94a3b8;
}

.pj-kpi strong {
    font-size: 1.2rem;
    color: #0f172a;
    font-variant-numeric: tabular-nums;
}

.pj-kpi--accent {
    background: rgba(25, 150, 131, 0.08);
    border-color: rgba(25, 150, 131, 0.22);
}

.pj-kpi--accent strong {
    color: var(--pj-accent-dark);
}

.pj-kpi--danger {
    background: rgba(220, 38, 38, 0.06);
    border-color: rgba(220, 38, 38, 0.18);
}

.pj-kpi--danger strong {
    color: #b91c1c;
}

.pj-forms {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr));
    gap: 1rem;
    margin-bottom: 1.1rem;
}

.pj-card {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    padding: 1.1rem 1.25rem;
    border: 1px solid #e2e8f0;
    border-radius: 1rem;
    background: #fff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.pj-card h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}

.pj-card p {
    margin: 0.15rem 0 0;
    font-size: 0.83rem;
    color: #64748b;
}

.pj-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.pj-field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin: 0;
}

.pj-field > span {
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #64748b;
}

.pj-field--third { flex: 1 1 8rem; }
.pj-field--twothirds { flex: 2 1 14rem; }
.pj-field--half { flex: 1 1 10rem; }
.pj-field--full { flex: 1 1 100%; }

.pj-primary,
.pj-secondary {
    align-self: flex-start;
    padding: 0.5rem 1.3rem;
    border-radius: 999px;
    font-weight: 700;
}

.pj-primary {
    border: 0;
    background: linear-gradient(135deg, #199683, #0f766e);
    color: #fff;
    box-shadow: 0 6px 16px rgba(15, 118, 110, 0.25);
}

.pj-secondary {
    border: 1px solid var(--pj-accent);
    background: #fff;
    color: var(--pj-accent-dark);
}

.pj-table-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 0.75rem;
}

.pj-dates {
    display: flex;
    gap: 0.75rem;
}

.pj-table-wrap {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 0.9rem;
}

.pj-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.pj-table th {
    padding: 0.7rem 0.9rem;
    text-align: left;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}

.pj-table td {
    padding: 0.65rem 0.9rem;
    border-bottom: 1px solid #eef2f6;
}

.pj-table tbody tr:last-child td {
    border-bottom: 0;
}

.pj-table tbody tr:hover {
    background: rgba(25, 150, 131, 0.045);
}

.pj-table .num {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.pj-pos { color: var(--pj-accent-dark); font-weight: 700; }
.pj-neg { color: #b91c1c; font-weight: 700; }

.pj-status {
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    background: #f1f5f9;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: capitalize;
}

.pj-over {
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    background: #fee2e2;
    color: #b91c1c;
    font-weight: 700;
}

.pj-empty-cell {
    padding: 1.6rem 1rem !important;
    text-align: center;
    color: #64748b;
}

.pj-empty-cell strong {
    display: block;
    color: #0f172a;
}

.pj-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    padding: 2rem 1rem;
    border: 1px dashed #cbd5e1;
    border-radius: 0.95rem;
    background: #f8fafc;
    color: #64748b;
    text-align: center;
}
</style>
