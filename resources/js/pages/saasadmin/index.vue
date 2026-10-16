<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'SaaS Admin',
            subtitle: 'Plan features, add-ons, partners, usage and billing runs',
            breadcrumbs: [{ title: 'SaaS Admin', href: 'NULL' }],
        },
    });

    type Plan = { id: number; name: string; code: string; price: number; features: string[] | null; settings: Record<string, unknown> | null };

    const { money, run } = useCloseout();
    const features = ref<{ key: string; label: string }[]>([]);
    const plans = ref<Plan[]>([]);
    const partners = ref<{ id: number; name: string; commission_percent: number }[]>([]);
    const addons = ref<{ id: number; name: string; code: string; price: number }[]>([]);
    const settingsText = ref<Record<number, string>>({});
    const result = ref('');
    const partner = ref({ name: '', commission_percent: 10 });
    const addon = ref({ name: '', code: '', price: 0 });
    const statement = ref({ partner_id: '' as number | '', from: '', to: '' });
    const statementRows = ref<{ invoice_no: string; company: string; paid_amount: number; commission: number }[]>([]);
    const statementTotal = ref(0);
    const assign = ref({ company_id: '' as number | '', addon_id: '' as number | '' });

    async function load() {
        const data = (await run(() => window.axios.get('/api/saas/overview')))?.data?.data;
        features.value = data?.features ?? [];
        plans.value = data?.plans ?? [];
        partners.value = data?.partners ?? [];
        addons.value = data?.addons ?? [];
        settingsText.value = Object.fromEntries(plans.value.map((plan) => [plan.id, JSON.stringify(plan.settings ?? {})]));
    }

    function toggleFeature(plan: Plan, key: string) {
        const current = plan.features ?? features.value.map((f) => f.key);
        plan.features = current.includes(key) ? current.filter((k) => k !== key) : [...current, key];
    }

    async function savePlan(plan: Plan, all = false) {
        let settings: Record<string, unknown> = {};

        try {
            settings = JSON.parse(settingsText.value[plan.id] || '{}');
        } catch {
            result.value = 'The package settings must be valid JSON.';

            return;
        }

        if (await run(() => window.axios.put(`/api/saas/plans/${plan.id}`, { features: all ? null : plan.features, settings }), 'Plan saved')) {
            await load();
        }
    }

    async function post(url: string, body: Record<string, unknown> = {}) {
        const response = await run(() => window.axios.post(url, body), 'Done');
        result.value = response?.data?.data ? JSON.stringify(response.data.data) : (response?.data?.message ?? '');
        await load();
    }

    async function getStatement() {
        const response = await run(() => window.axios.get(`/api/saas/partners/${statement.value.partner_id}/statement`, { params: { from: statement.value.from, to: statement.value.to } }));
        statementRows.value = response?.data?.data?.rows ?? [];
        statementTotal.value = response?.data?.data?.commission ?? 0;
    }

    onMounted(load);
</script>

<template>
    <Head title="SaaS Admin" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div v-if="result" class="alert alert-info py-2 small">{{ result }}</div>

        <div class="d-flex gap-2 mb-4">
            <button type="button" class="btn btn-outline-dark btn-sm" @click="post('/api/saas/usage/snapshot')">Snapshot usage now</button>
            <button type="button" class="btn btn-outline-dark btn-sm" @click="post('/api/saas/billing/run')">Run billing now (reminders, suspension)</button>
            <button type="button" class="btn btn-outline-danger btn-sm" @click="post('/api/saas/deletions/purge-due')">Purge due tenant deletions</button>
        </div>

        <h6>Plans: features and package settings</h6>
        <div v-for="plan in plans" :key="plan.id" class="border rounded p-3 mb-3">
            <div class="fw-semibold">{{ plan.name }} <span class="text-muted small">{{ plan.code }} · {{ money(plan.price) }}</span></div>
            <div class="d-flex flex-wrap gap-3 my-2">
                <label v-for="feature in features" :key="feature.key" class="small"><input type="checkbox" class="me-1" :checked="plan.features === null || plan.features.includes(feature.key)" @change="toggleFeature(plan, feature.key)" />{{ feature.label }}</label>
            </div>
            <input v-model="settingsText[plan.id]" class="form-control form-control-sm mb-2" aria-label="Package settings JSON" />
            <button type="button" class="btn btn-dark btn-sm me-2" @click="savePlan(plan)">Save</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="savePlan(plan, true)">Include every feature</button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="border rounded p-3">
                    <h6>Partners</h6>
                    <div class="input-group input-group-sm mb-2"><input v-model="partner.name" class="form-control" placeholder="Name" aria-label="Partner name" /><input v-model.number="partner.commission_percent" type="number" class="form-control" style="max-width: 6rem" aria-label="Commission %" /><button type="button" class="btn btn-dark" @click="post('/api/saas/partners', partner)">Add</button></div>
                    <div v-for="item in partners" :key="item.id" class="small">{{ item.name }}: {{ item.commission_percent }}%</div>
                    <hr />
                    <div class="row g-2">
                        <div class="col-3"><input v-model.number="statement.partner_id" type="number" class="form-control form-control-sm" placeholder="Partner id" aria-label="Partner id" /></div>
                        <div class="col-3"><input v-model="statement.from" type="date" class="form-control form-control-sm" aria-label="From" /></div>
                        <div class="col-3"><input v-model="statement.to" type="date" class="form-control form-control-sm" aria-label="To" /></div>
                        <div class="col-3"><button type="button" class="btn btn-outline-dark btn-sm" @click="getStatement">Statement</button></div>
                    </div>
                    <ClientTable
                        v-if="statementRows.length"
                        class="mt-2"
                        :columns="[{ key: 'c0', label: 'Invoice' }, { key: 'c1', label: 'Company' }, { key: 'c2', label: 'Paid' }, { key: 'c3', label: 'Commission' }]"
                        :rows="(statementRows).map((row) => ({ __s: { row }, c0: row.invoice_no, c1: row.company, c2: money(row.paid_amount), c3: money(row.commission) }))"
                        :page-size="25"
                        export-name="commission-statement"
                    >
                        <template #footer><tr class="fw-semibold"><td colspan="3">Commission</td><td class="text-end">{{ money(statementTotal) }}</td></tr></template>
                    </ClientTable>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3">
                    <h6>Add-ons</h6>
                    <div class="input-group input-group-sm mb-2"><input v-model="addon.name" class="form-control" placeholder="Name" aria-label="Add-on name" /><input v-model="addon.code" class="form-control" placeholder="Code" aria-label="Code" /><input v-model.number="addon.price" type="number" class="form-control" style="max-width: 6rem" aria-label="Price" /><button type="button" class="btn btn-dark" @click="post('/api/saas/addons', addon)">Add</button></div>
                    <div v-for="item in addons" :key="item.id" class="small">{{ item.id }}. {{ item.name }} ({{ item.code }}): {{ money(item.price) }} a period</div>
                    <hr />
                    <div class="input-group input-group-sm"><input v-model.number="assign.company_id" type="number" class="form-control" placeholder="Company id" aria-label="Company id" /><input v-model.number="assign.addon_id" type="number" class="form-control" placeholder="Add-on id" aria-label="Add-on id" /><button type="button" class="btn btn-outline-dark" @click="post(`/api/saas/companies/${assign.company_id}/addons`, { addon_id: assign.addon_id })">Assign</button></div>
                </div>
            </div>
        </div>
    </div></div></div>
</template>
