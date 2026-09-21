<script setup lang="ts">
    import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import FiscalYearDateRange from '@/components/FiscalYearDateRange.vue';
    import TheFilter from '@/components/theFilter.vue';
    import TheTable from '@/components/theTable.vue';
    import TopButtons from '@/components/topButtons.vue';
    import useActiveFinancialYear from '@/composables/activeFinancialYear';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useCustomers from '@/composables/customer';
    import useReport, {
        ADJUSTMENT_TYPES,
        PAYMENT_METHODS,
        PAYMENT_STATUSES,
    } from '@/composables/report';
    import type { ReportKey } from '@/composables/report';
    import useSuppliers from '@/composables/supplier';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import { formatNumber } from '@/utils/numberFormat';

    /**
     * One page for every report under Reports (transaction lists, party, product, stock, ledger and
     * financial reports). The route says which one; the composable says how it looks. Filters, totals and
     * the table behave the same for all of them.
     */
    const pageProps = defineProps<{
        report: ReportKey;
    }>();

    const { props } = usePage();

    const { state, summary, config, load, changeOrder, checkAll, select_data } = useReport(pageProps.report);

    const {
        formatedText,
        fetchCompany,
        fetchBranch,
        fetchCustomerGroup,
        fetchCategory,
        fetchBrand,
        fetchWithRetry,
        companiesdata,
        branchesdata,
        customergroupsdata,
        categoriesdata,
        brandsdata,
    } = useCommons();
    const { customersdata, fetchCustomersDropdown } = useCustomers();
    const { suppliersdata, fetchSuppliersDropdown } = useSuppliers();
    const { fetchActiveFinancialYear } = useActiveFinancialYear();

    setLayoutProps({
        title: config.title,
        subtitle: config.subtitle,
        breadcrumbs: [
            {
                title: 'Reports',
                href: 'NULL',
            },
            {
                title: config.title,
                href: 'NULL',
            },
        ],
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
    const branchFilterDisabled = computed(() => showCompanyFilter.value && !state.search.company_id);

    const party = computed(() => config.filters.party);
    const partyLabel = computed(() => {
        if (party.value === 'both') {
            return 'Customer / Supplier';
        }

        return party.value === 'supplier' ? 'Supplier' : 'Customer';
    });
    const parties = computed(() => {
        if (party.value === 'both') {
            return [...customersdata.value, ...suppliersdata.value];
        }

        return party.value === 'supplier' ? suppliersdata.value : customersdata.value;
    });
    const partyFilterDisabled = computed(() => !state.search.company_id || !state.search.branch_id);

    const columns = computed(() => config.columns);

    /** Outstanding and aging are a position on one day; the other reports cover a period. */
    const isAsOf = computed(() => config.filters.asOf === true);

    const TAX_SIDES = [
        { value: 'all', label: 'Input & output' },
        { value: 'input', label: 'Input (purchases)' },
        { value: 'output', label: 'Output (sales)' },
    ];

    const productsdata = ref<{ id: number | string; name?: string; text?: string }[]>([]);
    const accountsdata = ref<{ code: string; text: string }[]>([]);

    const ACCOUNT_GROUPS = [
        { value: '', label: 'All' },
        { value: 1, label: '1xx Equity' },
        { value: 2, label: '2xx Assets' },
        { value: 3, label: '3xx Liabilities' },
        { value: 4, label: '4xx Expenses' },
        { value: 5, label: '5xx Revenue' },
        { value: 6, label: '6xx Cost of goods sold' },
    ];

    const VOUCHER_TYPES = [
        { value: 'all', label: 'Receipts & payments' },
        { value: 'receipt', label: 'Receipts' },
        { value: 'payment', label: 'Payments' },
    ];

    const CONTACT_TYPES = [
        { value: 'all', label: 'Customers & suppliers' },
        { value: 'customer', label: 'Customers' },
        { value: 'supplier', label: 'Suppliers' },
    ];

    const cards = computed(() =>
        config.summary.map((card) => {
            const value = card.key.split('.').reduce<unknown>(
                (carry, part) => (carry as Record<string, unknown> | undefined)?.[part],
                summary.value,
            );

            return {
                ...card,
                display: formatNumber(value, card.kind === 'count' ? 0 : 2),
            };
        }),
    );

    const hasRange = computed(() => Boolean(state.search.end_date) && (isAsOf.value || Boolean(state.search.start_date)));

    const filterOpen = ref(false);
    const applyingFiscalYear = ref(false);

    const getData = async () => {
        await load();
    };

    /** The account list belongs to the company; a code shared by several branches is listed once. */
    async function loadAccounts() {
        state.search.account_code = '';
        accountsdata.value = [];

        if (!config.filters.accountSelector || !state.search.company_id) {
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchallaccounts', {
                params: { company_id: state.search.company_id },
            });
            const seen = new Set<string>();

            accountsdata.value = (response.data as { code: string; text: string }[]).filter((account) => {
                if (seen.has(account.code)) {
                    return false;
                }

                seen.add(account.code);

                return true;
            });
        } catch {
            accountsdata.value = [];
        }
    }

    /** Category, brand and product lists belong to the company, so they follow the company filter. */
    async function loadProductFilters() {
        state.search.category_id = '';
        state.search.brand_id = '';
        state.search.product_id = '';

        if (!config.filters.productFilters || !state.search.company_id) {
            return;
        }

        await fetchCategory(state.search.company_id);
        await fetchBrand(state.search.company_id);

        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchproducts', {
                params: { company_id: state.search.company_id },
            });
            productsdata.value = response.data;
        } catch {
            productsdata.value = [];
        }
    }

    async function loadParties() {
        state.search.contact_id = '';
        state.search.customer_group_id = '';

        if (config.filters.customerGroup) {
            await fetchCustomerGroup(state.search.company_id, state.search.branch_id);
        }

        if (party.value === 'supplier' || party.value === 'both') {
            await fetchSuppliersDropdown(state.search.company_id, state.search.branch_id);
        }

        if (party.value === 'customer' || party.value === 'both') {
            await fetchCustomersDropdown(state.search.company_id, state.search.branch_id);
        }
    }

    /** Today as a `Y-m-d` string in the browser's own time zone. */
    const today = (): string => new Date().toLocaleDateString('en-CA');

    /**
     * A position on one day starts on today; a period starts on the active financial year, and without
     * one the report is not limited by date.
     */
    async function applyActiveFiscalYear() {
        if (isAsOf.value) {
            state.search.start_date = '';
            state.search.end_date = today();

            return;
        }

        applyingFiscalYear.value = true;

        try {
            const year = await fetchActiveFinancialYear(state.search.company_id);

            state.search.start_date = year?.start_date ?? '';
            state.search.end_date = year?.end_date ?? '';
        } finally {
            applyingFiscalYear.value = false;
        }
    }

    async function handleCompanyChange() {
        state.search.branch_id = '';
        await fetchBranch(state.search.company_id);
        await loadProductFilters();
        await loadAccounts();
        await loadParties();
        await applyActiveFiscalYear();
    }

    async function handleBranchChange() {
        await loadParties();
    }

    function constrainDates(field: 'start_date' | 'end_date') {
        const { start_date: start, end_date: end } = state.search;

        if (start && end && start > end) {
            if (field === 'start_date') {
                state.search.end_date = start;
            } else {
                state.search.start_date = end;
            }
        }
    }

    function clearFilters() {
        state.search.search = '';
        state.search.show_record = 10;
        state.search.page = 1;
        state.search.contact_id = '';
        state.search.customer_group_id = '';
        state.search.contact_type = 'all';
        state.search.include_zero = false;
        state.search.top = 10;
        state.search.tax_side = 'all';
        state.search.by_branch = false;
        state.search.from_branch_id = '';
        state.search.to_branch_id = '';
        state.search.account_group = '';
        state.search.voucher_type = 'all';
        state.search.status = config.filters.defaultStatus ?? '';
        state.search.payment_status = 'all';
        state.search.method = 'all';
        state.search.adjustment_type = 'all';
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';

        void applyActiveFiscalYear()
            .then(() => loadProductFilters())
            .then(() => loadAccounts())
            .then(() => loadParties())
            .then(getData);
    }

    function search() {
        state.search.page = 1;
        void getData();
    }

    function onStateUpdate(newState: Record<string, unknown>) {
        Object.assign(state, newState);
    }

    const fetchAllRowsForExport = createTableExportAllRows(`${API_ENDPOINTS.reports}/${pageProps.report}`, () => state);

    onMounted(async () => {
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';

        if (showCompanyFilter.value) {
            await fetchCompany();
        }

        if (state.search.company_id && showBranchFilter.value) {
            await fetchBranch(state.search.company_id);
        }

        await applyActiveFiscalYear();

        await loadProductFilters();
        await loadAccounts();

        if (state.search.company_id && state.search.branch_id) {
            await loadParties();
        }

        await getData();
    });
</script>

<template>
    <Head :title="config.title" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar">
                <TopButtons
                    :state="state"
                    :filter-open="filterOpen"
                    :getData="search"
                    :url="`report/${report}`"
                    :show-filter="true"
                    :show-add="false"
                    :show-status="false"
                    :show-bulk-icons="false"
                    :show-import="false"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter v-model:open="filterOpen" :loading="state.loading" @clear="clearFilters" @search="search">
                <div v-if="showCompanyFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-company">Company</label>
                    <select
                        id="report-filter-company"
                        v-model="state.search.company_id"
                        class="form-select form-select-sm"
                        @change="handleCompanyChange"
                    >
                        <option value="">All</option>
                        <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                            {{ company.text ?? company.name }}
                        </option>
                    </select>
                </div>

                <div v-if="showBranchFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-branch">Branch</label>
                    <select
                        id="report-filter-branch"
                        v-model="state.search.branch_id"
                        class="form-select form-select-sm"
                        :disabled="branchFilterDisabled"
                        @change="handleBranchChange"
                    >
                        <option value="">All</option>
                        <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                            {{ branch.text ?? branch.name }}
                        </option>
                    </select>
                </div>

                <div v-if="party" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-party">{{ partyLabel }}</label>
                    <select
                        id="report-filter-party"
                        v-model="state.search.contact_id"
                        class="form-select form-select-sm"
                        :disabled="partyFilterDisabled"
                    >
                        <option value="">All</option>
                        <option v-for="item in parties" :key="item.id" :value="item.id">
                            {{ item.text ?? item.business_name }}
                        </option>
                    </select>
                </div>

                <template v-if="config.filters.productFilters">
                    <div class="col-md-4 col-lg-3 admin-filter-field">
                        <label class="form-label" for="report-filter-category">Category</label>
                        <select id="report-filter-category" v-model="state.search.category_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option v-for="category in categoriesdata" :key="category.id" :value="category.id">
                                {{ category.text ?? category.name }}
                            </option>
                        </select>
                    </div>

                    <div class="col-md-4 col-lg-3 admin-filter-field">
                        <label class="form-label" for="report-filter-brand">Brand</label>
                        <select id="report-filter-brand" v-model="state.search.brand_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option v-for="brand in brandsdata" :key="brand.id" :value="brand.id">
                                {{ brand.text ?? brand.name }}
                            </option>
                        </select>
                    </div>

                    <div class="col-md-4 col-lg-3 admin-filter-field">
                        <label class="form-label" for="report-filter-product">Product</label>
                        <select id="report-filter-product" v-model="state.search.product_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option v-for="product in productsdata" :key="product.id" :value="product.id">
                                {{ product.text ?? product.name }}
                            </option>
                        </select>
                    </div>
                </template>

                <template v-if="config.filters.transferBranches">
                    <div class="col-md-4 col-lg-3 admin-filter-field">
                        <label class="form-label" for="report-filter-from-branch">From branch</label>
                        <select id="report-filter-from-branch" v-model="state.search.from_branch_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                                {{ branch.text ?? branch.name }}
                            </option>
                        </select>
                    </div>

                    <div class="col-md-4 col-lg-3 admin-filter-field">
                        <label class="form-label" for="report-filter-to-branch">To branch</label>
                        <select id="report-filter-to-branch" v-model="state.search.to_branch_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                                {{ branch.text ?? branch.name }}
                            </option>
                        </select>
                    </div>
                </template>

                <div v-if="config.filters.byBranch" class="col-md-4 col-lg-3 admin-filter-field d-flex align-items-end">
                    <div class="form-check">
                        <input id="report-filter-by-branch" v-model="state.search.by_branch" type="checkbox" class="form-check-input" />
                        <label class="form-check-label" for="report-filter-by-branch">Show each branch separately</label>
                    </div>
                </div>

                <div v-if="config.filters.accountSelector" class="col-md-6 col-lg-4 admin-filter-field">
                    <label class="form-label" for="report-filter-account">Account</label>
                    <select id="report-filter-account" v-model="state.search.account_code" class="form-select form-select-sm">
                        <option value="">Choose an account</option>
                        <option v-for="account in accountsdata" :key="account.code" :value="account.code">
                            {{ account.text }}
                        </option>
                    </select>
                </div>

                <div v-if="config.filters.accountGroups" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-account-group">Account class</label>
                    <select id="report-filter-account-group" v-model="state.search.account_group" class="form-select form-select-sm">
                        <option v-for="option in ACCOUNT_GROUPS" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div v-if="config.filters.voucherTypes" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-voucher-type">Vouchers</label>
                    <select id="report-filter-voucher-type" v-model="state.search.voucher_type" class="form-select form-select-sm">
                        <option v-for="option in VOUCHER_TYPES" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div v-if="config.filters.topN" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-top">Number of products</label>
                    <input
                        id="report-filter-top"
                        v-model.number="state.search.top"
                        type="number"
                        min="1"
                        max="500"
                        class="form-control form-control-sm"
                    />
                </div>

                <div v-if="config.filters.taxSides" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-tax-side">Tax</label>
                    <select id="report-filter-tax-side" v-model="state.search.tax_side" class="form-select form-select-sm">
                        <option v-for="option in TAX_SIDES" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div v-if="config.filters.customerGroup" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-group">Customer group</label>
                    <select
                        id="report-filter-group"
                        v-model="state.search.customer_group_id"
                        class="form-select form-select-sm"
                        :disabled="partyFilterDisabled"
                    >
                        <option value="">All</option>
                        <option v-for="group in customergroupsdata" :key="group.id" :value="group.id">
                            {{ group.text ?? group.name }}
                        </option>
                    </select>
                </div>

                <div v-if="config.filters.contactTypes" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-contact-type">Show</label>
                    <select id="report-filter-contact-type" v-model="state.search.contact_type" class="form-select form-select-sm">
                        <option v-for="option in CONTACT_TYPES" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div v-if="config.filters.statuses" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-status">Status</label>
                    <select id="report-filter-status" v-model="state.search.status" class="form-select form-select-sm">
                        <option v-for="option in config.filters.statuses" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div v-if="config.filters.paymentStatus" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-payment">Payment status</label>
                    <select id="report-filter-payment" v-model="state.search.payment_status" class="form-select form-select-sm">
                        <option v-for="option in PAYMENT_STATUSES" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div v-if="config.filters.methods" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-method">Payment method</label>
                    <select id="report-filter-method" v-model="state.search.method" class="form-select form-select-sm">
                        <option v-for="option in PAYMENT_METHODS" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div v-if="config.filters.adjustmentTypes" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-type">Adjustment type</label>
                    <select id="report-filter-type" v-model="state.search.adjustment_type" class="form-select form-select-sm">
                        <option v-for="option in ADJUSTMENT_TYPES" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div v-if="isAsOf" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="report-filter-as-of">As of</label>
                    <input
                        id="report-filter-as-of"
                        v-model="state.search.end_date"
                        type="date"
                        class="form-control form-control-sm"
                    />
                </div>

                <div v-if="config.filters.includeZero" class="col-md-4 col-lg-3 admin-filter-field d-flex align-items-end">
                    <div class="form-check">
                        <input
                            id="report-filter-zero"
                            v-model="state.search.include_zero"
                            type="checkbox"
                            class="form-check-input"
                        />
                        <label class="form-check-label" for="report-filter-zero">Include settled contacts</label>
                    </div>
                </div>

                <div v-if="!isAsOf" class="col-md-8 col-lg-4 admin-filter-field">
                    <FiscalYearDateRange
                        v-model:start-date="state.search.start_date"
                        v-model:end-date="state.search.end_date"
                        :fiscal-year="null"
                        :id-prefix="`report-${report}`"
                        @change="constrainDates"
                    />
                </div>
            </TheFilter>

            <div class="admin-list-card__body">
                <div class="transaction-report__kpis">
                    <article
                        v-for="card in cards"
                        :key="card.key"
                        class="transaction-report__kpi"
                        :class="{ 'transaction-report__kpi--accent': card.accent }"
                    >
                        <span>{{ card.label }}</span>
                        <strong>{{ card.display }}</strong>
                    </article>
                </div>

                <p v-if="hasRange" class="transaction-report__range">
                    <template v-if="isAsOf">
                        As of <strong>{{ state.search.end_date }}</strong>
                    </template>
                    <template v-else>
                        <strong>{{ state.search.start_date }}</strong> to <strong>{{ state.search.end_date }}</strong>
                    </template>
                </p>

                <div class="admin-list-table">
                    <TheTable
                        :columns="columns"
                        :selectData="select_data"
                        :state="state"
                        :checkAll="checkAll"
                        :getData="getData"
                        :changeOrder="changeOrder"
                        actionType="link"
                        :apiUrl="`report/${report}`"
                        show-export
                        :export-file-name="config.exportName"
                        :export-title="formatedText(config.title)"
                        :export-all-rows="fetchAllRowsForExport"
                        @update:state="onStateUpdate"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.transaction-report__kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.transaction-report__kpi {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.75rem 1rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: 0.5rem;
}

.transaction-report__kpi span {
    font-size: 0.75rem;
    color: var(--bs-secondary-color, #6c757d);
}

.transaction-report__kpi strong {
    font-size: 1.05rem;
    font-variant-numeric: tabular-nums;
}

.transaction-report__kpi--accent {
    border-color: rgba(25, 150, 131, 0.4);
    background: rgba(25, 150, 131, 0.08);
}

.transaction-report__range {
    margin: 0 0 0.5rem;
    font-size: 0.8rem;
    color: var(--bs-secondary-color, #6c757d);
}
</style>
