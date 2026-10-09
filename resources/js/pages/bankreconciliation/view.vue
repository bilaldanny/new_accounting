<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import useCommons from '@/composables/common';

    const params = defineProps<{ id: number | string }>();

    defineOptions({
        layout: {
            title: 'Bank Reconciliation',
            subtitle: 'Match each statement line to a ledger line, then reconcile',
            breadcrumbs: [
                {
                    title: 'Bank Reconciliation',
                    href: '/bankreconciliation',
                },
            ],
        },
    });

    const { Notify } = useCommons();
    const { props } = usePage();

    const authUser = computed(() => props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string): boolean => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes(path);

    const data = ref<Record<string, any> | null>(null);
    const busy = ref(false);
    const days = ref(5);
    const choice = ref<Record<number, string>>({});

    const money = (value: unknown): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const locked = computed(() => data.value?.status === 'reconciled');
    const summary = computed(() => data.value?.summary as Record<string, any> | undefined);

    /** The ledger lines still free, same amount and side as a statement line, for its dropdown. */
    const candidatesFor = (amount: number) => ((summary.value?.outstanding_ledger ?? []) as Array<Record<string, any>>)
        .filter((row) => Math.abs(Number(row.signed_amount) - amount) < 0.005);

    const ledgerLine = (detailId: number | null) => detailId === null ? null : '#' + detailId;

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function call(action: () => Promise<{ data: Record<string, any> }>, success?: string) {
        busy.value = true;

        try {
            const response = await action();
            data.value = response.data?.data ?? data.value;

            if (success || response.data?.message) {
                Notify(success ?? response.data.message, 'success');
            }
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    const base = () => `/api/bank-reconciliations/${params.id}`;
    const autoMatch = () => call(() => window.axios.post(`${base()}/auto-match`, { days: days.value }));
    const match = (lineId: number) => call(() => window.axios.post(`${base()}/lines/${lineId}/match`, { detail_id: choice.value[lineId] }));
    const unmatch = (lineId: number) => call(() => window.axios.post(`${base()}/lines/${lineId}/unmatch`));
    const reconcile = () => call(() => window.axios.post(`${base()}/reconcile`));

    onMounted(() => call(() => window.axios.get(base())));
</script>

<template>
    <Head title="Bank Reconciliation" />

    <p><Link href="/bankreconciliation">&larr; All statements</Link></p>

    <template v-if="data">
        <div class="card mb-3">
            <div class="card-body">
                <h6>
                    {{ data.account_code }} · {{ data.statement_from }} to {{ data.statement_to }}
                    <span class="badge ms-2" :class="locked ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'">{{ data.status }}</span>
                </h6>
                <table v-if="summary" class="table table-sm w-auto mb-2">
                    <tbody>
                        <tr><td>Statement closing balance</td><td class="text-end">{{ money(summary.statement_closing) }}</td></tr>
                        <tr><td>+ Ledger lines not on the statement (deposits in transit, cheques not cashed)</td><td class="text-end">{{ money(summary.outstanding_ledger_net) }}</td></tr>
                        <tr><td>Ledger balance on {{ data.statement_to }}</td><td class="text-end">{{ money(summary.ledger_balance) }}</td></tr>
                        <tr><td>+ Statement lines not in the ledger (charges, interest)</td><td class="text-end">{{ money(summary.unmatched_statement_net) }}</td></tr>
                        <tr class="fw-bold"><td>Difference</td><td class="text-end" :class="summary.is_balanced ? 'text-success' : 'text-danger'">{{ money(summary.difference) }}</td></tr>
                    </tbody>
                </table>
                <p v-if="summary && ! summary.lines_total_matches_closing" class="text-warning mb-2">The opening balance plus the statement lines does not equal the closing balance: check the figures you entered.</p>
                <p v-if="summary && summary.unmatched_statement_count" class="text-secondary mb-2">
                    An item the books lack (a bank charge) needs a journal entry; once posted it shows in the dropdown of its line.
                </p>

                <div v-if="! locked" class="d-flex gap-2 align-items-center">
                    <template v-if="can('/bankreconciliation/match')">
                        <input v-model.number="days" type="number" min="0" max="60" class="form-control form-control-sm" style="width: 5rem" aria-label="Day window">
                        <button type="button" class="btn btn-outline-primary btn-sm" :disabled="busy" @click="autoMatch">Auto-match (± days)</button>
                    </template>
                    <button v-if="can('/bankreconciliation/reconcile')" type="button" class="btn btn-success btn-sm" :disabled="busy || ! summary?.is_balanced" @click="reconcile">Mark reconciled</button>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3">Statement lines</h6>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Date</th><th>Description</th><th>Reference</th><th class="text-end">Amount</th><th>Matched to</th></tr></thead>
                        <tbody>
                            <tr v-for="line in data.lines" :key="line.id">
                                <td>{{ line.txn_date }}</td>
                                <td>{{ line.description }}</td>
                                <td>{{ line.reference }}</td>
                                <td class="text-end" :class="line.amount < 0 ? 'text-danger' : ''">{{ money(line.amount) }}</td>
                                <td>
                                    <template v-if="line.matched_detail_id">
                                        ledger line {{ ledgerLine(line.matched_detail_id) }}
                                        <button v-if="! locked && can('/bankreconciliation/match')" type="button" class="btn btn-link btn-sm" :disabled="busy" @click="unmatch(line.id)">Unmatch</button>
                                    </template>
                                    <div v-else-if="! locked && can('/bankreconciliation/match')" class="d-flex gap-1">
                                        <select v-model="choice[line.id]" class="form-select form-select-sm" style="min-width: 16rem">
                                            <option value="" disabled>Choose ledger line</option>
                                            <option v-for="row in candidatesFor(line.amount)" :key="row.id" :value="row.id">{{ row.voucher_date }} · {{ row.voucher_no }} {{ row.cheque_no }} · {{ money(row.signed_amount) }}</option>
                                        </select>
                                        <button type="button" class="btn btn-outline-primary btn-sm" :disabled="busy || ! choice[line.id]" @click="match(line.id)">Match</button>
                                    </div>
                                    <span v-else class="text-muted">-</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="summary?.outstanding_ledger?.length" class="card">
            <div class="card-body">
                <h6 class="mb-3">Ledger lines not on the statement</h6>
                <table class="table table-sm">
                    <thead><tr><th>Date</th><th>Voucher</th><th>Cheque</th><th>Description</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        <tr v-for="row in summary.outstanding_ledger" :key="row.id">
                            <td>{{ row.voucher_date }}</td><td>{{ row.voucher_no }}</td><td>{{ row.cheque_no }}</td><td>{{ row.description }}</td>
                            <td class="text-end">{{ money(row.signed_amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </template>
</template>
