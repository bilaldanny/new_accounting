<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Dunning & Collections',
            subtitle: 'Reviewed batches of reminders, the log and collection status',
            breadcrumbs: [{ title: 'Dunning & Collections', href: 'NULL' }],
        },
    });

    type Level = { level: number; name: string; days_past_due: number; subject: string; body: string; is_active: boolean };
    type Candidate = { id: number; contact_id: number; contact_name: string; invoice_no: string | null; due_date: string; open_amount: number; days_past_due: number; level: number; level_name: string; case_status: string };
    type EventRow = { id: number; contact_id: number; transaction_id: number; level: number; channel: string; status: string; subject: string; created_at: string };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const levels = ref<Level[]>([]);
    const candidates = ref<Candidate[]>([]);
    const events = ref<EventRow[]>([]);
    const selected = ref<number[]>([]);
    const asOf = ref(new Date().toISOString().slice(0, 10));
    const channel = ref<'email' | 'log'>('email');

    async function load() {
        const [lv, cand, log] = await Promise.all([
            run(() => window.axios.get('/api/dunning/levels', { params: scope() })),
            run(() => window.axios.get('/api/dunning/candidates', { params: { ...scope(), as_of: asOf.value } })),
            run(() => window.axios.get('/api/dunning/events', { params: scope() })),
        ]);
        levels.value = lv?.data?.data ?? [];
        candidates.value = cand?.data?.data ?? [];
        events.value = log?.data?.data ?? [];
        selected.value = candidates.value.map((row) => row.id);
    }

    async function saveLevel(level: Level) {
        await run(() => window.axios.put(`/api/dunning/levels/${level.level}`, { ...scope(), name: level.name, days_past_due: level.days_past_due, subject: level.subject, body: level.body, is_active: level.is_active }), 'Level saved');
        await load();
    }

    async function send() {
        const items = candidates.value.filter((row) => selected.value.includes(row.id)).map((row) => ({ transaction_id: row.id, level: row.level }));

        if (! items.length) {
            return;
        }

        const response = await run(() => window.axios.post('/api/dunning/send', { ...scope(), channel: channel.value, as_of: asOf.value, items }), 'Reminders processed');
        const data = response?.data?.data;

        if (data) {
            window.alert(`Sent ${data.sent}, logged ${data.logged}, failed ${data.failed}, skipped ${data.skipped} (no email address or already sent).`);
        }

        await load();
    }

    async function setStatus(row: Candidate, status: string) {
        let promised: string | null = null;

        if (status === 'promised') {
            promised = window.prompt('Promised to pay by (YYYY-MM-DD)', '');

            if (! promised) {
                return;
            }
        }

        if (await run(() => window.axios.post('/api/dunning/cases', { ...scope(), contact_id: row.contact_id, status, promised_on: promised }), 'Saved')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Dunning & Collections" />

    <div class="container-fluid py-3">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div class="border rounded p-3 mb-4">
            <div class="row g-2 align-items-end mb-2">
                <div class="col-auto"><label class="form-label small" for="dn-asof">As of</label><input id="dn-asof" v-model="asOf" type="date" class="form-control form-control-sm" @change="load" /></div>
                <div class="col-auto">
                    <select v-model="channel" class="form-select form-select-sm" aria-label="Channel"><option value="email">Send email</option><option value="log">Log a manual follow-up</option></select>
                </div>
                <div v-if="can('/dunning/send')" class="col-auto"><button type="button" class="btn btn-dark btn-sm" :disabled="! selected.length" @click="send">Send selected ({{ selected.length }})</button></div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th></th><th>Customer</th><th>Invoice</th><th>Due</th><th class="text-end">Days</th><th class="text-end">Owed</th><th>Level</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="row in candidates" :key="row.id">
                            <td><input v-model="selected" type="checkbox" :value="row.id" :aria-label="`Select ${row.invoice_no}`" /></td>
                            <td>{{ row.contact_name }}</td><td>{{ row.invoice_no }}</td><td>{{ row.due_date }}</td><td class="text-end">{{ row.days_past_due }}</td><td class="text-end">{{ money(row.open_amount) }}</td>
                            <td>{{ row.level }}: {{ row.level_name }}</td><td class="text-capitalize">{{ row.case_status }}</td>
                            <td v-if="can('/dunning/case')" class="text-end">
                                <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="setStatus(row, 'promised')">Promised</button>
                                <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="setStatus(row, 'disputed')">Disputed</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="setStatus(row, 'escalated')">Escalate</button>
                            </td>
                        </tr>
                        <tr v-if="! candidates.length"><td colspan="9" class="text-center text-muted">No reminder is due.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <h6>Levels</h6>
        <div class="row g-3 mb-4">
            <div v-for="level in levels" :key="level.level" class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="d-flex gap-2 mb-2">
                        <input v-model="level.name" class="form-control form-control-sm" :aria-label="`Level ${level.level} name`" :disabled="! can('/dunning/levels')" />
                        <input v-model.number="level.days_past_due" type="number" class="form-control form-control-sm" style="width: 6rem" :aria-label="`Level ${level.level} days`" :disabled="! can('/dunning/levels')" />
                    </div>
                    <input v-model="level.subject" class="form-control form-control-sm mb-2" :aria-label="`Level ${level.level} subject`" :disabled="! can('/dunning/levels')" />
                    <textarea v-model="level.body" rows="6" class="form-control form-control-sm mb-2" :aria-label="`Level ${level.level} text`" :disabled="! can('/dunning/levels')" />
                    <div class="small text-muted mb-2">Use {customer} {invoice} {amount} {due_date} {days_overdue} {company}.</div>
                    <label class="small me-3"><input v-model="level.is_active" type="checkbox" class="me-1" :disabled="! can('/dunning/levels')" />Active</label>
                    <button v-if="can('/dunning/levels')" type="button" class="btn btn-outline-dark btn-sm" @click="saveLevel(level)">Save</button>
                </div>
            </div>
        </div>

        <h6>History</h6>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>When</th><th>Invoice id</th><th>Level</th><th>Channel</th><th>Result</th><th>Subject</th></tr></thead>
                <tbody>
                    <tr v-for="row in events" :key="row.id"><td>{{ row.created_at.slice(0, 16).replace('T', ' ') }}</td><td>{{ row.transaction_id }}</td><td>{{ row.level }}</td><td>{{ row.channel }}</td><td class="text-capitalize">{{ row.status.replace('_', ' ') }}</td><td>{{ row.subject }}</td></tr>
                    <tr v-if="! events.length"><td colspan="6" class="text-center text-muted">Nothing sent yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
