<script setup lang="ts">
    import { Head, Link, router, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Bank Reconciliation',
            subtitle: 'Enter a bank statement, match it to the ledger, and lock it once the difference is nil',
            breadcrumbs: [
                {
                    title: 'Bank Reconciliation',
                    href: 'NULL',
                },
            ],
        },
    });

    type Row = { txn_date: string; description: string; reference: string; amount: number };

    const { Notify } = useCommons();
    const { props } = usePage();

    const authUser = computed(() => props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string): boolean => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes(path);

    const statements = ref<Array<Record<string, any>>>([]);
    const accounts = ref<Array<{ code: string; name: string }>>([]);
    const busy = ref(false);
    const csv = ref('');
    const companyId = ref('');
    const form = reactive({ account_code: '', statement_from: '', statement_to: '', opening_balance: '0', closing_balance: '', note: '' });

    const money = (value: unknown): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    /**
     * Reads pasted CSV: date, description, reference, amount (money in positive, money out negative), or date,
     * description, reference, money in, money out. A first line that does not start with a date is skipped.
     */
    const parsedRows = computed<Row[]>(() => csv.value
        .split(/\r?\n/)
        .map((line) => line.split(',').map((cell) => cell.trim().replace(/^"|"$/g, '')))
        .filter((cells) => /^\d{4}-\d{2}-\d{2}$/.test(cells[0] ?? ''))
        .map((cells) => {
            const amount = cells.length >= 5
                ? Number(cells[3] || 0) - Number(cells[4] || 0)
                : Number((cells[3] ?? '0').replace(/[^0-9.-]/g, ''));

            return { txn_date: cells[0], description: cells[1] ?? '', reference: cells[2] ?? '', amount };
        })
        .filter((row) => ! Number.isNaN(row.amount)));

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function load() {
        const params = isSuperadmin.value && companyId.value !== '' ? { company_id: companyId.value } : {};
        statements.value = (await window.axios.get('/api/bank-reconciliations', { params })).data?.data?.data ?? [];

        try {
            accounts.value = (await window.axios.get('/api/bank-reconciliations/accounts', { params })).data?.data ?? [];
        } catch {
            accounts.value = [];
        }
    }

    async function save() {
        busy.value = true;

        try {
            const body: Record<string, unknown> = { ...form, rows: parsedRows.value };

            if (isSuperadmin.value && companyId.value !== '') {
                body.company_id = companyId.value;
            }

            const response = await window.axios.post('/api/bank-reconciliations', body);
            Notify('Statement saved', 'success');
            router.visit(`/bankreconciliation/${response.data.id}`);
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    onMounted(async () => {
        try {
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    });
</script>

<template>
    <Head title="Bank Reconciliation" />

    <div v-if="can('/bankreconciliation/add')" class="card mb-3">
        <div class="card-body">
            <h6 class="mb-3">New statement</h6>
            <form class="row g-2" @submit.prevent="save">
                <div v-if="isSuperadmin" class="col-md-2">
                    <label class="form-label">Company ID</label>
                    <input v-model="companyId" type="number" class="form-control" required @change="load">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Bank account</label>
                    <select v-model="form.account_code" class="form-select" required>
                        <option value="" disabled>Select account</option>
                        <option v-for="account in accounts" :key="account.code" :value="account.code">{{ account.code }} · {{ account.name }}</option>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label">From</label><input v-model="form.statement_from" type="date" class="form-control" required></div>
                <div class="col-md-2"><label class="form-label">To</label><input v-model="form.statement_to" type="date" class="form-control" required></div>
                <div class="col-md-2"><label class="form-label">Opening balance</label><input v-model="form.opening_balance" type="number" step="0.01" class="form-control" required></div>
                <div class="col-md-2"><label class="form-label">Closing balance</label><input v-model="form.closing_balance" type="number" step="0.01" class="form-control" required></div>
                <div class="col-12">
                    <label class="form-label">Statement lines (CSV)</label>
                    <textarea v-model="csv" rows="6" class="form-control font-monospace" placeholder="date,description,reference,amount&#10;2026-09-03,Deposit,DEP1,1000&#10;2026-09-06,Cheque 1,CHQ1,-400" />
                    <small class="text-muted">
                        Columns: date (YYYY-MM-DD), description, reference, amount (money in positive, money out negative); or date, description, reference, money in, money out.
                        {{ parsedRows.length }} line(s) read.
                    </small>
                </div>
                <div class="col-12"><button type="submit" class="btn btn-primary" :disabled="busy || ! parsedRows.length">Save statement</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h6 class="mb-3">Statements</h6>
            <p v-if="! statements.length" class="text-muted mb-0">No statements yet.</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr><th>Account</th><th>Period</th><th class="text-end">Closing</th><th>Matched</th><th>Status</th><th /></tr>
                    </thead>
                    <tbody>
                        <tr v-for="statement in statements" :key="statement.id">
                            <td>{{ statement.account_code }}</td>
                            <td>{{ String(statement.statement_from).slice(0, 10) }} to {{ String(statement.statement_to).slice(0, 10) }}</td>
                            <td class="text-end">{{ money(statement.closing_balance) }}</td>
                            <td>{{ statement.matched_lines_count }} / {{ statement.lines_count }}</td>
                            <td><span class="badge" :class="statement.status === 'reconciled' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'">{{ statement.status }}</span></td>
                            <td><Link :href="`/bankreconciliation/${statement.id}`" class="btn btn-outline-secondary btn-sm">Open</Link></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
