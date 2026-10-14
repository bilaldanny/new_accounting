<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Security Center',
            subtitle: 'Password policy, IP rules, sessions, sign-in history and personal data',
            breadcrumbs: [{ title: 'Security Center', href: 'NULL' }],
        },
    });

    type Setting = { maker_checker: boolean; policy_enabled: boolean; min_length: number; require_number: boolean; require_mixed_case: boolean; require_symbol: boolean; expiry_days: number; history_count: number };
    type IpRule = { id: number; type: string; address: string; note: string | null };
    type Session = { id: number; user_id: number; email: string; ip: string | null; device: string | null; last_activity: string | null };
    type LoginEvent = { id: number; created_at: string; event: string; identity: string | null; user_email: string | null; ip: string | null; device: string | null };
    type Request = { id: number; subject_type: string; subject_id: number; kind: string; created_at: string };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const settings = ref<Setting | null>(null);
    const rules = ref<IpRule[]>([]);
    const sessions = ref<Session[]>([]);
    const events = ref<LoginEvent[]>([]);
    const requests = ref<Request[]>([]);
    const eventFilter = ref('');
    const newRule = ref({ type: 'allow', address: '', note: '' });
    const subject = ref({ subject_type: 'contact', subject_id: '' as number | '' });
    const exported = ref('');

    async function load() {
        const [s, r, ss, ev, pr] = await Promise.all([
            run(() => window.axios.get('/api/security/settings', { params: scope() })),
            run(() => window.axios.get('/api/security/ip-rules', { params: scope() })),
            run(() => window.axios.get('/api/security/sessions', { params: scope() })),
            run(() => window.axios.get('/api/security/login-events', { params: { ...scope(), event: eventFilter.value || undefined } })),
            can('/security/privacy') ? run(() => window.axios.get('/api/security/privacy', { params: scope() })) : Promise.resolve(null),
        ]);
        settings.value = s?.data?.data ?? null;
        rules.value = r?.data?.data ?? [];
        sessions.value = ss?.data?.data ?? [];
        events.value = ev?.data?.data ?? [];
        requests.value = pr?.data?.data ?? [];
    }

    async function saveSettings() {
        if (settings.value && await run(() => window.axios.put('/api/security/settings', { ...scope(), ...settings.value }), 'Security settings saved')) {
            await load();
        }
    }

    async function addRule() {
        if (await run(() => window.axios.post('/api/security/ip-rules', { ...scope(), ...newRule.value }), 'Rule added')) {
            newRule.value = { type: 'allow', address: '', note: '' };
            await load();
        }
    }

    async function deleteRule(rule: IpRule) {
        if (await run(() => window.axios.delete(`/api/security/ip-rules/${rule.id}`, { params: scope() }), 'Rule removed')) {
            await load();
        }
    }

    async function revoke(session: Session) {
        if (await run(() => window.axios.post(`/api/security/sessions/${session.id}/revoke`, scope()), 'Session ended')) {
            await load();
        }
    }

    function exportLog() {
        const params = new URLSearchParams({ export: 'csv', ...(eventFilter.value ? { event: eventFilter.value } : {}), ...(isSuperadmin.value && companyId.value !== '' ? { company_id: String(companyId.value) } : {}) });
        window.location.href = `/api/security/login-events?${params.toString()}`;
    }

    async function exportSubject() {
        const response = await run(() => window.axios.post('/api/security/privacy/export', { ...scope(), ...subject.value }));
        exported.value = response ? JSON.stringify(response.data.data, null, 2) : '';
        await load();
    }

    async function anonymize() {
        if (window.confirm('Anonymise this person? Their name and contact details are replaced for good; invoices and payments stay.')
            && await run(() => window.axios.post('/api/security/privacy/anonymize', { ...scope(), ...subject.value, confirm: true }), 'Anonymised')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Security Center" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div v-if="settings" class="border rounded p-3 mb-4">
            <h6>Password policy and maker-checker</h6>
            <div class="row g-2 align-items-end">
                <div class="col-auto"><label class="small"><input v-model="settings.maker_checker" type="checkbox" class="me-1" />Maker-checker (nobody approves their own document)</label></div>
                <div class="col-auto"><label class="small"><input v-model="settings.policy_enabled" type="checkbox" class="me-1" />Enforce the password policy</label></div>
                <div class="col-auto"><label class="form-label small" for="sec-min">Min length</label><input id="sec-min" v-model.number="settings.min_length" type="number" class="form-control form-control-sm" style="width: 5rem" /></div>
                <div class="col-auto"><label class="small"><input v-model="settings.require_number" type="checkbox" class="me-1" />Number</label></div>
                <div class="col-auto"><label class="small"><input v-model="settings.require_mixed_case" type="checkbox" class="me-1" />Mixed case</label></div>
                <div class="col-auto"><label class="small"><input v-model="settings.require_symbol" type="checkbox" class="me-1" />Symbol</label></div>
                <div class="col-auto"><label class="form-label small" for="sec-exp">Expires after (days, 0 = never)</label><input id="sec-exp" v-model.number="settings.expiry_days" type="number" class="form-control form-control-sm" style="width: 6rem" /></div>
                <div class="col-auto"><label class="form-label small" for="sec-his">Remember last N passwords</label><input id="sec-his" v-model.number="settings.history_count" type="number" class="form-control form-control-sm" style="width: 6rem" /></div>
                <div v-if="can('/security/manage')" class="col-auto"><button type="button" class="btn btn-dark btn-sm" @click="saveSettings">Save</button></div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-5">
                <div class="border rounded p-3">
                    <h6>IP allow / deny list</h6>
                    <div v-if="can('/security/manage')" class="row g-2 mb-2">
                        <div class="col-3"><select v-model="newRule.type" class="form-select form-select-sm" aria-label="Type"><option>allow</option><option>deny</option></select></div>
                        <div class="col-5"><input v-model="newRule.address" class="form-control form-control-sm" placeholder="203.0.113.0/24" aria-label="Address" /></div>
                        <div class="col-4"><button type="button" class="btn btn-outline-dark btn-sm" @click="addRule">Add</button></div>
                    </div>
                    <table class="table table-sm mb-0"><tbody>
                        <tr v-for="rule in rules" :key="rule.id"><td class="text-capitalize">{{ rule.type }}</td><td>{{ rule.address }}</td><td class="text-end"><button v-if="can('/security/manage')" type="button" class="btn btn-link btn-sm text-danger" @click="deleteRule(rule)">remove</button></td></tr>
                        <tr v-if="! rules.length"><td class="text-muted">No rules: every address may sign in.</td></tr>
                    </tbody></table>
                </div>
            </div>
            <div class="col-md-7">
                <div class="border rounded p-3">
                    <h6>Active sessions and devices</h6>
                    <ClientTable empty-text="No sessions recorded yet."  :columns="[{ key: 'c0', label: 'User' }, { key: 'c1', label: 'Device' }, { key: 'c2', label: 'IP' }, { key: 'c3', label: 'Last active' }, { key: 'c4', label: '' }]" :rows="(sessions).map((session) => ({ __s: { session }, c0: (session.email), c1: (session.device), c2: (session.ip), c3: (session.last_activity), c4: '' }))" export-name="user">
<template #cell-c4="{ row: __m }"><template v-for="{ session } in [__m.__s]" :key="0"><button v-if="can('/security/manage')" type="button" class="btn btn-outline-danger btn-sm" @click="revoke(session)">End</button></template></template>
</ClientTable>
                </div>
            </div>
        </div>

        <div class="border rounded p-3 mb-4">
            <div class="d-flex gap-2 align-items-center mb-2">
                <h6 class="mb-0">Security log</h6>
                <select v-model="eventFilter" class="form-select form-select-sm" style="width: 10rem" aria-label="Event" @change="load"><option value="">All events</option><option>login</option><option>failed</option><option>logout</option><option>lockout</option><option>ip_blocked</option></select>
                <button type="button" class="btn btn-outline-dark btn-sm" @click="exportLog">Export CSV</button>
            </div>
            <ClientTable empty-text="Nothing logged yet."  :columns="[{ key: 'c0', label: 'Time' }, { key: 'c1', label: 'Event' }, { key: 'c2', label: 'User' }, { key: 'c3', label: 'IP' }, { key: 'c4', label: 'Device' }]" :rows="(events).map((event) => ({ __s: { event }, c0: (event.created_at), c1: (event.event.replace('_', ' ')), c2: (event.user_email ?? event.identity), c3: (event.ip), c4: (event.device) }))" export-name="time">
</ClientTable>
        </div>

        <div v-if="can('/security/privacy')" class="border rounded p-3">
            <h6>Personal data</h6>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-auto"><select v-model="subject.subject_type" class="form-select form-select-sm" aria-label="Subject"><option value="contact">Customer / supplier</option><option value="user">User</option></select></div>
                <div class="col-auto"><input v-model.number="subject.subject_id" type="number" class="form-control form-control-sm" placeholder="Id" aria-label="Subject id" /></div>
                <div class="col-auto"><button type="button" class="btn btn-outline-dark btn-sm" @click="exportSubject">Export data</button></div>
                <div class="col-auto"><button type="button" class="btn btn-outline-danger btn-sm" @click="anonymize">Anonymise</button></div>
            </div>
            <pre v-if="exported" class="small border rounded p-2" style="max-height: 16rem; overflow: auto">{{ exported }}</pre>
            <table class="table table-sm mb-0"><tbody><tr v-for="item in requests" :key="item.id"><td>{{ item.created_at }}</td><td class="text-capitalize">{{ item.kind }}</td><td>{{ item.subject_type }} #{{ item.subject_id }}</td></tr></tbody></table>
        </div>
    </div></div></div>
</template>
