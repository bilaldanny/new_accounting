<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Subscription & Billing',
            subtitle: 'Your plan, invoices, usage, data export and account closure',
            breadcrumbs: [{ title: 'Subscription & Billing', href: 'NULL' }],
        },
    });

    type Overview = {
        company: { name: string; code: string; tenant_status: string; cancel_at_period_end: boolean; current_period_ends_at: string | null; suspension_reason: string | null; brand_name: string | null; brand_color: string | null; hide_powered_by: boolean };
        plan: { name: string; price: number; billing_cycle: string; features: { key: string; label: string; enabled: boolean }[] } | null;
        invoices: { id: number; invoice_no: string; period_start: string; period_end: string; total_amount: number; addons_amount: number; status: string; due_date: string; paid_at: string | null }[];
        usage: { points: { day: string; users: number; products: number; invoices_month: number; storage_mb: number }[]; plan: Record<string, number | null> };
        onboarding: { items: { key: string; label: string; done: boolean }[]; done: number; total: number };
        addons: { id: number; name: string; price: number; quantity: number }[];
        deletion: { status: string; scheduled_for: string | null } | null;
        domains: { id: number; domain: string; verification_token: string; verified_at: string | null }[];
        exports: { id: number; size: number; created_at: string }[];
    };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const data = ref<Overview | null>(null);
    const domain = ref('');
    const confirmCode = ref('');
    const branding = ref({ brand_name: '', brand_color: '#199683', hide_powered_by: false });

    async function load() {
        data.value = (await run(() => window.axios.get('/api/tenant-billing/overview', { params: scope() })))?.data?.data ?? null;

        if (data.value) {
            branding.value = { brand_name: data.value.company.brand_name ?? '', brand_color: data.value.company.brand_color ?? '#199683', hide_powered_by: data.value.company.hide_powered_by };
        }
    }

    async function post(url: string, ok: string, body: Record<string, unknown> = {}) {
        if (await run(() => window.axios.post(url, { ...scope(), ...body }), ok)) {
            await load();
        }
    }

    async function remove(url: string) {
        if (await run(() => window.axios.delete(url, { params: scope() }), 'Removed')) {
            await load();
        }
    }

    async function saveBranding() {
        await run(() => window.axios.put('/api/tenant-billing/branding', { ...scope(), ...branding.value }), 'Branding saved');
    }

    onMounted(load);
</script>

<template>
    <Head title="Subscription & Billing" />
    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">

    <div class="container-fluid py-3">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        <div v-if="! data" class="text-center text-muted py-5">
            <strong class="d-block">{{ isSuperadmin && companyId === '' ? 'Choose a company above to see its plan and invoices.' : 'The billing overview could not be loaded.' }}</strong>
        </div>
        <template v-if="data">

        <div v-if="data.company.tenant_status === 'suspended'" class="alert alert-danger py-2">This account is suspended: {{ data.company.suspension_reason }}. Paying the overdue invoice reactivates it.</div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small">Plan</div>
                    <div class="fs-5 fw-semibold">{{ data.plan?.name ?? 'No plan' }}</div>
                    <div class="small">Status: <span class="text-capitalize">{{ data.company.tenant_status }}</span>, period ends {{ data.company.current_period_ends_at ?? '-' }}</div>
                    <div v-if="data.company.cancel_at_period_end" class="small text-danger">Set to end at the period end.</div>
                    <div v-if="can('/tenant-billing/manage')" class="mt-2">
                        <button v-if="! data.company.cancel_at_period_end" type="button" class="btn btn-outline-danger btn-sm" @click="post('/api/tenant-billing/cancel', 'Saved')">Cancel at period end</button>
                        <button v-else type="button" class="btn btn-dark btn-sm" @click="post('/api/tenant-billing/renew', 'Saved')">Keep my subscription</button>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1">Onboarding {{ data.onboarding.done }} / {{ data.onboarding.total }}</div>
                    <div v-for="item in data.onboarding.items" :key="item.key" class="small" :class="item.done ? 'text-success' : ''">{{ item.done ? '✓' : '○' }} {{ item.label }}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1">Included in your plan</div>
                    <div v-for="feature in data.plan?.features ?? []" :key="feature.key" class="small" :class="feature.enabled ? '' : 'text-muted text-decoration-line-through'">{{ feature.label }}</div>
                </div>
            </div>
        </div>

        <h6>Invoices</h6>
        <ClientTable empty-text="No invoices yet."  :columns="[{ key: 'c0', label: 'No' }, { key: 'c1', label: 'Period' }, { key: 'c2', label: 'Add-ons' }, { key: 'c3', label: 'Total' }, { key: 'c4', label: 'Due' }, { key: 'c5', label: 'Status' }]" :rows="(data.invoices).map((invoice) => ({ __s: { invoice }, c0: (invoice.invoice_no), c1: `${invoice.period_start} to ${invoice.period_end}`, c2: (money(invoice.addons_amount)), c3: (money(invoice.total_amount)), c4: (invoice.due_date), c5: (invoice.status) }))" export-name="no">
</ClientTable>

        <h6>Usage (last 30 days)</h6>
        <ClientTable  :columns="[{ key: 'c0', label: 'Day' }, { key: 'c1', label: 'Users' }, { key: 'c2', label: 'Products' }, { key: 'c3', label: 'Invoices this month' }, { key: 'c4', label: 'Storage MB' }]" :rows="(data.usage.points.slice(-10)).map((point) => ({ __s: { point }, c0: (point.day), c1: (point.users), c2: (point.products), c3: (point.invoices_month), c4: (point.storage_mb) }))" export-name="day">
</ClientTable>

        <div v-if="can('/tenant-billing/manage')" class="row g-3">
            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <h6>Your data</h6>
                    <button type="button" class="btn btn-outline-dark btn-sm mb-2" @click="post('/api/tenant-billing/exports', 'Export ready')">Export my data (zip of CSV files)</button>
                    <div v-for="item in data.exports" :key="item.id" class="small"><a :href="`/api/tenant-billing/exports/${item.id}/download`">Export {{ item.created_at.slice(0, 16) }}</a> ({{ Math.round(item.size / 1024) }} KB)</div>
                    <hr />
                    <div v-if="data.company.tenant_status === 'cancelled'">
                        <div v-if="! data.deletion || ['cancelled', 'executed'].includes(data.deletion.status)">
                            <button type="button" class="btn btn-outline-danger btn-sm" @click="post('/api/tenant-billing/deletion/request', 'Requested')">Request deletion of my data</button>
                        </div>
                        <div v-else-if="data.deletion.status === 'requested'">
                            <input v-model="confirmCode" class="form-control form-control-sm mb-2" :placeholder="`Type ${data.company.code} to confirm`" aria-label="Company code" />
                            <button type="button" class="btn btn-danger btn-sm me-2" @click="post('/api/tenant-billing/deletion/confirm', 'Confirmed', { code: confirmCode })">Confirm deletion</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" @click="post('/api/tenant-billing/deletion/cancel', 'Cancelled')">Cancel</button>
                        </div>
                        <div v-else class="small">Deletion confirmed; your data is removed on {{ data.deletion.scheduled_for }}.
                            <button type="button" class="btn btn-link btn-sm" @click="post('/api/tenant-billing/deletion/cancel', 'Cancelled')">Cancel it</button></div>
                    </div>
                    <div v-else class="small text-muted">Deletion is possible only after the subscription is cancelled.</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <h6>Branding and custom domain</h6>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><input v-model="branding.brand_name" class="form-control form-control-sm" placeholder="Brand name" aria-label="Brand name" /></div>
                        <div class="col-3"><input v-model="branding.brand_color" type="color" class="form-control form-control-sm form-control-color" aria-label="Brand colour" /></div>
                        <div class="col-3"><label class="small"><input v-model="branding.hide_powered_by" type="checkbox" class="me-1" />Hide "powered by"</label></div>
                    </div>
                    <button type="button" class="btn btn-outline-dark btn-sm mb-3" @click="saveBranding">Save branding</button>
                    <div class="input-group input-group-sm mb-2"><input v-model="domain" class="form-control" placeholder="erp.example.com" aria-label="Domain" /><button type="button" class="btn btn-dark" @click="post('/api/tenant-billing/domains', 'Domain added', { domain })">Add domain</button></div>
                    <div v-for="item in data.domains" :key="item.id" class="small mb-2">
                        <strong>{{ item.domain }}</strong> {{ item.verified_at ? '(verified)' : '(not verified)' }}
                        <div v-if="! item.verified_at" class="text-muted">Add a DNS TXT record <code>_erp-verify.{{ item.domain }}</code> with the value <code>{{ item.verification_token }}</code>, point the domain's CNAME at this application, then
                            <button type="button" class="btn btn-link btn-sm p-0" @click="post(`/api/tenant-billing/domains/${item.id}/verify`, 'Checked')">verify</button>.</div>
                        <button type="button" class="btn btn-link btn-sm text-danger p-0" @click="remove(`/api/tenant-billing/domains/${item.id}`)">remove</button>
                    </div>
                </div>
            </div>
        </div>
        </template>
    </div>
    </div></div></div>
</template>
