<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, reactive, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Dashboard Insights',
            subtitle: 'Top customers and products, expenses and cash movement',
            breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Insights', href: 'NULL' }],
        },
    });

    type Insights = {
        period: { from: string; to: string };
        top_customers: { name: string; amount: number; documents: number }[];
        top_products: { name: string; amount: number; quantity: number }[];
        expenses: { code: string; name: string; amount: number }[];
        cash_flow: { in: number; out: number; net: number };
    };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const data = ref<Insights | null>(null);
    const from = ref('');
    const to = ref('');
    const layout = ref<{ order: string[]; hidden: string[] }>({ order: [], hidden: [] });
    const widgets = ref<string[]>([]);

    async function load() {
        const [insights, prefs] = await Promise.all([
            run(() => window.axios.get('/api/dashboard-insights', { params: { ...scope(), from: from.value || undefined, to: to.value || undefined } })),
            run(() => window.axios.get('/api/dashboard-tools/layout')),
        ]);
        data.value = insights?.data?.data ?? null;
        layout.value = prefs?.data?.data ?? layout.value;
        widgets.value = prefs?.data?.widgets ?? [];
    }

    function exportCsv() {
        const params = new URLSearchParams({ export: 'csv', ...(from.value ? { from: from.value } : {}), ...(to.value ? { to: to.value } : {}), ...(isSuperadmin.value && companyId.value !== '' ? { company_id: String(companyId.value) } : {}) });
        window.location.href = `/api/dashboard-insights?${params.toString()}`;
    }

    function toggle(widget: string) {
        layout.value.hidden = layout.value.hidden.includes(widget) ? layout.value.hidden.filter((item) => item !== widget) : [...layout.value.hidden, widget];
    }

    function move(widget: string, by: -1 | 1) {
        const order = [...layout.value.order];
        const index = order.indexOf(widget);
        const target = index + by;

        if (index < 0 || target < 0 || target >= order.length) {
            return;
        }

        [order[index], order[target]] = [order[target], order[index]];
        layout.value.order = order;
    }

    async function saveLayout() {
        await run(() => window.axios.put('/api/dashboard-preferences', layout.value), 'Layout saved');
    }

    /* KPI widgets: a figure of a report the user may open, for a period */
    type Kpi = { id: number; label: string; report_key: string; figure_key: string; period: string; value: number | null };
    const kpis = ref<Kpi[]>([]);
    const catalog = ref<{ key: string; title: string }[]>([]);
    const figures = ref<string[]>([]);
    const kpiForm = reactive({ report_key: '', figure_key: '', period: 'month', label: '' });

    async function loadKpis() {
        catalog.value = (await run(() => window.axios.get('/api/dashboard-tools/kpi-catalog')))?.data?.data ?? [];
        kpis.value = (await run(() => window.axios.get('/api/dashboard-tools/kpis', { params: scope() })))?.data?.data ?? [];
    }

    async function pickReport() {
        kpiForm.figure_key = '';
        figures.value = kpiForm.report_key === '' ? [] : ((await run(() => window.axios.get(`/api/dashboard-tools/kpi-figures/${kpiForm.report_key}`, { params: scope() })))?.data?.data ?? []);
    }

    async function addKpi() {
        if (await run(() => window.axios.post('/api/dashboard-tools/kpis', { ...scope(), ...kpiForm }), 'Widget added')) {
            await loadKpis();
        }
    }

    async function removeKpi(id: number) {
        if (await run(() => window.axios.delete(`/api/dashboard-tools/kpis/${id}`), 'Widget removed')) {
            await loadKpis();
        }
    }

    /* The daily business summary (company admins) */
    const summary = reactive({ enabled: false, send_hour: 20, channels: ['email'] as string[], recipients: '' });
    const summaryRuns = ref<{ run_date: string; channel: string; recipient: string; status: string; error: string | null }[]>([]);
    const summaryText = ref('');

    async function loadSummary() {
        if (! can('/dashboard/summary')) {
            return;
        }

        const data = (await run(() => window.axios.get('/api/dashboard-tools/summary', { params: scope() })))?.data?.data;

        if (data) {
            Object.assign(summary, { enabled: Boolean(data.enabled), send_hour: Number(data.send_hour), channels: data.channels ?? ['email'], recipients: (data.recipients ?? []).join(', ') });
            summaryRuns.value = data.runs ?? [];
        }
    }

    const summaryBody = () => ({ ...scope(), enabled: summary.enabled, send_hour: summary.send_hour, channels: summary.channels, recipients: summary.recipients.split(/[,;\n]+/).map((x) => x.trim()).filter(Boolean) });

    async function saveSummary() {
        if (await run(() => window.axios.put('/api/dashboard-tools/summary', summaryBody()), 'Summary settings saved')) {
            await loadSummary();
        }
    }

    async function previewSummary() {
        summaryText.value = (await run(() => window.axios.get('/api/dashboard-tools/summary/preview', { params: scope() })))?.data?.data?.text ?? '';
    }

    async function sendSummary() {
        const result = (await run(() => window.axios.post('/api/dashboard-tools/summary/send', scope())))?.data?.data;
        summaryText.value = result ? `Sent ${result.sent}, failed ${result.failed}, already sent today ${result.skipped}.` : '';
        await loadSummary();
    }

    /* Layouts of the roles of the company (company admins) */
    type RoleLayout = { role_id: number; name: string; shown: string[] | null; preset_kind: string | null; preset: string[] | null };
    const roleLayouts = ref<RoleLayout[]>([]);

    async function loadRoleLayouts() {
        if (! can('/dashboard/layouts')) {
            return;
        }

        roleLayouts.value = (await run(() => window.axios.get('/api/dashboard-tools/role-layouts', { params: scope() })))?.data?.data ?? [];
    }

    const roleShown = (role: RoleLayout): string[] => role.shown ?? role.preset ?? widgets.value;

    async function toggleRoleWidget(role: RoleLayout, widget: string) {
        const current = roleShown(role);
        const next = current.includes(widget) ? current.filter((w) => w !== widget) : [...current, widget];

        if (await run(() => window.axios.put(`/api/dashboard-tools/role-layouts/${role.role_id}`, { ...scope(), shown: next }), 'Layout of the role saved')) {
            await loadRoleLayouts();
        }
    }

    async function resetRole(role: RoleLayout) {
        if (await run(() => window.axios.delete(`/api/dashboard-tools/role-layouts/${role.role_id}`, { params: scope() }), 'Back to the built-in layout')) {
            await loadRoleLayouts();
        }
    }

    onMounted(() => {
        void load();
        void loadKpis();
        void loadSummary();
        void loadRoleLayouts();
    });
</script>

<template>
    <Head title="Dashboard Insights" />

    <div class="container-fluid py-3">
        <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm mb-3" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
        <div class="row g-2 align-items-end mb-3">
            <div class="col-auto"><label class="form-label small" for="di-from">From</label><input id="di-from" v-model="from" type="date" class="form-control form-control-sm" @change="load" /></div>
            <div class="col-auto"><label class="form-label small" for="di-to">To</label><input id="di-to" v-model="to" type="date" class="form-control form-control-sm" @change="load" /></div>
            <div class="col-auto"><button type="button" class="btn btn-outline-dark btn-sm" @click="exportCsv">Export CSV</button></div>
        </div>

        <div v-if="data" class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Top customers</h6>
                    <table class="table table-sm mb-0"><tbody>
                        <tr v-for="row in data.top_customers" :key="row.name"><td>{{ row.name }}</td><td class="text-muted">{{ row.documents }} sales</td><td class="text-end">{{ money(row.amount) }}</td></tr>
                        <tr v-if="! data.top_customers.length"><td class="text-muted">No sales in this period.</td></tr>
                    </tbody></table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Top products</h6>
                    <table class="table table-sm mb-0"><tbody>
                        <tr v-for="row in data.top_products" :key="row.name"><td>{{ row.name }}</td><td class="text-muted">{{ row.quantity }} sold</td><td class="text-end">{{ money(row.amount) }}</td></tr>
                        <tr v-if="! data.top_products.length"><td class="text-muted">No sales in this period.</td></tr>
                    </tbody></table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Expenses</h6>
                    <table class="table table-sm mb-0"><tbody>
                        <tr v-for="row in data.expenses" :key="row.code"><td>{{ row.code }}</td><td>{{ row.name }}</td><td class="text-end">{{ money(row.amount) }}</td></tr>
                        <tr v-if="! data.expenses.length"><td class="text-muted">No expenses in this period.</td></tr>
                    </tbody></table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Cash and bank</h6>
                    <div class="d-flex justify-content-between"><span>In</span><strong>{{ money(data.cash_flow.in) }}</strong></div>
                    <div class="d-flex justify-content-between"><span>Out</span><strong>{{ money(data.cash_flow.out) }}</strong></div>
                    <div class="d-flex justify-content-between"><span>Net</span><strong>{{ money(data.cash_flow.net) }}</strong></div>
                </div>
            </div>
        </div>

        <div class="border rounded p-3">
            <h6>My dashboard layout</h6>
            <p class="small text-muted">Choose the cards you see and their order; it is saved for your sign-in only.</p>
            <div v-for="widget in layout.order" :key="widget" class="d-flex align-items-center gap-2 mb-1">
                <input type="checkbox" :checked="! layout.hidden.includes(widget)" :aria-label="`Show ${widget}`" @change="toggle(widget)" />
                <span class="text-capitalize flex-grow-1">{{ widget.replace('_', ' ') }}</span>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="move(widget, -1)">up</button>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="move(widget, 1)">down</button>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-2" @click="saveLayout">Save layout</button>
        </div>

        <div class="border rounded p-3 mt-4">
            <h6>My KPI widgets</h6>
            <p class="small text-muted">Pick a figure of any report you may open; it shows as a card on your dashboard for the period you choose.</p>
            <div class="row g-2 mb-2">
                <div class="col-md-3"><select v-model="kpiForm.report_key" class="form-select form-select-sm" aria-label="Report" @change="pickReport"><option value="">Report</option><option v-for="r in catalog" :key="r.key" :value="r.key">{{ r.title }}</option></select></div>
                <div class="col-md-3"><select v-model="kpiForm.figure_key" class="form-select form-select-sm" aria-label="Figure"><option value="">Figure</option><option v-for="f in figures" :key="f" :value="f">{{ f.replace(/_/g, ' ') }}</option></select></div>
                <div class="col-md-2"><select v-model="kpiForm.period" class="form-select form-select-sm" aria-label="Period"><option value="today">Today</option><option value="week">This week</option><option value="month">This month</option><option value="last_30">Last 30 days</option></select></div>
                <div class="col-md-2"><input v-model="kpiForm.label" class="form-control form-control-sm" placeholder="Label (optional)" aria-label="Widget label" /></div>
                <div class="col-md-2"><button type="button" class="btn btn-dark btn-sm" :disabled="! kpiForm.report_key || ! kpiForm.figure_key" @click="addKpi">Add widget</button></div>
            </div>
            <ul class="small mb-0">
                <li v-for="k in kpis" :key="k.id">{{ k.label }}: <strong>{{ k.value === null ? '-' : money(k.value) }}</strong> ({{ k.period }}) <button type="button" class="btn btn-link btn-sm py-0" @click="removeKpi(k.id)">Remove</button></li>
                <li v-if="! kpis.length" class="text-muted">No widgets yet.</li>
            </ul>
        </div>

        <div v-if="can('/dashboard/summary')" class="border rounded p-3 mt-4">
            <h6>Daily business summary</h6>
            <p class="small text-muted">Sales, purchases, money received and paid, expense vouchers and the best selling products of the day, sent to the people below by email, WhatsApp or SMS (through Messaging Settings). A person gets a day once.</p>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-md-2 form-check ms-2"><input id="ds-on" v-model="summary.enabled" type="checkbox" class="form-check-input" /><label class="form-check-label small" for="ds-on">Send every day</label></div>
                <div class="col-md-2"><label class="form-label small" for="ds-hour">From hour</label><input id="ds-hour" v-model.number="summary.send_hour" type="number" min="0" max="23" class="form-control form-control-sm" /></div>
                <div class="col-md-3 small">
                    <label v-for="c in ['email', 'whatsapp', 'sms']" :key="c" class="me-3"><input v-model="summary.channels" type="checkbox" :value="c" class="me-1" />{{ c }}</label>
                </div>
                <div class="col-md-5"><label class="form-label small" for="ds-to">Email addresses and phone numbers (comma separated)</label><input id="ds-to" v-model="summary.recipients" class="form-control form-control-sm" /></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm" @click="saveSummary">Save</button>
            <button type="button" class="btn btn-outline-dark btn-sm ms-2" @click="previewSummary">Preview today</button>
            <button type="button" class="btn btn-outline-dark btn-sm ms-2" @click="sendSummary">Send now</button>
            <pre v-if="summaryText" class="small border rounded p-2 mt-2 mb-0">{{ summaryText }}</pre>
            <ul class="small mt-2 mb-0"><li v-for="r in summaryRuns" :key="r.run_date + r.channel + r.recipient">{{ r.run_date }} {{ r.channel }} to {{ r.recipient }}: {{ r.status }}<span v-if="r.error"> ({{ r.error }})</span></li></ul>
        </div>

        <div v-if="can('/dashboard/layouts')" class="border rounded p-3 mt-4">
            <h6>Dashboard layout of each role</h6>
            <p class="small text-muted">What a person of the role sees until they choose their own layout. A card still needs the person's permission for its figures. Without a setting, a role named like an owner, accountant, sales manager, warehouse incharge or cashier starts with a built-in layout.</p>
            <div v-for="role in roleLayouts" :key="role.role_id" class="mb-3">
                <div class="d-flex align-items-center gap-2"><strong>{{ role.name }}</strong><span v-if="role.shown" class="badge text-bg-dark">company layout</span><span v-else-if="role.preset_kind" class="badge text-bg-secondary">built-in: {{ role.preset_kind.replace('_', ' ') }}</span><span v-else class="badge text-bg-light">all cards</span>
                    <button v-if="role.shown" type="button" class="btn btn-link btn-sm py-0" @click="resetRole(role)">Back to built-in</button></div>
                <div class="d-flex flex-wrap gap-3 small">
                    <label v-for="w in widgets" :key="w" class="text-capitalize"><input type="checkbox" class="me-1" :checked="roleShown(role).includes(w)" @change="toggleRoleWidget(role, w)" />{{ w.replace('_', ' ') }}</label>
                </div>
            </div>
            <div v-if="! roleLayouts.length" class="text-muted small">This company has no roles yet.</div>
        </div>
    </div>
</template>
