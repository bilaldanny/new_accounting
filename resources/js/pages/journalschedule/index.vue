<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Journal Schedules',
            subtitle: 'Recurring and reversing journals, recurring expenses, amortization and accruals',
            breadcrumbs: [{ title: 'Journal Schedules', href: 'NULL' }],
        },
    });

    type Schedule = { id: number; kind: string; name: string; frequency: string; start_date: string; status: string; auto_post: boolean; auto_reverse: boolean; runs_count: number; last_run_on: string | null; next_due_on: string | null; blocked_reason: string | null };
    type Due = { schedule_id: number; name: string; kind: string; date: string; amount: number; auto_post: boolean };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const rows = ref<Schedule[]>([]);
    const due = ref<Due[]>([]);
    const upTo = ref(new Date().toISOString().slice(0, 10));
    const blocked = ref<{ name: string; date: string; reason: string }[]>([]);

    const form = ref({
        kind: 'recurring', name: '', frequency: 'monthly', start_date: new Date().toISOString().slice(0, 10), end_date: '', max_runs: '' as number | '',
        auto_post: true, amount: 0, total_amount: 0, months: 12, direction: 'expense',
        debit_account_id: '' as number | '', credit_account_id: '' as number | '',
    });

    const isJournal = computed(() => ['recurring', 'reversing'].includes(form.value.kind));

    async function load() {
        const response = await run(() => window.axios.get('/api/journal-schedules', { params: scope() }));
        rows.value = response?.data?.data ?? [];
    }

    function payload() {
        const f = form.value;
        const base: Record<string, unknown> = {
            ...scope(), kind: f.kind, name: f.name, frequency: f.frequency, start_date: f.start_date, end_date: f.end_date || null,
            max_runs: f.max_runs === '' ? null : f.max_runs, auto_post: f.auto_post,
        };

        if (isJournal.value) {
            base.lines = [
                { account_id: Number(f.debit_account_id), debit: f.amount, credit: 0 },
                { account_id: Number(f.credit_account_id), debit: 0, credit: f.amount },
            ];
        } else if (f.kind === 'amortization') {
            base.meta = { total_amount: f.total_amount, months: f.months, direction: f.direction, source_account_id: Number(f.credit_account_id), target_account_id: Number(f.debit_account_id) };
        } else if (f.kind === 'accrual') {
            base.meta = { amount: f.amount, expense_account_id: Number(f.debit_account_id), liability_account_id: Number(f.credit_account_id) };
        } else {
            base.meta = { amount: f.amount, expense_account_id: Number(f.debit_account_id), paid_from_account_id: Number(f.credit_account_id) };
        }

        return base;
    }

    async function save() {
        if (await run(() => window.axios.post('/api/journal-schedules', payload()), 'Schedule saved')) {
            form.value.name = '';
            await load();
        }
    }

    async function preview() {
        const response = await run(() => window.axios.get('/api/journal-schedules/preview', { params: { ...scope(), up_to: upTo.value } }));
        due.value = response?.data?.data ?? [];
    }

    async function runNow() {
        const response = await run(() => window.axios.post('/api/journal-schedules/run', { ...scope(), up_to: upTo.value }), 'Schedules run');
        blocked.value = response?.data?.data?.blocked ?? [];
        await load();
        await preview();
    }

    async function change(row: Schedule, action: 'pause' | 'resume' | 'cancel') {
        if (await run(() => window.axios.post(`/api/journal-schedules/${row.id}/${action}`, scope()), 'Saved')) {
            await load();
        }
    }

    onMounted(async () => {
        await load();
        await preview();
    });
</script>

<template>
    <Head title="Journal Schedules" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div v-if="can('/journalschedule/add')" class="border rounded p-3 mb-4">
            <h6>New schedule</h6>
            <div class="row g-2">
                <div class="col-md-2">
                    <select v-model="form.kind" class="form-select form-select-sm" aria-label="Kind">
                        <option value="recurring">Recurring journal</option>
                        <option value="reversing">Reversing journal</option>
                        <option value="expense">Recurring expense</option>
                        <option value="amortization">Prepaid / deferred</option>
                        <option value="accrual">Accrued expense</option>
                    </select>
                </div>
                <div class="col-md-3"><input v-model="form.name" class="form-control form-control-sm" placeholder="Name" aria-label="Name" /></div>
                <div v-if="form.kind !== 'amortization'" class="col-md-2">
                    <select v-model="form.frequency" class="form-select form-select-sm" aria-label="Frequency"><option>daily</option><option>weekly</option><option>monthly</option><option>yearly</option></select>
                </div>
                <div class="col-md-2"><input v-model="form.start_date" type="date" class="form-control form-control-sm" aria-label="Start date" /></div>
                <div class="col-md-2"><input v-model="form.end_date" type="date" class="form-control form-control-sm" aria-label="End date" /></div>
                <div class="col-md-2"><input v-model.number="form.debit_account_id" type="number" class="form-control form-control-sm" :placeholder="form.kind === 'amortization' ? 'Expense / revenue account id' : 'Debit account id'" aria-label="Debit account" /></div>
                <div class="col-md-2"><input v-model.number="form.credit_account_id" type="number" class="form-control form-control-sm" :placeholder="form.kind === 'amortization' ? 'Prepaid / deferred account id' : 'Credit account id'" aria-label="Credit account" /></div>
                <template v-if="form.kind === 'amortization'">
                    <div class="col-md-2"><input v-model.number="form.total_amount" type="number" class="form-control form-control-sm" placeholder="Total" aria-label="Total amount" /></div>
                    <div class="col-md-1"><input v-model.number="form.months" type="number" class="form-control form-control-sm" placeholder="Months" aria-label="Months" /></div>
                    <div class="col-md-2"><select v-model="form.direction" class="form-select form-select-sm" aria-label="Direction"><option value="expense">Prepaid expense</option><option value="revenue">Deferred revenue</option></select></div>
                </template>
                <div v-else class="col-md-2"><input v-model.number="form.amount" type="number" class="form-control form-control-sm" placeholder="Amount" aria-label="Amount" /></div>
                <div class="col-md-2 d-flex align-items-center"><label class="small"><input v-model="form.auto_post" type="checkbox" class="me-1" />Post automatically</label></div>
                <div class="col-md-1"><button type="button" class="btn btn-dark btn-sm" @click="save">Save</button></div>
            </div>
            <div class="small text-muted mt-2">Without "post automatically" each voucher is left pending for approval. Accruals and reversing journals are reversed on the first day of the next month.</div>
        </div>

        <div class="border rounded p-3 mb-4">
            <div class="row g-2 align-items-end mb-2">
                <div class="col-auto"><label class="form-label small" for="upto">Post everything due up to</label><input id="upto" v-model="upTo" type="date" class="form-control form-control-sm" @change="preview" /></div>
                <div v-if="can('/journalschedule/run')" class="col-auto"><button type="button" class="btn btn-dark btn-sm" @click="runNow">Run now</button></div>
            </div>
            <div v-for="item in blocked" :key="item.name + item.date" class="alert alert-warning py-2 small mb-2">{{ item.name }} stopped at {{ item.date }}: {{ item.reason }}</div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Due</th><th>Schedule</th><th>Kind</th><th class="text-end">Amount</th><th>Voucher</th></tr></thead>
                <tbody>
                    <tr v-for="item in due" :key="item.schedule_id + item.date"><td>{{ item.date }}</td><td>{{ item.name }}</td><td class="text-capitalize">{{ item.kind }}</td><td class="text-end">{{ money(item.amount) }}</td><td>{{ item.auto_post ? 'posted' : 'pending approval' }}</td></tr>
                    <tr v-if="! due.length"><td colspan="5" class="text-center text-muted">Nothing is due.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Name</th><th>Kind</th><th>Frequency</th><th>Status</th><th>Runs</th><th>Last</th><th>Next</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.name }}<div v-if="row.blocked_reason" class="small text-danger">{{ row.blocked_reason }}</div></td>
                        <td class="text-capitalize">{{ row.kind }}</td><td class="text-capitalize">{{ row.frequency }}</td><td class="text-capitalize">{{ row.status }}</td>
                        <td>{{ row.runs_count }}</td><td>{{ row.last_run_on ?? '-' }}</td><td>{{ row.next_due_on ?? '-' }}</td>
                        <td v-if="can('/journalschedule/:id/edit')" class="text-end">
                            <button v-if="row.status === 'active'" type="button" class="btn btn-outline-dark btn-sm me-1" @click="change(row, 'pause')">Pause</button>
                            <button v-if="row.status === 'paused'" type="button" class="btn btn-outline-dark btn-sm me-1" @click="change(row, 'resume')">Resume</button>
                            <button v-if="['active', 'paused'].includes(row.status)" type="button" class="btn btn-outline-danger btn-sm" @click="change(row, 'cancel')">Cancel</button>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="8" class="text-center text-muted">No schedules yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div></div></div>
</template>
