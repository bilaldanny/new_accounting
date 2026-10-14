<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Lead Allocation',
            subtitle: 'Who a new lead goes to, and follow-up reminders',
            breadcrumbs: [{ title: 'Lead Allocation', href: 'NULL' }],
        },
    });

    type Rule = { id: number; name: string; strategy: string; lead_source_id: number | null; branch_id: number | null; user_ids: number[]; priority: number; is_active: boolean };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rules = ref<Rule[]>([]);
    const form = ref({ name: '', strategy: 'round_robin', lead_source_id: '' as number | '', branch_id: '' as number | '', users: '', priority: 100 });
    const message = ref('');

    async function load() {
        rules.value = (await run(() => window.axios.get('/api/crm/allocation-rules', { params: scope() })))?.data?.data ?? [];
    }

    async function save() {
        const body = {
            ...scope(), name: form.value.name, strategy: form.value.strategy, priority: form.value.priority,
            lead_source_id: form.value.lead_source_id || null, branch_id: form.value.branch_id || null,
            user_ids: form.value.users.split(/[\s,]+/).filter(Boolean).map(Number),
        };

        if (await run(() => window.axios.post('/api/crm/allocation-rules', body), 'Rule saved')) {
            form.value.name = '';
            await load();
        }
    }

    async function remove(rule: Rule) {
        if (await run(() => window.axios.delete(`/api/crm/allocation-rules/${rule.id}`, { params: scope() }), 'Rule removed')) {
            await load();
        }
    }

    async function allocate() {
        message.value = (await run(() => window.axios.post('/api/crm/allocate-unassigned', scope())))?.data?.message ?? '';
    }

    async function remind() {
        const response = await run(() => window.axios.post('/api/crm/run-reminders', scope()));
        message.value = `Reminded ${response?.data?.data?.reminded ?? 0} follow-up(s).`;
    }

    onMounted(load);
</script>

<template>
    <Head title="Lead Allocation" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        <div v-if="message" class="alert alert-info py-2 small">{{ message }}</div>

        <div v-if="can('/leads/:id/edit')" class="border rounded p-3 mb-4">
            <h6>New rule</h6>
            <div class="row g-2">
                <div class="col-md-3"><input v-model="form.name" class="form-control form-control-sm" placeholder="Name" aria-label="Name" /></div>
                <div class="col-md-2"><select v-model="form.strategy" class="form-select form-select-sm" aria-label="Strategy"><option value="round_robin">Round robin</option><option value="by_source">By lead source</option><option value="by_branch">By branch</option></select></div>
                <div v-if="form.strategy === 'by_source'" class="col-md-2"><input v-model.number="form.lead_source_id" type="number" class="form-control form-control-sm" placeholder="Lead source id" aria-label="Lead source id" /></div>
                <div v-if="form.strategy === 'by_branch'" class="col-md-2"><input v-model.number="form.branch_id" type="number" class="form-control form-control-sm" placeholder="Branch id" aria-label="Branch id" /></div>
                <div class="col-md-3"><input v-model="form.users" class="form-control form-control-sm" placeholder="User ids, comma separated" aria-label="User ids" /></div>
                <div class="col-md-1"><input v-model.number="form.priority" type="number" class="form-control form-control-sm" aria-label="Priority" /></div>
                <div class="col-md-1"><button type="button" class="btn btn-dark btn-sm" @click="save">Save</button></div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="button" class="btn btn-outline-dark btn-sm" @click="allocate">Allocate unassigned leads now</button>
                <button v-if="can('/activities')" type="button" class="btn btn-outline-dark btn-sm" @click="remind">Send follow-up reminders now</button>
            </div>
        </div>

        <ClientTable empty-text="No allocation rules: new leads stay unassigned."  :columns="[{ key: 'c0', label: 'Priority' }, { key: 'c1', label: 'Name' }, { key: 'c2', label: 'Strategy' }, { key: 'c3', label: 'Source' }, { key: 'c4', label: 'Branch' }, { key: 'c5', label: 'Pool' }, { key: 'c6', label: '' }]" :rows="(rules).map((rule) => ({ __s: { rule }, c0: (rule.priority), c1: (rule.name), c2: (rule.strategy.replace('_', ' ')), c3: (rule.lead_source_id ?? '-'), c4: (rule.branch_id ?? '-'), c5: (rule.user_ids.join(', ')), c6: '' }))" export-name="priority">
<template #cell-c6="{ row: __m }"><template v-for="{ rule } in [__m.__s]" :key="0"><button v-if="can('/leads/:id/edit')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(rule)">Remove</button></template></template>
</ClientTable>
    </div></div></div>
</template>
