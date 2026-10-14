<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, reactive, ref } from 'vue';
    import useCloseout from '@/composables/closeout';
    import { currentLocale, rememberLocale } from '@/lang';
    import type { Locale } from '@/lang';

    defineOptions({
        layout: {
            title: 'Business Settings',
            subtitle: 'Number format, POS, language, notifications, branches, rates, data and retention',
            breadcrumbs: [{ title: 'Settings', href: 'NULL' }, { title: 'Business Settings', href: 'NULL' }],
        },
    });

    type Branch = { id: number; name: string; code: string; parent_id: number | null; depth: number };
    type Template = { kind: string; title: string | null; body: string | null; is_active: boolean };
    type Run = { id: number; entity: string; source: string; file_name: string | null; status: string; created: number; created_at: string };
    type Data = {
        settings: { number_format: Record<string, string | number>; pos: Record<string, string | boolean>; language: { default: string } };
        sample: { positive: string; negative: string };
        summary: { company: Record<string, string> | null; regional: Record<string, string> | null; links: Record<string, string> };
        branches: Branch[];
        notification_kinds: { kind: string; label: string }[];
        notification_templates: Template[];
        rate_feed: { url: string; rates_path: string; is_active: boolean; has_token: boolean; last_sync_at: string | null; last_status: string | null } | null;
        retention: { entity: string; keep_days: number; archive: boolean; is_active: boolean; last_run_at: string | null; last_removed: number }[];
        retention_entities: { entity: string; label: string }[];
        retention_min_days: number;
    };

    const { companyId, isSuperadmin, scope, can, run, Notify } = useCloseout();
    const canManage = computed(() => can('/businesssettings/manage'));
    const canImport = computed(() => can('/businesssettings/import'));
    const tab = ref<'general' | 'notifications' | 'branches' | 'rates' | 'retention' | 'data'>('general');
    const data = ref<Data | null>(null);
    const history = ref<Run[]>([]);
    const myLocale = ref<Locale>(currentLocale());

    const feed = reactive({ url: '', rates_path: 'rates', token: '', is_active: true });
    const retention = reactive({ entity: 'audit_logs', keep_days: 365, archive: true });
    const migration = reactive({ source: 'template', kind: 'customer', csv: '', result: null as null | Record<string, unknown> });
    const pushState = ref('');

    async function load() {
        const response = await run(() => window.axios.get('/api/business-settings', { params: scope() }));
        data.value = response?.data?.data ?? null;

        if (data.value?.rate_feed) {
            Object.assign(feed, { url: data.value.rate_feed.url, rates_path: data.value.rate_feed.rates_path, is_active: data.value.rate_feed.is_active, token: '' });
        }

        if (canImport.value) {
            const h = await run(() => window.axios.get('/api/data-center/history', { params: scope() }));
            history.value = h?.data?.data ?? [];
        }
    }

    async function save() {
        if (!data.value) {
            return;
        }

        await run(() => window.axios.put('/api/business-settings', { ...scope(), ...data.value!.settings }), 'Settings saved');
        await load();
    }

    async function moveBranch(branch: Branch, parent: string) {
        await run(() => window.axios.put(`/api/business-settings/branches/${branch.id}/parent`, { ...scope(), parent_id: parent === '' ? null : Number(parent) }), 'Branch moved');
        await load();
    }

    function templateFor(kind: string): Template {
        return data.value?.notification_templates.find((item) => item.kind === kind) ?? { kind, title: '', body: '', is_active: true };
    }

    async function saveTemplate(template: Template) {
        await run(() => window.axios.put(`/api/business-settings/notification-templates/${template.kind}`, { ...scope(), title: template.title, body: template.body, is_active: template.is_active }), 'Notification wording saved');
        await load();
    }

    async function clearTemplate(kind: string) {
        await run(() => window.axios.delete(`/api/business-settings/notification-templates/${kind}`, { params: scope() }), 'Back to the standard wording');
        await load();
    }

    async function saveFeed() {
        await run(() => window.axios.put('/api/business-settings/rate-feed', { ...scope(), ...feed }), 'Rate feed saved');
        await load();
    }

    async function syncNow() {
        await run(() => window.axios.post('/api/business-settings/rate-feed/sync', { ...scope() }), 'Rates synced');
        await load();
    }

    async function saveRetention() {
        await run(() => window.axios.put('/api/business-settings/retention', { ...scope(), ...retention }), 'Retention rule saved');
        await load();
    }

    async function removeRetention(entity: string) {
        await run(() => window.axios.delete(`/api/business-settings/retention/${entity}`, { params: scope() }), 'Rule removed');
        await load();
    }

    function download(path: string, format: 'csv' | 'xlsx') {
        const query = new URLSearchParams({ format, ...(isSuperadmin.value && companyId.value !== '' ? { company_id: String(companyId.value) } : {}) });
        window.location.href = `/api/data-center/${path}?${query.toString()}`;
    }

    async function migrate(commit: boolean) {
        const response = await run(() => window.axios.post('/api/data-center/migrate', { ...scope(), source: migration.source, kind: migration.kind, csv: migration.csv, commit }), commit ? 'Imported' : undefined);
        migration.result = response?.data ?? null;

        if (commit && response) {
            await load();
        }
    }

    async function onFile(event: Event) {
        const file = (event.target as HTMLInputElement).files?.[0];
        migration.csv = file ? await file.text() : '';
    }

    async function undo(item: Run) {
        await run(() => window.axios.post(`/api/data-center/history/${item.id}/undo`, { ...scope() }));
        await load();
    }

    async function enablePush() {
        try {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                pushState.value = 'This browser does not support push notifications.';

                return;
            }

            const key = await run(() => window.axios.get('/api/push/key'));
            const publicKey: string | null = key?.data?.data?.public_key ?? null;

            if (!publicKey) {
                pushState.value = 'Push notifications are not set up on this server yet (the administrator has to generate the keys).';

                return;
            }

            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                pushState.value = 'Notifications were not allowed in this browser.';

                return;
            }

            const registration = await navigator.serviceWorker.register('/push-sw.js', { scope: '/push-scope/' });
            await navigator.serviceWorker.ready;
            const padding = '='.repeat((4 - (publicKey.length % 4)) % 4);
            const raw = atob((publicKey + padding).replace(/-/g, '+').replace(/_/g, '/'));
            const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: Uint8Array.from(raw, (c) => c.charCodeAt(0)) });
            await run(() => window.axios.post('/api/push/subscribe', subscription.toJSON()), 'Notifications are on for this browser');
            pushState.value = 'On for this browser.';
        } catch (error: unknown) {
            pushState.value = error instanceof Error ? error.message : 'Could not turn notifications on.';
            Notify(pushState.value, 'alert');
        }
    }

    function chooseLocale() {
        rememberLocale(myLocale.value);
    }

    onMounted(load);
</script>

<template>
    <Head title="Business Settings" />

    <div class="container-fluid py-3">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <ul class="nav nav-tabs mb-3">
            <li v-for="item in [['general', 'General'], ['notifications', 'Notifications'], ['branches', 'Branches'], ['rates', 'Exchange rates'], ['retention', 'Retention'], ['data', 'Import and export']]" :key="item[0]" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: tab === item[0] }" @click="tab = item[0] as typeof tab">{{ item[1] }}</button>
            </li>
        </ul>

        <div v-if="data && tab === 'general'" class="row g-3">
            <div class="col-lg-6">
                <div class="border rounded p-3 mb-3">
                    <h6>Number format</h6>
                    <div class="row g-2">
                        <div class="col-4"><label class="form-label small" for="nf-dec">Decimals</label><input id="nf-dec" v-model.number="data.settings.number_format.decimals" type="number" min="0" max="4" class="form-control form-control-sm" :disabled="!canManage" /></div>
                        <div class="col-4"><label class="form-label small" for="nf-th">Thousands</label>
                            <select id="nf-th" v-model="data.settings.number_format.thousand" class="form-select form-select-sm" :disabled="!canManage"><option value=",">Comma</option><option value=".">Dot</option><option value=" ">Space</option><option value="">None</option></select></div>
                        <div class="col-4"><label class="form-label small" for="nf-de">Decimal</label>
                            <select id="nf-de" v-model="data.settings.number_format.decimal" class="form-select form-select-sm" :disabled="!canManage"><option value=".">Dot</option><option value=",">Comma</option></select></div>
                        <div class="col-6"><label class="form-label small" for="nf-gr">Grouping</label>
                            <select id="nf-gr" v-model="data.settings.number_format.grouping" class="form-select form-select-sm" :disabled="!canManage"><option value="western">1,234,567</option><option value="indian">12,34,567</option></select></div>
                        <div class="col-6"><label class="form-label small" for="nf-ng">Negative</label>
                            <select id="nf-ng" v-model="data.settings.number_format.negative" class="form-select form-select-sm" :disabled="!canManage"><option value="minus">-1,234.50</option><option value="brackets">(1,234.50)</option></select></div>
                    </div>
                    <div class="small text-muted mt-2">Saved format shows: <strong>{{ data.sample.positive }}</strong> and <strong>{{ data.sample.negative }}</strong></div>
                </div>

                <div class="border rounded p-3 mb-3">
                    <h6>POS</h6>
                    <label class="form-label small" for="pos-drawer">Open the cash drawer</label>
                    <select id="pos-drawer" v-model="data.settings.pos.cash_drawer" class="form-select form-select-sm mb-2" :disabled="!canManage"><option value="never">Never</option><option value="cash_sale">On a cash sale</option><option value="any_sale">On every sale</option></select>
                    <label class="form-label small" for="pos-pulse">Drawer pulse command</label>
                    <input id="pos-pulse" v-model="data.settings.pos.cash_drawer_pulse" type="text" class="form-control form-control-sm mb-2" maxlength="40" :disabled="!canManage" />
                    <label class="form-check small"><input v-model="data.settings.pos.require_open_shift" type="checkbox" class="form-check-input" :disabled="!canManage" /> A shift must be open to ring up a sale</label>
                    <div class="small text-muted mt-2">The drawer setting is stored and offered to the POS; opening the drawer needs a printer or drawer connected to the till (check it on the first device).</div>
                </div>

                <div class="border rounded p-3">
                    <h6>Language</h6>
                    <label class="form-label small" for="lang-default">Company default</label>
                    <select id="lang-default" v-model="data.settings.language.default" class="form-select form-select-sm mb-2" :disabled="!canManage"><option value="en">English</option><option value="ur">Urdu</option></select>
                    <label class="form-label small" for="lang-mine">My language (this browser)</label>
                    <select id="lang-mine" v-model="myLocale" class="form-select form-select-sm" @change="chooseLocale"><option value="en">English</option><option value="ur">Urdu</option></select>
                    <div class="small text-muted mt-2">The language framework and an Urdu word list are in place; menu names and most pages are still English.</div>
                </div>

                <button v-if="canManage" type="button" class="btn btn-dark btn-sm mt-3" @click="save">Save settings</button>
            </div>

            <div class="col-lg-6">
                <div class="border rounded p-3 mb-3">
                    <h6>Where the other settings live</h6>
                    <ul class="mb-0 small">
                        <li>Company: <a :href="data.summary.links.company">{{ data.summary.company?.name }}</a> ({{ data.summary.company?.code }})</li>
                        <li>Dates, time and currency: {{ data.summary.regional?.date_format ?? '-' }}, {{ data.summary.regional?.time_format ?? '-' }}, {{ data.summary.regional?.currency_code ?? 'not set' }} - <a :href="data.summary.links.company">company settings</a></li>
                        <li><a :href="data.summary.links.invoice">Invoice</a>, <a :href="data.summary.links.receipt">receipt</a> and <a :href="data.summary.links.barcode">barcode</a> printing, <a :href="data.summary.links.checkout">checkout extras</a></li>
                        <li><a :href="data.summary.links.messaging">WhatsApp and SMS gateway</a>, <a :href="data.summary.links.exchange_rates">exchange rates</a>, <a href="/documentcenter">document center</a></li>
                    </ul>
                </div>
                <div class="border rounded p-3">
                    <h6>Push notifications</h6>
                    <p class="small text-muted">Get the in-app notifications as browser notifications on this device.</p>
                    <button type="button" class="btn btn-outline-dark btn-sm" @click="enablePush">Turn on for this browser</button>
                    <div v-if="pushState" class="small mt-2">{{ pushState }}</div>
                </div>
            </div>
        </div>

        <div v-if="data && tab === 'notifications'" class="border rounded p-3">
            <h6>Notification wording</h6>
            <p class="small text-muted">Wrap the application's text: <code>{title}</code> and <code>{body}</code> are what it wrote, <code>{url}</code> the page it opens, <code>{company}</code> your company. Leave empty for the standard wording.</p>
            <div v-for="kind in data.notification_kinds" :key="kind.kind" class="border-bottom py-2">
                <strong>{{ kind.label }}</strong>
                <div class="row g-2 mt-1">
                    <div class="col-md-4"><input :value="templateFor(kind.kind).title" type="text" class="form-control form-control-sm" placeholder="Title, e.g. [{company}] {title}" :aria-label="`Title for ${kind.label}`" :disabled="!canManage" @input="(e) => { const t = templateFor(kind.kind); t.title = (e.target as HTMLInputElement).value; if (!data!.notification_templates.includes(t)) data!.notification_templates.push(t); }" /></div>
                    <div class="col-md-6"><input :value="templateFor(kind.kind).body" type="text" class="form-control form-control-sm" placeholder="Text, e.g. {body} - contact finance." :aria-label="`Text for ${kind.label}`" :disabled="!canManage" @input="(e) => { const t = templateFor(kind.kind); t.body = (e.target as HTMLInputElement).value; if (!data!.notification_templates.includes(t)) data!.notification_templates.push(t); }" /></div>
                    <div v-if="canManage" class="col-md-2 d-flex gap-1"><button type="button" class="btn btn-outline-dark btn-sm" @click="saveTemplate(templateFor(kind.kind))">Save</button><button type="button" class="btn btn-outline-secondary btn-sm" @click="clearTemplate(kind.kind)">Reset</button></div>
                </div>
            </div>
        </div>

        <div v-if="data && tab === 'branches'" class="border rounded p-3">
            <h6>Branch hierarchy</h6>
            <p class="small text-muted">Place branches under head offices or regions. Reports and permissions still work per branch.</p>
            <div v-for="branch in data.branches" :key="branch.id" class="d-flex align-items-center gap-2 mb-1" :style="{ paddingInlineStart: `${branch.depth * 1.5}rem` }">
                <span class="flex-grow-1">{{ branch.name }} <span class="text-muted small">{{ branch.code }}</span></span>
                <select v-if="canManage" class="form-select form-select-sm w-auto" :value="branch.parent_id ?? ''" :aria-label="`Parent of ${branch.name}`" @change="moveBranch(branch, ($event.target as HTMLSelectElement).value)">
                    <option value="">Top level</option>
                    <option v-for="other in data.branches.filter((b) => b.id !== branch.id)" :key="other.id" :value="other.id">{{ other.name }}</option>
                </select>
            </div>
        </div>

        <div v-if="data && tab === 'rates'" class="border rounded p-3" style="max-width: 40rem">
            <h6>Exchange rate feed</h6>
            <p class="small text-muted">An https address that answers JSON with the rates (units of each currency for one unit of your base currency). Use <code>{base}</code> in the address for the base currency code. A failed feed never changes the rates you entered by hand.</p>
            <label class="form-label small" for="rf-url">Feed address</label><input id="rf-url" v-model="feed.url" type="text" class="form-control form-control-sm mb-2" placeholder="https://rates.example.com/latest?base={base}" :disabled="!canManage" />
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label small" for="rf-path">Rates are under</label><input id="rf-path" v-model="feed.rates_path" type="text" class="form-control form-control-sm" :disabled="!canManage" /></div>
                <div class="col-6"><label class="form-label small" for="rf-token">Access token (optional)</label><input id="rf-token" v-model="feed.token" type="password" autocomplete="off" class="form-control form-control-sm" :placeholder="data.rate_feed?.has_token ? 'saved' : ''" :disabled="!canManage" /></div>
            </div>
            <label class="form-check small mb-2"><input v-model="feed.is_active" type="checkbox" class="form-check-input" :disabled="!canManage" /> Sync every day</label>
            <div v-if="canManage" class="d-flex gap-2"><button type="button" class="btn btn-dark btn-sm" :disabled="!feed.url" @click="saveFeed">Save</button><button type="button" class="btn btn-outline-dark btn-sm" :disabled="!data.rate_feed" @click="syncNow">Sync now</button></div>
            <div v-if="data.rate_feed?.last_status" class="small mt-2">Last sync {{ data.rate_feed.last_sync_at }}: {{ data.rate_feed.last_status }}</div>
        </div>

        <div v-if="data && tab === 'retention'" class="row g-3">
            <div v-if="canManage" class="col-lg-5">
                <div class="border rounded p-3">
                    <h6>Keep logs for a limited time</h6>
                    <label class="form-label small" for="rt-entity">Log</label>
                    <select id="rt-entity" v-model="retention.entity" class="form-select form-select-sm mb-2"><option v-for="e in data.retention_entities" :key="e.entity" :value="e.entity">{{ e.label }}</option></select>
                    <label class="form-label small" for="rt-days">Keep for (days, at least {{ data.retention_min_days }})</label>
                    <input id="rt-days" v-model.number="retention.keep_days" type="number" :min="data.retention_min_days" class="form-control form-control-sm mb-2" />
                    <label class="form-check small mb-2"><input v-model="retention.archive" type="checkbox" class="form-check-input" /> Save the removed rows to an archive file first</label>
                    <button type="button" class="btn btn-dark btn-sm" @click="saveRetention">Save rule</button>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="border rounded p-3">
                    <h6>Rules</h6>
                    <div v-for="rule in data.retention" :key="rule.entity" class="d-flex gap-2 align-items-center mb-1"><span class="flex-grow-1">{{ rule.entity }} - {{ rule.keep_days }} days {{ rule.archive ? '(archived)' : '' }} <span class="text-muted small">last run {{ rule.last_run_at ?? 'never' }}, {{ rule.last_removed }} removed</span></span>
                        <button v-if="canManage" type="button" class="btn btn-outline-danger btn-sm py-0" @click="removeRetention(rule.entity)">Remove</button></div>
                    <div v-if="!data.retention.length" class="text-muted small">No rules: nothing is removed. Business documents, vouchers and stock are never covered.</div>
                </div>
            </div>
        </div>

        <div v-if="tab === 'data' && canImport" class="row g-3">
            <div class="col-lg-5">
                <div class="border rounded p-3 mb-3">
                    <h6>Export</h6>
                    <div v-for="entity in ['customers', 'suppliers', 'products', 'chart_of_accounts']" :key="entity" class="d-flex gap-2 align-items-center mb-1">
                        <span class="flex-grow-1 text-capitalize">{{ entity.replace('_', ' ') }}</span>
                        <button type="button" class="btn btn-outline-dark btn-sm py-0" @click="download(`export/${entity}`, 'csv')">CSV</button>
                        <button type="button" class="btn btn-outline-dark btn-sm py-0" @click="download(`export/${entity}`, 'xlsx')">Excel</button>
                    </div>
                    <h6 class="mt-3">Import templates</h6>
                    <div v-for="entity in ['customers', 'suppliers']" :key="entity" class="d-flex gap-2 align-items-center mb-1">
                        <span class="flex-grow-1 text-capitalize">{{ entity }}</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="download(`template/${entity}`, 'csv')">CSV</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="download(`template/${entity}`, 'xlsx')">Excel</button>
                    </div>
                    <div class="small text-muted mt-2">Sales and purchases are imported from <a href="/salesimport">Sales Import</a> and <a href="/importpurchase">Import Purchase</a>.</div>
                </div>

                <div class="border rounded p-3">
                    <h6>Import history</h6>
                    <div v-for="item in history" :key="item.id" class="d-flex gap-2 align-items-center mb-1 small">
                        <span class="flex-grow-1">{{ item.created_at }} - {{ item.created }} {{ item.entity }} ({{ item.source }}) <span class="badge text-bg-secondary">{{ item.status }}</span></span>
                        <button v-if="item.status === 'active'" type="button" class="btn btn-outline-danger btn-sm py-0" @click="undo(item)">Undo</button>
                    </div>
                    <div v-if="!history.length" class="text-muted small">No imports yet.</div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="border rounded p-3">
                    <h6>Move contacts in from a file</h6>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small" for="mg-source">File from</label><select id="mg-source" v-model="migration.source" class="form-select form-select-sm"><option value="template">Our template</option><option value="tally">Tally ledger export (CSV)</option><option value="quickbooks">QuickBooks contact list (CSV)</option></select></div>
                        <div v-if="migration.source !== 'tally'" class="col-6"><label class="form-label small" for="mg-kind">They are</label><select id="mg-kind" v-model="migration.kind" class="form-select form-select-sm"><option value="customer">Customers</option><option value="supplier">Suppliers</option></select></div>
                    </div>
                    <input type="file" accept=".csv,text/csv" class="form-control form-control-sm mb-2" aria-label="CSV file" @change="onFile" />
                    <div class="d-flex gap-2"><button type="button" class="btn btn-outline-dark btn-sm" :disabled="!migration.csv" @click="migrate(false)">Check the file</button><button type="button" class="btn btn-dark btn-sm" :disabled="!migration.csv" @click="migrate(true)">Import</button></div>
                    <pre v-if="migration.result" class="small mt-3 mb-0" style="white-space: pre-wrap">{{ JSON.stringify(migration.result, null, 2) }}</pre>
                    <div class="small text-muted mt-2">Tally and QuickBooks column layouts vary by version and report; check the file first and read the unmatched columns it lists.</div>
                </div>
            </div>
        </div>
    </div>
</template>
