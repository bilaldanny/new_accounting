<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import useCustomers from '@/composables/customer';
    import { formatNumber } from '@/utils/numberFormat';
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import {
        Archive,
        ArrowLeft,
        Buildings,
        Calendar,
        Cart,
        ChevronDown,
        Envelope,
        File,
        GitBranch,
        LinkAlt,
        LocationPlus,
        Note,
        Phone,
        Receipt,
        RefreshCw,
        Store,
        TrendingUp,
        User,
        Wallet,
    } from '@boxicons/vue';
    import type { Component } from 'vue';
    import { computed, onMounted, reactive, ref, watch } from 'vue';

    defineOptions({
        layout: {
            title: 'View Customer',
            subtitle: 'Customer profile, ledger and activity',
            breadcrumbs: [
                {
                    title: 'Customer Management',
                    href: '/customer',
                },
                {
                    title: 'View Customer',
                    href: 'NULL',
                },
            ],
        },
    });

    const routeProps = defineProps({
        id: {
            required: true,
            type: String,
        },
    });

    const page = usePage();

    const {
        fetchCompany,
        fetchBranch,
        companiesdata,
        branchesdata,
        formatedText,
        Notify,
    } = useCommons();

    const {
        customersdata,
        fetchCustomersDropdown,
        getContactDetail,
        getLedger,
        linkCustomerCoa,
    } = useCustomers();

    type ContactDetail = {
        id?: number | string;
        company_id?: number | string;
        branch_id?: number | string;
        contact_id?: number | string;
        business_name?: string;
        prefix?: string;
        first_name?: string;
        middle_name?: string;
        last_name?: string;
        address?: string;
        address_line_2?: string;
        zipcode?: string;
        landmark?: string;
        street_name?: string;
        building_number?: string;
        mobile?: string;
        alternate_no?: string;
        email?: string;
        date_of_birth?: string;
        zipcode?: string;
        pay_type?: string;
        pay_term?: string | number;
        user_type?: string;
        customer_gl_id?: string | null;
        link_account?: boolean | number;
        total_sell?: number;
        paid_sell?: number;
        due_sell?: number;
        currency?: { currency_name?: string; code?: string };
        country?: { name?: string };
        state?: { name?: string };
        city?: { name?: string };
        company?: { name?: string; address?: string };
        branch?: { name?: string };
    };

    type LedgerRow = {
        id: number;
        voucher_date?: string;
        voucher_no?: string;
        ref_no?: string;
        description?: string;
        debit?: number | string | null;
        credit?: number | string | null;
        acc_nature?: string;
        highlight?: number;
        balance_amount?: number;
        branch?: { name?: string };
        transaction?: { parent?: { payment_status?: string } };
        type?: string;
        cheque_no?: string;
    };

    type LedgerData = {
        taccount: LedgerRow[];
        openingbalance: number;
        total_sell: number;
        total_paid_sell: number;
    };

    type StatCard = {
        label: string;
        value: string;
        tone: 'neutral' | 'success' | 'warning' | 'danger';
        icon: Component;
    };

    type DetailItem = {
        label: string;
        value: string;
        icon?: typeof Store;
    };

    const authUser = computed(() => page.props.auth?.user as {
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
    const branchFilterDisabled = computed(() => showCompanyFilter.value && !scopeFilters.company_id);
    const contactFilterDisabled = computed(() => !scopeFilters.company_id || !scopeFilters.branch_id);

    const loading = ref(true);
    const contactLoading = ref(false);
    const ledgerLoading = ref(false);
    const refreshing = ref(false);
    const linkCoaLoading = ref(false);
    const scopeOpen = ref(false);
    const tabs = [
        { id: 'ledger', label: 'Ledger', icon: Receipt },
        { id: 'sales', label: 'Sales', icon: Cart },
        { id: 'stock', label: 'Stock', icon: Archive },
        { id: 'documents', label: 'Documents', icon: LinkAlt },
        { id: 'payments', label: 'Payments', icon: Wallet },
        { id: 'activities', label: 'Activities', icon: Note },
    ];

    const validTabIds = new Set(tabs.map((tab) => tab.id));

    function resolveTabFromUrl(url?: string): string {
        const search = url
            ? (url.includes('?') ? url.slice(url.indexOf('?')) : '')
            : window.location.search;
        const tab = new URLSearchParams(search).get('tab');

        if (tab && validTabIds.has(tab)) {
            return tab;
        }

        return 'ledger';
    }

    const activeTab = ref(resolveTabFromUrl());

    const scopeFilters = reactive({
        company_id: '',
        branch_id: '',
        contact_id: routeProps.id,
    });

    const contact = ref<ContactDetail>({});
    const ledgerData = ref<LedgerData>({
        taccount: [],
        openingbalance: 0,
        total_sell: 0,
        total_paid_sell: 0,
        total_sell: 0,
        total_paid_sell: 0,
    });

    const today = new Date();
    const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);

    const formatInputDate = (date: Date): string => date.toISOString().slice(0, 10);

    const ledgerFilters = reactive({
        start_date: formatInputDate(monthStart),
        end_date: formatInputDate(today),
    });

    const formatAmount = formatNumber;

    const currencyCode = computed(() => contact.value.currency?.code ?? '');

    const displayName = computed(() => {
        const parts = [
            contact.value.prefix,
            contact.value.first_name,
            contact.value.middle_name,
            contact.value.last_name,
        ].filter(Boolean);

        return parts.join(' ') || '-';
    });

    const businessInitials = computed(() => {
        const name = String(contact.value.business_name ?? '').trim();

        if (!name) {
            return 'SU';
        }

        const words = name.split(/\s+/).filter(Boolean);

        if (words.length >= 2) {
            return `${words[0][0] ?? ''}${words[1][0] ?? ''}`.toUpperCase();
        }

        return name.slice(0, 2).toUpperCase();
    });

    const locationLine = computed(() => {
        return [
            contact.value.city?.name,
            contact.value.state?.name,
            contact.value.country?.name,
        ].filter(Boolean).join(', ');
    });

    const formattedAddress = computed(() => {
        const lines = [
            contact.value.address,
            contact.value.address_line_2,
            [
                contact.value.street_name,
                contact.value.building_number,
            ].filter(Boolean).join(', ') || null,
            contact.value.landmark,
            [
                contact.value.city?.name,
                contact.value.state?.name,
                contact.value.country?.name,
            ].filter(Boolean).join(', ') || null,
            contact.value.zipcode,
        ].filter(Boolean);

        return lines.length > 0 ? lines.join('\n') : '-';
    });

    const userTypeLabel = computed(() => {
        const type = contact.value.user_type ?? 'customer';

        return type.charAt(0).toUpperCase() + type.slice(1);
    });

    const isCoaLinked = computed(() => Boolean(contact.value.customer_gl_id));

    const showSellStats = computed(() =>
        contact.value.user_type === 'customer' || contact.value.user_type === 'both',
    );

    const sellStatCards = computed<StatCard[]>(() => [
        {
            label: 'Total Sell',
            value: formatAmount(contact.value.total_sell),
            tone: 'neutral',
            icon: TrendingUp,
        },
        {
            label: 'Paid',
            value: formatAmount(contact.value.paid_sell),
            tone: 'success',
            icon: Wallet,
        },
        {
            label: 'Due',
            value: formatAmount(contact.value.due_sell),
            tone: 'danger',
            icon: Receipt,
        },
    ]);

    const visibleStatCards = computed(() => {
        if (showSellStats.value) {
            return sellStatCards.value;
        }

        return [];
    });

    const contactDetails = computed<DetailItem[]>(() => [
        {
            label: 'Business Name',
            value: contact.value.business_name || '-',
            icon: Store,
        },
        {
            label: 'Contact Person',
            value: displayName.value,
            icon: User,
        },
        {
            label: 'Address',
            value: formattedAddress.value,
            icon: LocationPlus,
        },
        {
            label: 'Mobile',
            value: contact.value.alternate_no
                ? `${contact.value.mobile || '-'} · Alt ${contact.value.alternate_no}`
                : (contact.value.mobile || '-'),
            icon: Phone,
        },
        {
            label: 'Email',
            value: contact.value.email || '-',
            icon: Envelope,
        },
        {
            label: 'Date of birth',
            value: contact.value.date_of_birth || '-',
            icon: Calendar,
        },
        {
            label: 'Currency',
            value: contact.value.currency
                ? `${contact.value.currency.currency_name} (${contact.value.currency.code})`
                : '-',
        },
        {
            label: 'Pay Term Period',
            value: contact.value.pay_type || '-',
            icon: Calendar,
        },
        {
            label: 'Pay Term',
            value: contact.value.pay_term ? String(contact.value.pay_term) : '-',
        },
    ]);

    const ledgerRows = computed(() => {
        const rows = [...ledgerData.value.taccount];
        let runningBalance = Number(ledgerData.value.openingbalance ?? 0);

        return rows.map((row) => {
            const debit = Number(row.debit ?? 0);
            const credit = Number(row.credit ?? 0);

            if (row.acc_nature === 'cr') {
                runningBalance = runningBalance + credit - debit;
            } else {
                runningBalance = runningBalance + debit - credit;
            }

            return {
                ...row,
                balance_amount: runningBalance,
            };
        });
    });

    const ledgerEntryCount = computed(() => ledgerRows.value.length);

    const closingBalance = computed(() => {
        if (ledgerRows.value.length === 0) {
            return Number(ledgerData.value.openingbalance ?? 0);
        }

        return ledgerRows.value[ledgerRows.value.length - 1]?.balance_amount ?? 0;
    });

    const activeTabLabel = computed(() => tabs.find((tab) => tab.id === activeTab.value)?.label ?? '');

    function paymentStatusClass(status: string | undefined): string {
        const normalized = String(status ?? '').toLowerCase();

        if (normalized === 'paid') {
            return 'customer-view-badge customer-view-badge--success';
        }

        if (normalized === 'partial') {
            return 'customer-view-badge customer-view-badge--warning';
        }

        if (normalized === 'due') {
            return 'customer-view-badge customer-view-badge--danger';
        }

        return 'customer-view-badge customer-view-badge--muted';
    }

    async function loadContactDetail() {
        if (!scopeFilters.contact_id) {
            return;
        }

        contactLoading.value = true;

        try {
            contact.value = await getContactDetail({
                contact_id: scopeFilters.contact_id,
                company_id: scopeFilters.company_id || undefined,
                branch_id: scopeFilters.branch_id || undefined,
            });

            scopeFilters.company_id = String(contact.value.company_id ?? '');
            scopeFilters.branch_id = String(contact.value.branch_id ?? '');
            scopeFilters.contact_id = String(contact.value.id ?? scopeFilters.contact_id);

            if (showCompanyFilter.value) {
                await fetchCompany();
            }

            if (showBranchFilter.value && scopeFilters.company_id) {
                await fetchBranch(scopeFilters.company_id);
            }

            if (scopeFilters.company_id && scopeFilters.branch_id) {
                await fetchCustomersDropdown(scopeFilters.company_id, scopeFilters.branch_id);
            }
        } finally {
            contactLoading.value = false;
        }
    }

    async function loadLedger() {
        if (!scopeFilters.contact_id) {
            return;
        }

        ledgerLoading.value = true;

        try {
            ledgerData.value = await getLedger({
                contact_id: scopeFilters.contact_id,
                company_id: scopeFilters.company_id || undefined,
                branch_id: scopeFilters.branch_id || undefined,
                start_date: ledgerFilters.start_date,
                end_date: ledgerFilters.end_date,
            });
        } finally {
            ledgerLoading.value = false;
        }
    }

    async function reloadCustomerView() {
        await loadContactDetail();
        await loadLedger();
    }

    async function handleRefresh() {
        refreshing.value = true;

        try {
            await reloadCustomerView();
        } finally {
            refreshing.value = false;
        }
    }

    async function handleLinkCoa() {
        if (!scopeFilters.contact_id || isCoaLinked.value || linkCoaLoading.value) {
            return;
        }

        linkCoaLoading.value = true;

        try {
            const response = await linkCustomerCoa(scopeFilters.contact_id);
            Notify(response?.message ?? 'Successfully linked to chart of account', 'success');
            await reloadCustomerView();
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                const validationMessage = error.response?.data?.errors
                    ? Object.values(error.response.data.errors as Record<string, string[]>).flat()[0]
                    : null;

                Notify(
                    validationMessage
                    || error.response?.data?.message
                    || 'Unable to link customer to chart of account',
                    'alert',
                );
            } else {
                Notify('Unable to link customer to chart of account', 'alert');
            }
        } finally {
            linkCoaLoading.value = false;
        }
    }

    async function handleCompanyChange() {
        scopeFilters.branch_id = '';
        scopeFilters.contact_id = '';
        customersdata.value = [];
        await fetchBranch(scopeFilters.company_id);
    }

    async function handleBranchChange() {
        scopeFilters.contact_id = '';
        await fetchCustomersDropdown(scopeFilters.company_id, scopeFilters.branch_id);
    }

    async function handleContactChange() {
        await reloadCustomerView();
    }

    watch(
        () => [ledgerFilters.start_date, ledgerFilters.end_date],
        async () => {
            if (scopeFilters.contact_id) {
                await loadLedger();
            }
        },
    );

    watch(
        () => page.url,
        (url) => {
            activeTab.value = resolveTabFromUrl(url);
        },
    );

    onMounted(async () => {
        scopeFilters.company_id = String(authUser.value?.company_id ?? '');
        scopeFilters.branch_id = String(authUser.value?.branch_id ?? '');

        try {
            await reloadCustomerView();
        } finally {
            loading.value = false;
        }
    });
</script>

<template>
    <Head :title="formatedText('customer.view')" />

    <div class="admin-list-page customer-view-page">
        <Loader v-if="loading" message="Loading customer view…" :fields="6" />

        <div v-else class="customer-view-layout">
            <aside class="customer-view-sidebar">
                <Loader v-if="contactLoading" message="Loading customer…" :fields="4" />

                <section v-else class="admin-list-card customer-view-profile">
                    <div class="customer-view-profile__banner" aria-hidden="true">
                        <span class="customer-view-profile__orb customer-view-profile__orb--one" />
                        <span class="customer-view-profile__orb customer-view-profile__orb--two" />
                    </div>

                    <div class="customer-view-profile__toolbar">
                        <Link href="/customer" class="customer-view-back">
                            <ArrowLeft size="xs" />
                            Customers
                        </Link>

                        <button
                            type="button"
                            class="customer-view-icon-btn"
                            title="Refresh"
                            :disabled="refreshing || contactLoading || ledgerLoading"
                            @click="handleRefresh"
                        >
                            <RefreshCw size="xs" :class="{ 'customer-view-spin': refreshing }" />
                        </button>
                    </div>

                    <div class="customer-view-profile__identity">
                        <div class="customer-view-profile__avatar">{{ businessInitials }}</div>

                        <div class="customer-view-profile__meta">
                            <div class="customer-view-profile__badges">
                                <span class="customer-view-badge customer-view-badge--primary">{{ userTypeLabel }}</span>
                                <span v-if="currencyCode" class="customer-view-badge customer-view-badge--muted">{{ currencyCode }}</span>
                            </div>

                            <h1 class="customer-view-profile__title">{{ contact.business_name || 'Customer' }}</h1>
                            <p class="customer-view-profile__person">{{ displayName }}</p>

                            <div class="customer-view-profile__chips">
                                <span v-if="contact.company?.name" class="customer-view-chip">
                                    <Buildings size="xs" />
                                    {{ contact.company.name }}
                                </span>
                                <span v-if="contact.branch?.name" class="customer-view-chip">
                                    <GitBranch size="xs" />
                                    {{ contact.branch.name }}
                                </span>
                                <span v-if="locationLine" class="customer-view-chip">
                                    <LocationPlus size="xs" />
                                    {{ locationLine }}
                                </span>
                            </div>

                            <div
                                v-if="!isCoaLinked"
                                class="customer-view-coa-alert"
                            >
                                <div class="customer-view-coa-alert__content">
                                    <Receipt size="xs" />
                                    <div>
                                        <strong>Chart of account not linked</strong>
                                        <p>This customer has no ledger account. Link one to track balances and transactions.</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="customer-view-coa-alert__action"
                                    :disabled="linkCoaLoading || contactLoading"
                                    @click="handleLinkCoa"
                                >
                                    <RefreshCw v-if="linkCoaLoading" size="xs" class="customer-view-spin" />
                                    {{ linkCoaLoading ? 'Linking…' : 'Link to COA' }}
                                </button>
                            </div>

                            <div
                                v-else
                                class="customer-view-coa-linked"
                            >
                                <Receipt size="xs" />
                                <span>COA linked</span>
                                <code>{{ contact.customer_gl_id }}</code>
                            </div>
                        </div>
                    </div>

                    <div v-if="visibleStatCards.length" class="customer-view-profile__stats">
                        <article
                            v-for="stat in visibleStatCards"
                            :key="stat.label"
                            class="customer-view-stat"
                            :class="`customer-view-stat--${stat.tone}`"
                        >
                            <span class="customer-view-stat__icon">
                                <component :is="stat.icon" size="xs" />
                            </span>
                            <div class="customer-view-stat__content">
                                <span class="customer-view-stat__label">{{ stat.label }}</span>
                                <span class="customer-view-stat__value">
                                    <small v-if="currencyCode">{{ currencyCode }}</small>
                                    {{ stat.value }}
                                </span>
                            </div>
                        </article>
                    </div>

                    <div class="customer-view-profile__details">
                        <h2 class="customer-view-profile__details-title">Contact details</h2>

                        <ul class="customer-view-info-list">
                            <li v-for="item in contactDetails" :key="item.label" class="customer-view-info-list__item">
                                <span class="customer-view-info-list__icon">
                                    <component :is="item.icon ?? Store" size="xs" />
                                </span>
                                <div class="customer-view-info-list__body">
                                    <span class="customer-view-info-list__label">{{ item.label }}</span>
                                    <span class="customer-view-info-list__value">{{ item.value }}</span>
                                </div>
                            </li>
                        </ul>
                    </div>
                </section>
            </aside>

            <div class="customer-view-main">
                <section class="admin-list-card customer-view-scope">
                    <button
                        type="button"
                        class="customer-view-scope__toggle"
                        :aria-expanded="scopeOpen"
                        @click="scopeOpen = !scopeOpen"
                    >
                        <div>
                            <span class="customer-view-scope__toggle-label">Switch customer</span>
                            <span class="customer-view-scope__toggle-hint">Change company, branch, or contact</span>
                        </div>
                        <ChevronDown size="sm" :class="{ 'customer-view-scope__chevron--open': scopeOpen }" />
                    </button>

                    <div v-show="scopeOpen" class="customer-view-scope__body">
                        <div class="row g-3">
                            <div v-if="showCompanyFilter" class="col-md-4">
                                <label class="customer-view-label" for="customer-view-company">Company</label>
                                <select
                                    id="customer-view-company"
                                    v-model="scopeFilters.company_id"
                                    class="form-select form-select-sm customer-view-select"
                                    @change="handleCompanyChange"
                                >
                                    <option value="">All companies</option>
                                    <option
                                        v-for="company in (companiesdata as Array<{ id: number | string; text?: string; name?: string }>)"
                                        :key="company.id"
                                        :value="company.id"
                                    >
                                        {{ company.text ?? company.name }}
                                    </option>
                                </select>
                            </div>

                            <div v-if="showBranchFilter" class="col-md-4">
                                <label class="customer-view-label" for="customer-view-branch">Branch</label>
                                <select
                                    id="customer-view-branch"
                                    v-model="scopeFilters.branch_id"
                                    class="form-select form-select-sm customer-view-select"
                                    :disabled="branchFilterDisabled"
                                    @change="handleBranchChange"
                                >
                                    <option value="">All branches</option>
                                    <option
                                        v-for="branch in (branchesdata as Array<{ id: number | string; text?: string; name?: string }>)"
                                        :key="branch.id"
                                        :value="branch.id"
                                    >
                                        {{ branch.text ?? branch.name }}
                                    </option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="customer-view-label" for="customer-view-contact">Customer</label>
                                <select
                                    id="customer-view-contact"
                                    v-model="scopeFilters.contact_id"
                                    class="form-select form-select-sm customer-view-select"
                                    :disabled="contactFilterDisabled"
                                    @change="handleContactChange"
                                >
                                    <option value="">Select customer</option>
                                    <option v-for="customer in customersdata" :key="customer.id" :value="customer.id">
                                        {{ customer.text ?? customer.business_name }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="admin-list-card customer-view-workspace">
                    <div class="customer-view-workspace__head">
                        <div>
                            <h2 class="customer-view-workspace__title">Activity & ledger</h2>
                            <p class="customer-view-workspace__subtitle">Review transactions, Sales, and account history</p>
                        </div>

                        <div v-if="activeTab === 'ledger'" class="customer-view-kpis">
                            <div class="customer-view-kpi">
                                <span class="customer-view-kpi__label">Entries</span>
                                <span class="customer-view-kpi__value">{{ ledgerEntryCount }}</span>
                            </div>
                            <div class="customer-view-kpi customer-view-kpi--accent">
                                <span class="customer-view-kpi__label">Closing balance</span>
                                <span class="customer-view-kpi__value">{{ formatAmount(closingBalance) }}</span>
                            </div>
                        </div>
                    </div>

                    <nav class="customer-view-tabs" aria-label="Customer activity sections">
                        <button
                            v-for="tab in tabs"
                            :key="tab.id"
                            type="button"
                            class="customer-view-tabs__btn"
                            :class="{ 'customer-view-tabs__btn--active': activeTab === tab.id }"
                            :aria-selected="activeTab === tab.id"
                            @click="activeTab = tab.id"
                        >
                            <component :is="tab.icon" size="xs" />
                            <span>{{ tab.label }}</span>
                        </button>
                    </nav>

                    <div class="customer-view-tabs__panel">
                        <div v-if="activeTab === 'ledger'" class="customer-view-ledger">
                            <div class="customer-view-ledger-toolbar">
                                <div class="customer-view-ledger-toolbar__copy">
                                    <h3 class="customer-view-ledger-toolbar__title">Account ledger</h3>
                                    <p class="customer-view-ledger-toolbar__subtitle">
                                        Period statement for {{ contact.business_name || 'This customer' }}
                                    </p>
                                </div>

                                <div class="customer-view-date-range">
                                    <div class="customer-view-date-field">
                                        <label class="customer-view-label" for="ledger-start-date">From</label>
                                        <input
                                            id="ledger-start-date"
                                            v-model="ledgerFilters.start_date"
                                            type="date"
                                            class="form-control form-control-sm customer-view-date-input"
                                        />
                                    </div>
                                    <span class="customer-view-date-range__sep" aria-hidden="true">→</span>
                                    <div class="customer-view-date-field">
                                        <label class="customer-view-label" for="ledger-end-date">To</label>
                                        <input
                                            id="ledger-end-date"
                                            v-model="ledgerFilters.end_date"
                                            type="date"
                                            class="form-control form-control-sm customer-view-date-input"
                                        />
                                    </div>
                                </div>
                            </div>

                            <Loader v-if="ledgerLoading" message="Loading ledger…" :fields="4" />

                            <template v-else>
                                <div class="customer-view-ledger-grid">
                                    <article class="customer-view-summary-card">
                                        <div class="customer-view-summary-card__head">
                                            <User size="xs" />
                                            <h4>Bill to</h4>
                                        </div>
                                        <p class="customer-view-summary-card__name">{{ displayName }}</p>
                                        <p v-if="contact.business_name" class="customer-view-summary-card__line">{{ contact.business_name }}</p>
                                        <p v-if="contact.address" class="customer-view-summary-card__line">{{ contact.address }}</p>
                                        <p v-if="locationLine" class="customer-view-summary-card__line">{{ locationLine }}</p>
                                        <div class="customer-view-summary-card__contacts">
                                            <span><Envelope size="xs" /> {{ contact.email || '-' }}</span>
                                            <span><Phone size="xs" /> {{ contact.mobile || '-' }}</span>
                                        </div>
                                    </article>

                                    <article class="customer-view-summary-card customer-view-summary-card--accent">
                                        <div class="customer-view-summary-card__head">
                                            <Receipt size="xs" />
                                            <h4>Account summary</h4>
                                        </div>
                                        <p class="customer-view-summary-card__period">
                                            {{ ledgerFilters.start_date }} — {{ ledgerFilters.end_date }}
                                        </p>
                                        <dl class="customer-view-summary-list">
                                            <div class="customer-view-summary-list__row">
                                                <dt>Opening balance</dt>
                                                <dd>{{ currencyCode }} {{ formatAmount(ledgerData.openingbalance) }}</dd>
                                            </div>
                                            <div v-if="showSellStats" class="customer-view-summary-list__row">
                                                <dt>Total sell</dt>
                                                <dd>{{ currencyCode }} {{ formatAmount(ledgerData.total_sell) }}</dd>
                                            </div>
                                            <div v-if="showSellStats" class="customer-view-summary-list__row">
                                                <dt>Sell paid</dt>
                                                <dd>{{ currencyCode }} {{ formatAmount(ledgerData.total_paid_sell) }}</dd>
                                            </div>
                                            <div class="customer-view-summary-list__row customer-view-summary-list__row--total">
                                                <dt>Closing balance</dt>
                                                <dd>{{ currencyCode }} {{ formatAmount(closingBalance) }}</dd>
                                            </div>
                                        </dl>
                                    </article>
                                </div>

                                <div class="customer-view-table-head">
                                    <p>
                                        Statement from <strong>{{ ledgerFilters.start_date }}</strong> to
                                        <strong>{{ ledgerFilters.end_date }}</strong>
                                    </p>
                                    <span class="customer-view-table-head__count">{{ ledgerEntryCount }} entries</span>
                                </div>

                                <div class="customer-view-table-wrap">
                                    <table class="table customer-view-table">
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
                                            <tr class="customer-view-table__opening">
                                                <td>{{ ledgerFilters.start_date }}</td>
                                                <td colspan="4">Opening balance</td>
                                                <td></td>
                                                <td class="text-end customer-view-amount"></td>
                                                <td class="text-end customer-view-amount"></td>
                                                <td class="text-end customer-view-amount customer-view-amount--strong">
                                                    {{ formatAmount(ledgerData.openingbalance) }}
                                                </td>
                                                <td colspan="2"></td>
                                            </tr>
                                            <tr
                                                v-for="row in ledgerRows"
                                                :key="row.id"
                                                class="customer-view-table__row"
                                                :class="{ 'customer-view-table__highlight': row.highlight === 1 }"
                                            >
                                                <td>{{ row.voucher_date || '-' }}</td>
                                                <td class="customer-view-table__mono">{{ row.voucher_no || '-' }}</td>
                                                <td class="customer-view-table__mono">{{ row.ref_no || '-' }}</td>
                                                <td class="customer-view-table__narration" v-html="row.description || '-'" />
                                                <td>{{ row.branch?.name || '-' }}</td>
                                                <td>
                                                    <span :class="paymentStatusClass(row.transaction?.parent?.payment_status)">
                                                        {{ row.transaction?.parent?.payment_status || '-' }}
                                                    </span>
                                                </td>
                                                <td class="text-end customer-view-amount">{{ row.debit ? formatAmount(row.debit) : '-' }}</td>
                                                <td class="text-end customer-view-amount">{{ row.credit ? formatAmount(row.credit) : '-' }}</td>
                                                <td class="text-end customer-view-amount customer-view-amount--strong">
                                                    {{ formatAmount(row.balance_amount) }}
                                                </td>
                                                <td>{{ row.type || '-' }}</td>
                                                <td>{{ row.cheque_no || '-' }}</td>
                                            </tr>
                                            <tr v-if="ledgerRows.length === 0">
                                                <td colspan="11">
                                                    <div class="customer-view-empty customer-view-empty--inline">
                                                        <Receipt size="sm" />
                                                        <p>No ledger entries for this period.</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
                        </div>

                        <div v-else class="customer-view-empty">
                            <div class="customer-view-empty__icon">
                                <File size="md" />
                            </div>
                            <h3>{{ activeTabLabel }}</h3>
                            <p>This section is coming soon.</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
.customer-view-page {
    --sv-accent: #199683;
    --sv-accent-dark: #0f766e;
    --sv-accent-soft: rgba(25, 150, 131, 0.1);
    --sv-accent-border: rgba(25, 150, 131, 0.18);
}

.customer-view-layout {
    display: grid;
    grid-template-columns: minmax(300px, 360px) minmax(0, 1fr);
    gap: 1rem;
    align-items: start;
}

.customer-view-sidebar {
    position: sticky;
    top: 1rem;
}

.customer-view-profile {
    position: relative;
    overflow: hidden;
    padding: 0;
}

.customer-view-profile__banner {
    position: relative;
    height: 5.5rem;
    background: linear-gradient(135deg, #199683 0%, #0f766e 55%, #115e59 100%);
}

.customer-view-profile__orb {
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.12);
}

.customer-view-profile__orb--one {
    width: 7rem;
    height: 7rem;
    top: -2rem;
    right: -1rem;
}

.customer-view-profile__orb--two {
    width: 4rem;
    height: 4rem;
    bottom: -1.5rem;
    left: 1rem;
}

.customer-view-profile__toolbar {
    position: absolute;
    top: 0.75rem;
    left: 0.875rem;
    right: 0.875rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    z-index: 2;
}

.customer-view-back {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.35rem 0.65rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #fff;
    text-decoration: none;
    background: rgba(255, 255, 255, 0.14);
    backdrop-filter: blur(6px);
    border: 1px solid rgba(255, 255, 255, 0.18);
}

.customer-view-back:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.22);
}

.customer-view-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.14);
    color: #fff;
    backdrop-filter: blur(6px);
    transition: background-color 0.15s ease;
}

.customer-view-icon-btn:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.24);
}

.customer-view-icon-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.customer-view-spin {
    animation: customer-view-spin 0.8s linear infinite;
}

@keyframes customer-view-spin {
    to {
        transform: rotate(360deg);
    }
}

.customer-view-profile__identity {
    display: flex;
    gap: 0.875rem;
    padding: 0 1.125rem 1rem;
    margin-top: -2rem;
    position: relative;
    z-index: 1;
}

.customer-view-profile__avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 4.5rem;
    height: 4.5rem;
    border-radius: 1.125rem;
    background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
    color: var(--sv-accent-dark);
    font-size: 1.125rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    border: 3px solid #fff;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
    flex-shrink: 0;
}

.customer-view-profile__meta {
    min-width: 0;
    padding-top: 2.25rem;
}

.customer-view-coa-alert {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-top: 0.875rem;
    padding: 0.875rem 1rem;
    border-radius: 0.875rem;
    border: 1px solid rgba(245, 158, 11, 0.25);
    background: linear-gradient(135deg, rgba(255, 251, 235, 0.95) 0%, rgba(254, 243, 199, 0.65) 100%);
}

.customer-view-coa-alert__content {
    display: flex;
    gap: 0.625rem;
    align-items: flex-start;
    min-width: 0;
    color: #92400e;
}

.customer-view-coa-alert__content strong {
    display: block;
    font-size: 0.8125rem;
    margin-bottom: 0.125rem;
}

.customer-view-coa-alert__content p {
    margin: 0;
    font-size: 0.75rem;
    line-height: 1.45;
    color: #b45309;
}

.customer-view-coa-alert__action {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.5rem 0.875rem;
    border: none;
    border-radius: 999px;
    background: #d97706;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 700;
    white-space: nowrap;
    transition: background 0.2s ease;
}

.customer-view-coa-alert__action:hover:not(:disabled) {
    background: #b45309;
}

.customer-view-coa-alert__action:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.customer-view-coa-linked {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.875rem;
    padding: 0.5rem 0.75rem;
    border-radius: 999px;
    background: rgba(16, 185, 129, 0.12);
    color: #047857;
    font-size: 0.75rem;
    font-weight: 600;
}

.customer-view-coa-linked code {
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.75);
    color: #065f46;
    font-size: 0.6875rem;
}

.customer-view-profile__badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin-bottom: 0.5rem;
}

.customer-view-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.18rem 0.55rem;
    border-radius: 999px;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.customer-view-badge--primary {
    color: var(--sv-accent-dark);
    background: var(--sv-accent-soft);
    border: 1px solid var(--sv-accent-border);
}

.customer-view-badge--muted {
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
}

.customer-view-badge--success {
    color: #15803d;
    background: rgba(34, 197, 94, 0.12);
}

.customer-view-badge--warning {
    color: #b45309;
    background: rgba(245, 158, 11, 0.12);
}

.customer-view-badge--danger {
    color: #b91c1c;
    background: rgba(239, 68, 68, 0.12);
}

.customer-view-profile__title {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 800;
    line-height: 1.25;
    color: var(--app-text, #111827);
}

.customer-view-profile__person {
    margin: 0.25rem 0 0.625rem;
    font-size: 0.8125rem;
    color: var(--app-text-secondary, #64748b);
}

.customer-view-profile__chips {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.customer-view-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
    color: #475569;
}

.customer-view-profile__stats {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    padding: 0 1.125rem 1rem;
}

.customer-view-stat {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 0.875rem;
    border-radius: 0.875rem;
    background: #f8fafc;
    border: 1px solid #eef2f7;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.customer-view-stat:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}

.customer-view-stat--success {
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.08) 0%, rgba(34, 197, 94, 0.02) 100%);
    border-color: rgba(34, 197, 94, 0.18);
}

.customer-view-stat--danger {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.08) 0%, rgba(239, 68, 68, 0.02) 100%);
    border-color: rgba(239, 68, 68, 0.18);
}

.customer-view-stat__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.625rem;
    background: #fff;
    color: var(--sv-accent-dark);
    border: 1px solid #e5e7eb;
    flex-shrink: 0;
}

.customer-view-stat--success .customer-view-stat__icon {
    color: #15803d;
}

.customer-view-stat--danger .customer-view-stat__icon {
    color: #b91c1c;
}

.customer-view-stat__content {
    min-width: 0;
}

.customer-view-stat__label {
    display: block;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--app-text-muted, #94a3b8);
}

.customer-view-stat__value {
    display: block;
    font-size: 1rem;
    font-weight: 800;
    color: var(--app-text, #111827);
    font-variant-numeric: tabular-nums;
}

.customer-view-stat__value small {
    margin-right: 0.25rem;
    font-size: 0.6875rem;
    font-weight: 700;
    color: var(--app-text-secondary, #64748b);
}

.customer-view-profile__details {
    padding: 0 1.125rem 1.125rem;
    border-top: 1px solid var(--app-border-subtle, #f1f5f9);
}

.customer-view-profile__details-title {
    margin: 1rem 0 0.75rem;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--app-text-muted, #94a3b8);
}

.customer-view-info-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.customer-view-info-list__item {
    display: flex;
    gap: 0.75rem;
    padding: 0.625rem 0.75rem;
    border-radius: 0.75rem;
    background: #fafbfc;
    border: 1px solid #eef2f7;
}

.customer-view-info-list__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 0.5rem;
    background: var(--sv-accent-soft);
    color: var(--sv-accent-dark);
    flex-shrink: 0;
}

.customer-view-info-list__body {
    min-width: 0;
}

.customer-view-info-list__label {
    display: block;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--app-text-muted, #94a3b8);
}

.customer-view-info-list__value {
    display: block;
    margin-top: 0.125rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: #334155;
    line-height: 1.45;
    word-break: break-word;
}

.customer-view-main {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    min-width: 0;
}

.customer-view-scope__toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    width: 100%;
    padding: 0.875rem 1.125rem;
    border: 0;
    background: transparent;
    text-align: left;
}

.customer-view-scope__toggle-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--app-text, #111827);
}

.customer-view-scope__toggle-hint {
    display: block;
    margin-top: 0.125rem;
    font-size: 0.75rem;
    color: var(--app-text-secondary, #64748b);
}

.customer-view-scope__chevron--open {
    transform: rotate(180deg);
}

.customer-view-scope__toggle svg {
    transition: transform 0.2s ease;
    color: var(--app-text-secondary, #64748b);
    flex-shrink: 0;
}

.customer-view-scope__body {
    padding: 0 1.125rem 1.125rem;
    border-top: 1px solid var(--app-border-subtle, #f1f5f9);
}

.customer-view-label {
    display: block;
    margin-bottom: 0.375rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--app-text-secondary, #64748b);
}

.customer-view-select,
.customer-view-date-input {
    border-radius: 0.625rem;
    border-color: var(--app-border, #e5e7eb);
    min-height: 2.375rem;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.03);
}

.customer-view-workspace__head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.125rem 1.25rem 0;
}

.customer-view-workspace__title {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
    color: var(--app-text, #111827);
}

.customer-view-workspace__subtitle {
    margin: 0.25rem 0 0;
    font-size: 0.8125rem;
    color: var(--app-text-secondary, #64748b);
}

.customer-view-kpis {
    display: flex;
    gap: 0.625rem;
}

.customer-view-kpi {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    padding: 0.5rem 0.75rem;
    border-radius: 0.75rem;
    background: #f8fafc;
    border: 1px solid #eef2f7;
}

.customer-view-kpi--accent {
    background: var(--sv-accent-soft);
    border-color: var(--sv-accent-border);
}

.customer-view-kpi__label {
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--app-text-muted, #94a3b8);
}

.customer-view-kpi__value {
    font-size: 0.9375rem;
    font-weight: 800;
    color: var(--app-text, #111827);
    font-variant-numeric: tabular-nums;
}

.customer-view-kpi--accent .customer-view-kpi__value {
    color: var(--sv-accent-dark);
}

.customer-view-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 1rem 1.25rem 0;
}

.customer-view-tabs__btn {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.5rem 0.875rem;
    border: 1px solid #e5e7eb;
    border-radius: 999px;
    background: #fff;
    color: #64748b;
    font-size: 0.8125rem;
    font-weight: 600;
    transition: all 0.15s ease;
}

.customer-view-tabs__btn:hover {
    color: #334155;
    border-color: #cbd5e1;
    background: #f8fafc;
}

.customer-view-tabs__btn--active {
    color: #fff;
    background: linear-gradient(135deg, var(--sv-accent) 0%, var(--sv-accent-dark) 100%);
    border-color: transparent;
    box-shadow: 0 8px 18px rgba(25, 150, 131, 0.24);
}

.customer-view-tabs__panel {
    padding: 1.125rem 1.25rem 1.25rem;
}

.customer-view-ledger-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.customer-view-ledger-toolbar__title {
    margin: 0;
    font-size: 0.9375rem;
    font-weight: 700;
    color: var(--app-text, #111827);
}

.customer-view-ledger-toolbar__subtitle {
    margin: 0.25rem 0 0;
    font-size: 0.8125rem;
    color: var(--app-text-secondary, #64748b);
}

.customer-view-date-range {
    display: flex;
    align-items: flex-end;
    gap: 0.625rem;
    padding: 0.75rem;
    border-radius: 0.875rem;
    background: #f8fafc;
    border: 1px solid #eef2f7;
}

.customer-view-date-range__sep {
    padding-bottom: 0.55rem;
    color: var(--app-text-muted, #94a3b8);
    font-weight: 700;
}

.customer-view-date-field {
    min-width: 9.5rem;
}

.customer-view-ledger-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.875rem;
    margin-bottom: 1rem;
}

.customer-view-summary-card {
    height: 100%;
    padding: 1rem 1.125rem;
    border-radius: 0.875rem;
    background: #f8fafc;
    border: 1px solid #eef2f7;
}

.customer-view-summary-card--accent {
    background: linear-gradient(135deg, rgba(25, 150, 131, 0.08) 0%, rgba(25, 150, 131, 0.02) 100%);
    border-color: var(--sv-accent-border);
}

.customer-view-summary-card__head {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
    color: var(--sv-accent-dark);
}

.customer-view-summary-card__head h4 {
    margin: 0;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.customer-view-summary-card__name {
    margin: 0 0 0.375rem;
    font-size: 1rem;
    font-weight: 800;
    color: var(--app-text, #111827);
}

.customer-view-summary-card__line {
    margin: 0 0 0.25rem;
    font-size: 0.8125rem;
    color: #475569;
}

.customer-view-summary-card__contacts {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px dashed #e2e8f0;
}

.customer-view-summary-card__contacts span {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.8125rem;
    color: #475569;
}

.customer-view-summary-card__period {
    margin: 0 0 0.875rem;
    font-size: 0.8125rem;
    color: var(--app-text-secondary, #64748b);
}

.customer-view-summary-list {
    margin: 0;
}

.customer-view-summary-list__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.5rem 0;
    border-bottom: 1px dashed rgba(25, 150, 131, 0.16);
}

.customer-view-summary-list__row:last-child {
    border-bottom: 0;
}

.customer-view-summary-list__row--total {
    margin-top: 0.25rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--sv-accent-border);
    border-bottom: 0;
}

.customer-view-summary-list__row dt {
    margin: 0;
    font-size: 0.8125rem;
    color: #475569;
}

.customer-view-summary-list__row dd {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 800;
    color: var(--app-text, #111827);
    font-variant-numeric: tabular-nums;
}

.customer-view-summary-list__row--total dd {
    color: var(--sv-accent-dark);
}

.customer-view-table-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.75rem;
    padding: 0.625rem 0.875rem;
    border-radius: 0.75rem;
    background: #f8fafc;
    border: 1px solid #eef2f7;
}

.customer-view-table-head p {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--app-text-secondary, #64748b);
}

.customer-view-table-head__count {
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--sv-accent-dark);
    background: var(--sv-accent-soft);
    padding: 0.25rem 0.625rem;
    border-radius: 999px;
}

.customer-view-table-wrap {
    overflow: auto;
    border: 1px solid #eef2f7;
    border-radius: 0.875rem;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
}

.customer-view-table {
    margin: 0;
    font-size: 0.8125rem;
}

.customer-view-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: #f1f5f9;
    border-bottom: 1px solid #e2e8f0;
    font-size: 0.625rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #64748b;
    white-space: nowrap;
    padding-top: 0.75rem;
    padding-bottom: 0.75rem;
}

.customer-view-table tbody td {
    vertical-align: middle;
    border-color: #f1f5f9;
}

.customer-view-table__row:hover {
    background: rgba(248, 250, 252, 0.9);
}

.customer-view-table__opening {
    background: rgba(25, 150, 131, 0.05);
}

.customer-view-table__highlight {
    background: var(--sv-accent-dark) !important;
    color: #fff;
}

.customer-view-table__highlight .customer-view-badge {
    background: rgba(255, 255, 255, 0.16);
    color: #fff;
}

.customer-view-table__mono {
    font-family: var(--app-font-mono);
    font-size: 0.75rem;
}

.customer-view-table__narration {
    max-width: 14rem;
    white-space: normal;
}

.customer-view-amount {
    font-variant-numeric: tabular-nums;
}

.customer-view-amount--strong {
    font-weight: 800;
    color: var(--sv-accent-dark);
}

.customer-view-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 3rem 1rem;
    text-align: center;
    color: var(--app-text-secondary, #64748b);
}

.customer-view-empty__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid #eef2f7;
    color: var(--sv-accent-dark);
}

.customer-view-empty h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
    color: var(--app-text, #111827);
}

.customer-view-empty p {
    margin: 0;
    font-size: 0.875rem;
}

.customer-view-empty--inline {
    padding: 1.5rem 1rem;
}

@media (max-width: 1199.98px) {
    .customer-view-layout {
        grid-template-columns: 1fr;
    }

    .customer-view-sidebar {
        position: static;
    }
}

@media (max-width: 991.98px) {
    .customer-view-ledger-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767.98px) {
    .customer-view-kpis {
        width: 100%;
    }

    .customer-view-kpi {
        flex: 1;
        align-items: flex-start;
    }

    .customer-view-date-range {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
    }

    .customer-view-date-range__sep {
        display: none;
    }

    .customer-view-date-field {
        min-width: 0;
        width: 100%;
    }

    .customer-view-ledger-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .customer-view-table-head {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
