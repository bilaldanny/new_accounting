<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import FiscalYearDateRange from '@/components/FiscalYearDateRange.vue';
    import Loader from '@/components/Loader.vue';
    import useActiveFinancialYear from '@/composables/activeFinancialYear';
    import useCommons from '@/composables/common';
    import useCustomers from '@/composables/customer';
    import useSuppliers from '@/composables/supplier';
    import { formatNumber } from '@/utils/numberFormat';

    defineOptions({
        layout: {
            title: 'Customer / Supplier Ledger',
            subtitle: 'Period statement of account for any customer or supplier',
            breadcrumbs: [
                {
                    title: 'Reports',
                    href: 'NULL',
                },
                {
                    title: 'Customer / Supplier Ledger',
                    href: 'NULL',
                },
            ],
        },
    });

    type PartyType = 'customer' | 'supplier';

    type LedgerRow = {
        id: number | string;
        voucher_date?: string;
        voucher_no?: string;
        ref_no?: string;
        description?: string;
        debit?: number | string | null;
        credit?: number | string | null;
        acc_nature?: string;
        highlight?: number;
        branch?: { name?: string };
        transaction?: { parent?: { payment_status?: string } };
        type?: string;
        cheque_no?: string;
    };

    type LedgerData = {
        taccount: LedgerRow[];
        openingbalance: number;
    };

    const emptyLedger = (): LedgerData => ({ taccount: [], openingbalance: 0 });

    const { props } = usePage();

    const { fetchCompany, fetchBranch, companiesdata, branchesdata, Notify } = useCommons();
    const { customersdata, fetchCustomersDropdown, getLedger: getCustomerLedger } = useCustomers();
    const { suppliersdata, fetchSuppliersDropdown, getLedger: getSupplierLedger } = useSuppliers();
    const { fiscalYear, fetchActiveFinancialYear, clampToFiscalYear } = useActiveFinancialYear();

    const scope = reactive({
        company_id: '' as string | number,
        branch_id: '' as string | number,
        contact_id: '' as string | number,
    });

    const filters = reactive({
        start_date: '',
        end_date: '',
    });

    const authUser = computed(() => props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyFilter = computed(() => isSuperadmin.value);
    const showBranchFilter = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const branchFilterDisabled = computed(() => showCompanyFilter.value && !scope.company_id);

    const partyType = ref<PartyType>('customer');
    const partyLabel = computed(() => (partyType.value === 'customer' ? 'Customer' : 'Supplier'));
    const parties = computed(() => (partyType.value === 'customer' ? customersdata.value : suppliersdata.value));
    const partyFilterDisabled = computed(() => !scope.company_id || !scope.branch_id);

    const ledgerData = ref<LedgerData>(emptyLedger());
    const loading = ref(false);
    const hasLoaded = ref(false);
    const applyingFiscalYear = ref(false);

    const formatAmount = formatNumber;

    const ledgerRows = computed(() => {
        let runningBalance = Number(ledgerData.value.openingbalance ?? 0);

        return ledgerData.value.taccount.map((row) => {
            const debit = Number(row.debit ?? 0);
            const credit = Number(row.credit ?? 0);

            runningBalance = row.acc_nature === 'cr'
                ? runningBalance + credit - debit
                : runningBalance + debit - credit;

            return { ...row, balance_amount: runningBalance };
        });
    });

    const debitTotal = computed(() =>
        ledgerRows.value.reduce((sum, row) => sum + Number(row.debit ?? 0), 0),
    );

    const creditTotal = computed(() =>
        ledgerRows.value.reduce((sum, row) => sum + Number(row.credit ?? 0), 0),
    );

    const closingBalance = computed(() => {
        if (ledgerRows.value.length === 0) {
            return Number(ledgerData.value.openingbalance ?? 0);
        }

        return ledgerRows.value[ledgerRows.value.length - 1].balance_amount;
    });

    const selectedPartyName = computed(() => {
        const party = parties.value.find((item) => String(item.id) === String(scope.contact_id));

        return party?.text ?? party?.business_name ?? partyLabel.value;
    });

    /** Narrations can carry markup from the server; show them as plain text. */
    function plainText(value: string | undefined): string {
        return String(value ?? '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() || '-';
    }

    function documentTypeLabel(type: string | undefined): string {
        const labels: Record<string, string> = {
            purchaseorder: 'Purchase order',
            purchasereturn: 'Purchase return',
            recieving_note: 'Receiving note',
            sell: 'Sale',
            sellreturn: 'Sale return',
            issue_note: 'Issue note',
            cash: 'Cash',
            bank: 'Bank',
            online: 'Online',
            cheque: 'Cheque',
            bank_transfer: 'Bank transfer',
            card: 'Card',
            other: 'Other',
        };

        return labels[String(type ?? '')] ?? (type && type !== '-' ? type : '-');
    }

    function paymentStatusClass(status: string | undefined): string {
        const normalized = String(status ?? '').toLowerCase();

        if (normalized === 'paid') {
            return 'badge bg-success';
        }

        if (normalized === 'partial') {
            return 'badge bg-warning text-dark';
        }

        if (normalized === 'due') {
            return 'badge bg-danger';
        }

        return 'text-muted';
    }

    function resetLedger() {
        ledgerData.value = emptyLedger();
        hasLoaded.value = false;
    }

    async function loadParties() {
        scope.contact_id = '';
        resetLedger();

        if (partyType.value === 'customer') {
            await fetchCustomersDropdown(scope.company_id, scope.branch_id);

            return;
        }

        await fetchSuppliersDropdown(scope.company_id, scope.branch_id);
    }

    async function loadLedger() {
        if (!scope.contact_id) {
            return;
        }

        loading.value = true;

        try {
            const getLedger = partyType.value === 'customer' ? getCustomerLedger : getSupplierLedger;

            ledgerData.value = await getLedger({
                contact_id: scope.contact_id,
                company_id: scope.company_id || undefined,
                branch_id: scope.branch_id || undefined,
                start_date: filters.start_date,
                end_date: filters.end_date,
            });
            hasLoaded.value = true;
        } catch (error: unknown) {
            resetLedger();

            if (window.axios.isAxiosError(error) && error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'Unable to load the ledger', 'alert');
            }
        } finally {
            loading.value = false;
        }
    }

    async function applyActiveFiscalYear() {
        if (filters.start_date && filters.end_date) {
            return;
        }

        applyingFiscalYear.value = true;

        try {
            const year = await fetchActiveFinancialYear(scope.company_id);

            if (year !== null) {
                filters.start_date = year.start_date;
                filters.end_date = year.end_date;
            }
        } finally {
            applyingFiscalYear.value = false;
        }
    }

    function constrainDates(field: 'start_date' | 'end_date') {
        filters.start_date = clampToFiscalYear(filters.start_date);
        filters.end_date = clampToFiscalYear(filters.end_date);

        if (filters.start_date && filters.end_date && filters.start_date > filters.end_date) {
            if (field === 'start_date') {
                filters.end_date = filters.start_date;
            } else {
                filters.start_date = filters.end_date;
            }
        }
    }

    async function onPartyTypeChange() {
        await loadParties();
    }

    async function onCompanyChange() {
        scope.branch_id = '';
        filters.start_date = '';
        filters.end_date = '';
        await fetchBranch(scope.company_id);
        await loadParties();
    }

    async function onBranchChange() {
        await loadParties();
    }

    async function onContactChange() {
        resetLedger();

        if (!scope.contact_id) {
            return;
        }

        const before = `${filters.start_date}|${filters.end_date}`;

        await applyActiveFiscalYear();

        // A changed range is picked up by the date watcher; otherwise load here.
        if (`${filters.start_date}|${filters.end_date}` === before) {
            await loadLedger();
        }
    }

    watch(
        () => [filters.start_date, filters.end_date],
        () => {
            void loadLedger();
        },
    );

    onMounted(async () => {
        scope.company_id = authUser.value?.company_id ?? '';
        scope.branch_id = authUser.value?.branch_id ?? '';

        if (showCompanyFilter.value) {
            await fetchCompany();
        }

        if (scope.company_id && showBranchFilter.value) {
            await fetchBranch(scope.company_id);
        }

        if (scope.company_id && scope.branch_id) {
            await loadParties();
        }
    });
</script>

<template>
    <Head title="Customer / Supplier Ledger" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="ledger-report__filters">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-auto">
                        <span class="form-label d-block">Ledger of</span>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Ledger of">
                            <input
                                id="ledger-party-customer"
                                v-model="partyType"
                                type="radio"
                                class="btn-check"
                                value="customer"
                                @change="onPartyTypeChange"
                            />
                            <label class="btn btn-outline-secondary" for="ledger-party-customer">Customer</label>
                            <input
                                id="ledger-party-supplier"
                                v-model="partyType"
                                type="radio"
                                class="btn-check"
                                value="supplier"
                                @change="onPartyTypeChange"
                            />
                            <label class="btn btn-outline-secondary" for="ledger-party-supplier">Supplier</label>
                        </div>
                    </div>

                    <div v-if="showCompanyFilter" class="col-12 col-md-4 col-lg-3">
                        <label class="form-label" for="ledger-company">Company</label>
                        <select
                            id="ledger-company"
                            v-model="scope.company_id"
                            class="form-select form-select-sm"
                            @change="onCompanyChange"
                        >
                            <option value="">Select company</option>
                            <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                                {{ company.text ?? company.name }}
                            </option>
                        </select>
                    </div>

                    <div v-if="showBranchFilter" class="col-12 col-md-4 col-lg-3">
                        <label class="form-label" for="ledger-branch">Branch</label>
                        <select
                            id="ledger-branch"
                            v-model="scope.branch_id"
                            class="form-select form-select-sm"
                            :disabled="branchFilterDisabled"
                            @change="onBranchChange"
                        >
                            <option value="">Select branch</option>
                            <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                                {{ branch.text ?? branch.name }}
                            </option>
                        </select>
                    </div>

                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="form-label" for="ledger-party">{{ partyLabel }}</label>
                        <select
                            id="ledger-party"
                            v-model="scope.contact_id"
                            class="form-select form-select-sm"
                            :disabled="partyFilterDisabled"
                            @change="onContactChange"
                        >
                            <option value="">Select {{ partyLabel.toLowerCase() }}</option>
                            <option v-for="party in parties" :key="party.id" :value="party.id">
                                {{ party.text ?? party.business_name }}
                            </option>
                        </select>
                    </div>

                    <div class="col-12 col-lg-4">
                        <FiscalYearDateRange
                            v-model:start-date="filters.start_date"
                            v-model:end-date="filters.end_date"
                            :fiscal-year="fiscalYear"
                            id-prefix="party-ledger"
                            @change="constrainDates"
                        />
                    </div>
                </div>
            </div>

            <div class="admin-list-card__body">
                <Loader v-if="loading || applyingFiscalYear" message="Loading ledger…" :fields="4" />

                <div v-else-if="!hasLoaded" class="ledger-report__empty">
                    <p>
                        Choose a company and branch, then a {{ partyLabel.toLowerCase() }}, to see the account
                        statement.
                    </p>
                </div>

                <template v-else>
                    <div class="ledger-report__kpis">
                        <article class="ledger-report__kpi">
                            <span>Opening balance</span>
                            <strong>{{ formatAmount(ledgerData.openingbalance) }}</strong>
                        </article>
                        <article class="ledger-report__kpi">
                            <span>Total debit</span>
                            <strong>{{ formatAmount(debitTotal) }}</strong>
                        </article>
                        <article class="ledger-report__kpi">
                            <span>Total credit</span>
                            <strong>{{ formatAmount(creditTotal) }}</strong>
                        </article>
                        <article class="ledger-report__kpi ledger-report__kpi--accent">
                            <span>Closing balance</span>
                            <strong>{{ formatAmount(closingBalance) }}</strong>
                        </article>
                    </div>

                    <div class="ledger-report__head">
                        <p>
                            {{ selectedPartyName }}
                            <template v-if="filters.start_date && filters.end_date">
                                · <strong>{{ filters.start_date }}</strong> to <strong>{{ filters.end_date }}</strong>
                            </template>
                        </p>
                        <span class="ledger-report__count">{{ ledgerRows.length }} entries</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle ledger-report__table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Voucher</th>
                                    <th>Reference</th>
                                    <th>Narration</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th class="text-end">Debit</th>
                                    <th class="text-end">Credit</th>
                                    <th class="text-end">Balance</th>
                                    <th>Method</th>
                                    <th>Cheque</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="ledger-report__opening">
                                    <td>{{ filters.start_date || '-' }}</td>
                                    <td colspan="5">Opening balance</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end fw-semibold">{{ formatAmount(ledgerData.openingbalance) }}</td>
                                    <td colspan="2"></td>
                                </tr>
                                <tr v-for="row in ledgerRows" :key="row.id">
                                    <td>{{ row.voucher_date || '-' }}</td>
                                    <td class="ledger-report__mono">{{ row.voucher_no || '-' }}</td>
                                    <td class="ledger-report__mono">{{ row.ref_no || '-' }}</td>
                                    <td class="ledger-report__narration">{{ plainText(row.description) }}</td>
                                    <td>{{ row.branch?.name || '-' }}</td>
                                    <td>
                                        <span :class="paymentStatusClass(row.transaction?.parent?.payment_status)">
                                            {{ row.transaction?.parent?.payment_status || '-' }}
                                        </span>
                                    </td>
                                    <td class="text-end">{{ row.debit ? formatAmount(row.debit) : '-' }}</td>
                                    <td class="text-end">{{ row.credit ? formatAmount(row.credit) : '-' }}</td>
                                    <td class="text-end fw-semibold">{{ formatAmount(row.balance_amount) }}</td>
                                    <td>{{ documentTypeLabel(row.type) }}</td>
                                    <td>{{ row.cheque_no && row.cheque_no !== '-' ? row.cheque_no : '-' }}</td>
                                </tr>
                                <tr v-if="ledgerRows.length === 0">
                                    <td colspan="11" class="text-center text-muted py-4">No ledger entries for this period.</td>
                                </tr>
                            </tbody>
                            <tfoot v-if="ledgerRows.length > 0">
                                <tr class="ledger-report__footer">
                                    <td colspan="6">Period totals</td>
                                    <td class="text-end">{{ formatAmount(debitTotal) }}</td>
                                    <td class="text-end">{{ formatAmount(creditTotal) }}</td>
                                    <td class="text-end fw-semibold">{{ formatAmount(closingBalance) }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
.ledger-report__filters {
    padding: 1rem 1rem 0.25rem;
}

.ledger-report__empty {
    padding: 2.5rem 1rem;
    text-align: center;
    color: var(--bs-secondary-color, #6c757d);
}

.ledger-report__kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.ledger-report__kpi {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.75rem 1rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: 0.5rem;
}

.ledger-report__kpi span {
    font-size: 0.75rem;
    color: var(--bs-secondary-color, #6c757d);
}

.ledger-report__kpi strong {
    font-size: 1.05rem;
}

.ledger-report__kpi--accent {
    border-color: rgba(25, 150, 131, 0.4);
    background: rgba(25, 150, 131, 0.08);
}

.ledger-report__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.ledger-report__head p {
    margin: 0;
}

.ledger-report__count {
    font-size: 0.8rem;
    color: var(--bs-secondary-color, #6c757d);
}

.ledger-report__table th,
.ledger-report__table td {
    white-space: nowrap;
}

.ledger-report__narration {
    white-space: normal;
    min-width: 14rem;
}

.ledger-report__mono {
    font-variant-numeric: tabular-nums;
}

.ledger-report__opening td,
.ledger-report__footer td {
    background: var(--bs-tertiary-bg, #f8f9fa);
    font-weight: 600;
}
</style>
