<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
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
    const from = ref('');
    const to = ref('');
    const form = ref({ code: '', name: '', budget_revenue: 0, budget_cost: 0 });
    const tag = ref({ project_id: '' as number | '', transaction_ids: '', voucher_ids: '' });

    async function load() {
        const response = await run(() => window.axios.get('/api/projects/report', { params: { ...scope(), from: from.value || undefined, to: to.value || undefined } }));
        rows.value = response?.data?.rows ?? [];
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

    <div class="container-fluid py-3">
        <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm mb-3" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />

        <div class="row g-3 mb-4">
            <div v-if="can('/project/add')" class="col-md-6">
                <div class="border rounded p-3">
                    <h6>New project</h6>
                    <div class="row g-2">
                        <div class="col-4"><input v-model="form.code" class="form-control form-control-sm" placeholder="Code" aria-label="Code" /></div>
                        <div class="col-8"><input v-model="form.name" class="form-control form-control-sm" placeholder="Name" aria-label="Name" /></div>
                        <div class="col-6"><input v-model.number="form.budget_revenue" type="number" class="form-control form-control-sm" placeholder="Revenue budget" aria-label="Revenue budget" /></div>
                        <div class="col-6"><input v-model.number="form.budget_cost" type="number" class="form-control form-control-sm" placeholder="Cost budget" aria-label="Cost budget" /></div>
                        <div class="col-12"><button type="button" class="btn btn-dark btn-sm" @click="save">Save project</button></div>
                    </div>
                </div>
            </div>
            <div v-if="can('/project/tag')" class="col-md-6">
                <div class="border rounded p-3">
                    <h6>Tag documents and vouchers</h6>
                    <div class="row g-2">
                        <div class="col-12"><input v-model.number="tag.project_id" type="number" class="form-control form-control-sm" placeholder="Project id (empty clears the tag)" aria-label="Project id" /></div>
                        <div class="col-6"><input v-model="tag.transaction_ids" class="form-control form-control-sm" placeholder="Sale / purchase ids" aria-label="Sale or purchase ids" /></div>
                        <div class="col-6"><input v-model="tag.voucher_ids" class="form-control form-control-sm" placeholder="Voucher ids" aria-label="Voucher ids" /></div>
                        <div class="col-12"><button type="button" class="btn btn-outline-dark btn-sm" @click="applyTag">Apply tag</button></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-2 align-items-end mb-2">
            <div class="col-auto"><label class="form-label small" for="pj-from">From</label><input id="pj-from" v-model="from" type="date" class="form-control form-control-sm" @change="load" /></div>
            <div class="col-auto"><label class="form-label small" for="pj-to">To</label><input id="pj-to" v-model="to" type="date" class="form-control form-control-sm" @change="load" /></div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Code</th><th>Project</th><th>Status</th><th class="text-end">Revenue</th><th class="text-end">Cost</th><th class="text-end">Margin</th><th class="text-end">Margin %</th><th class="text-end">Cost budget</th><th class="text-end">Over budget</th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.code }}</td><td>{{ row.name }}</td><td class="text-capitalize">{{ row.status.replace('_', ' ') }}</td>
                        <td class="text-end">{{ money(row.revenue) }}</td><td class="text-end">{{ money(row.cost) }}</td><td class="text-end">{{ money(row.margin) }}</td>
                        <td class="text-end">{{ row.margin_percent ?? '-' }}</td><td class="text-end">{{ money(row.budget_cost) }}</td><td class="text-end">{{ row.cost_over_budget === null ? '-' : money(row.cost_over_budget) }}</td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="9" class="text-center text-muted">No projects yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
