<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import useSuppliers from '@/composables/supplier';
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
            title: 'View Supplier',
            subtitle: 'Supplier profile, ledger and activity',
            breadcrumbs: [
                {
                    title: 'Supplier Management',
                    href: '/supplier',
                },
                {
                    title: 'View Supplier',
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
        suppliersdata,
        fetchSuppliersDropdown,
        getContactDetail,
        getLedger,
        linkSupplierCoa,
    } = useSuppliers();

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
        mobile?: string;
        alternate_no?: string;
        email?: string;
        zipcode?: string;
        pay_type?: string;
        pay_term?: string | number;
        user_type?: string;
        supplier_gl_id?: string | null;
        link_account?: boolean | number;
        total_purchase?: number;
        paid_purchase?: number;
        due_purchase?: number;
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
        total_purchase: number;
        total_paid_purchase: number;
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
    const activeTab = ref('ledger');

    const scopeFilters = reactive({
        company_id: '',
        branch_id: '',
        contact_id: routeProps.id,
    });

    const contact = ref<ContactDetail>({});
    const ledgerData = ref<LedgerData>({
        taccount: [],
        openingbalance: 0,
        total_purchase: 0,
        total_paid_purchase: 0,
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

    const tabs = [
        { id: 'ledger', label: 'Ledger', icon: Receipt },
        { id: 'purchases', label: 'Purchases', icon: Cart },
        { id: 'stock', label: 'Stock', icon: Archive },
        { id: 'documents', label: 'Documents', icon: LinkAlt },
        { id: 'payments', label: 'Payments', icon: Wallet },
        { id: 'activities', label: 'Activities', icon: Note },
    ];

    const formatAmount = (value: unknown): string =>
        Number(value ?? 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

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

    const userTypeLabel = computed(() => {
        const type = contact.value.user_type ?? 'supplier';

        return type.charAt(0).toUpperCase() + type.slice(1);
    });

    const isCoaLinked = computed(() => Boolean(contact.value.supplier_gl_id));

    const showPurchaseStats = computed(() =>
        contact.value.user_type === 'supplier' || contact.value.user_type === 'both',
    );

    const showSellStats = computed(() =>
        contact.value.user_type === 'customer' || contact.value.user_type === 'both',
    );

    const purchaseStatCards = computed<StatCard[]>(() => [
        {
            label: 'Total Purchase',
            value: formatAmount(contact.value.total_purchase),
            tone: 'neutral',
            icon: TrendingUp,
        },
        {
            label: 'Paid',
            value: formatAmount(contact.value.paid_purchase),
            tone: 'success',
            icon: Wallet,
        },
        {
            label: 'Due',
            value: formatAmount(contact.value.due_purchase),
            tone: 'danger',
            icon: Receipt,
        },
    ]);

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
        if (showPurchaseStats.value) {
            return purchaseStatCards.value;
        }

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
            value: contact.value.address || '-',
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
            return 'supplier-view-badge supplier-view-badge--success';
        }

        if (normalized === 'partial') {
            return 'supplier-view-badge supplier-view-badge--warning';
        }

        if (normalized === 'due') {
            return 'supplier-view-badge supplier-view-badge--danger';
        }

        return 'supplier-view-badge supplier-view-badge--muted';
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
                await fetchSuppliersDropdown(scopeFilters.company_id, scopeFilters.branch_id);
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

    async function reloadSupplierView() {
        await loadContactDetail();
        await loadLedger();
    }

    async function handleRefresh() {
        refreshing.value = true;

        try {
            await reloadSupplierView();
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
            const response = await linkSupplierCoa(scopeFilters.contact_id);
            Notify(response?.message ?? 'Successfully linked to chart of account', 'success');
            await reloadSupplierView();
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                const validationMessage = error.response?.data?.errors
                    ? Object.values(error.response.data.errors as Record<string, string[]>).flat()[0]
                    : null;

                Notify(
                    validationMessage
                    || error.response?.data?.message
                    || 'Unable to link supplier to chart of account',
                    'alert',
                );
            } else {
                Notify('Unable to link supplier to chart of account', 'alert');
            }
        } finally {
            linkCoaLoading.value = false;
        }
    }

    async function handleCompanyChange() {
        scopeFilters.branch_id = '';
        scopeFilters.contact_id = '';
        suppliersdata.value = [];
        await fetchBranch(scopeFilters.company_id);
    }

    async function handleBranchChange() {
        scopeFilters.contact_id = '';
        await fetchSuppliersDropdown(scopeFilters.company_id, scopeFilters.branch_id);
    }

    async function handleContactChange() {
        await reloadSupplierView();
    }

    watch(
        () => [ledgerFilters.start_date, ledgerFilters.end_date],
        async () => {
            if (scopeFilters.contact_id) {
                await loadLedger();
            }
        },
    );

    onMounted(async () => {
        scopeFilters.company_id = String(authUser.value?.company_id ?? '');
        scopeFilters.branch_id = String(authUser.value?.branch_id ?? '');

        try {
            await reloadSupplierView();
        } finally {
            loading.value = false;
        }
    });
</script>

<template>
    <Head :title="formatedText('supplier.view')" />

    <div class="admin-list-page supplier-view-page">
        <Loader v-if="loading" message="Loading supplier view…" :fields="6" />

        <div v-else class="supplier-view-layout">
            <aside class="supplier-view-sidebar">
                <Loader v-if="contactLoading" message="Loading supplier…" :fields="4" />

                <section v-else class="admin-list-card supplier-view-profile">
                    <div class="supplier-view-profile__banner" aria-hidden="true">
                        <span class="supplier-view-profile__orb supplier-view-profile__orb--one" />
                        <span class="supplier-view-profile__orb supplier-view-profile__orb--two" />
                    </div>

                    <div class="supplier-view-profile__toolbar">
                        <Link href="/supplier" class="supplier-view-back">
                            <ArrowLeft size="xs" />
                            Suppliers
                        </Link>

                        <button
                            type="button"
                            class="supplier-view-icon-btn"
                            title="Refresh"
                            :disabled="refreshing || contactLoading || ledgerLoading"
                            @click="handleRefresh"
                        >
                            <RefreshCw size="xs" :class="{ 'supplier-view-spin': refreshing }" />
                        </button>
                    </div>

                    <div class="supplier-view-profile__identity">
                        <div class="supplier-view-profile__avatar">{{ businessInitials }}</div>

                        <div class="supplier-view-profile__meta">
                            <div class="supplier-view-profile__badges">
                                <span class="supplier-view-badge supplier-view-badge--primary">{{ userTypeLabel }}</span>
                                <span v-if="currencyCode" class="supplier-view-badge supplier-view-badge--muted">{{ currencyCode }}</span>
                            </div>

                            <h1 class="supplier-view-profile__title">{{ contact.business_name || 'Supplier' }}</h1>
                            <p class="supplier-view-profile__person">{{ displayName }}</p>

                            <div class="supplier-view-profile__chips">
                                <span v-if="contact.company?.name" class="supplier-view-chip">
                                    <Buildings size="xs" />
                                    {{ contact.company.name }}
                                </span>
                                <span v-if="contact.branch?.name" class="supplier-view-chip">
                                    <GitBranch size="xs" />
                                    {{ contact.branch.name }}
                                </span>
                                <span v-if="locationLine" class="supplier-view-chip">
                                    <LocationPlus size="xs" />
                                    {{ locationLine }}
                                </span>
                            </div>

                            <div
                                v-if="!isCoaLinked"
                                class="supplier-view-coa-alert"
                            >
                                <div class="supplier-view-coa-alert__content">
                                    <Receipt size="xs" />
                                    <div>
                                        <strong>Chart of account not linked</strong>
                                        <p>This supplier has no ledger account. Link one to track balances and transactions.</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="supplier-view-coa-alert__action"
                                    :disabled="linkCoaLoading || contactLoading"
                                    @click="handleLinkCoa"
                                >
                                    <RefreshCw v-if="linkCoaLoading" size="xs" class="supplier-view-spin" />
                                    {{ linkCoaLoading ? 'Linking…' : 'Link to COA' }}
                                </button>
                            </div>

                            <div
                                v-else
                                class="supplier-view-coa-linked"
                            >
                                <Receipt size="xs" />
                                <span>COA linked</span>
                                <code>{{ contact.supplier_gl_id }}</code>
                            </div>
                        </div>
                    </div>

                    <div v-if="visibleStatCards.length" class="supplier-view-profile__stats">
                        <article
                            v-for="stat in visibleStatCards"
                            :key="stat.label"
                            class="supplier-view-stat"
                            :class="`supplier-view-stat--${stat.tone}`"
                        >
                            <span class="supplier-view-stat__icon">
                                <component :is="stat.icon" size="xs" />
                            </span>
                            <div class="supplier-view-stat__content">
                                <span class="supplier-view-stat__label">{{ stat.label }}</span>
                                <span class="supplier-view-stat__value">
                                    <small v-if="currencyCode">{{ currencyCode }}</small>
                                    {{ stat.value }}
                                </span>
                            </div>
                        </article>
                    </div>

                    <div class="supplier-view-profile__details">
                        <h2 class="supplier-view-profile__details-title">Contact details</h2>

                        <ul class="supplier-view-info-list">
                            <li v-for="item in contactDetails" :key="item.label" class="supplier-view-info-list__item">
                                <span class="supplier-view-info-list__icon">
                                    <component :is="item.icon ?? Store" size="xs" />
                                </span>
                                <div class="supplier-view-info-list__body">
                                    <span class="supplier-view-info-list__label">{{ item.label }}</span>
                                    <span class="supplier-view-info-list__value">{{ item.value }}</span>
                                </div>
                            </li>
                        </ul>
                    </div>
                </section>
            </aside>

            <div class="supplier-view-main">
                <section class="admin-list-card supplier-view-scope">
                    <button
                        type="button"
                        class="supplier-view-scope__toggle"
                        :aria-expanded="scopeOpen"
                        @click="scopeOpen = !scopeOpen"
                    >
                        <div>
                            <span class="supplier-view-scope__toggle-label">Switch supplier</span>
                            <span class="supplier-view-scope__toggle-hint">Change company, branch, or contact</span>
                        </div>
                        <ChevronDown size="sm" :class="{ 'supplier-view-scope__chevron--open': scopeOpen }" />
                    </button>

                    <div v-show="scopeOpen" class="supplier-view-scope__body">
                        <div class="row g-3">
                            <div v-if="showCompanyFilter" class="col-md-4">
                                <label class="supplier-view-label" for="supplier-view-company">Company</label>
                                <select
                                    id="supplier-view-company"
                                    v-model="scopeFilters.company_id"
                                    class="form-select form-select-sm supplier-view-select"
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
                                <label class="supplier-view-label" for="supplier-view-branch">Branch</label>
                                <select
                                    id="supplier-view-branch"
                                    v-model="scopeFilters.branch_id"
                                    class="form-select form-select-sm supplier-view-select"
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
                                <label class="supplier-view-label" for="supplier-view-contact">Supplier</label>
                                <select
                                    id="supplier-view-contact"
                                    v-model="scopeFilters.contact_id"
                                    class="form-select form-select-sm supplier-view-select"
                                    :disabled="contactFilterDisabled"
                                    @change="handleContactChange"
                                >
                                    <option value="">Select supplier</option>
                                    <option v-for="supplier in suppliersdata" :key="supplier.id" :value="supplier.id">
                                        {{ supplier.text ?? supplier.business_name }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="admin-list-card supplier-view-workspace">
                    <div class="supplier-view-workspace__head">
                        <div>
                            <h2 class="supplier-view-workspace__title">Activity & ledger</h2>
                            <p class="supplier-view-workspace__subtitle">Review transactions, purchases, and account history</p>
                        </div>

                        <div v-if="activeTab === 'ledger'" class="supplier-view-kpis">
                            <div class="supplier-view-kpi">
                                <span class="supplier-view-kpi__label">Entries</span>
                                <span class="supplier-view-kpi__value">{{ ledgerEntryCount }}</span>
                            </div>
                            <div class="supplier-view-kpi supplier-view-kpi--accent">
                                <span class="supplier-view-kpi__label">Closing balance</span>
                                <span class="supplier-view-kpi__value">{{ formatAmount(closingBalance) }}</span>
                            </div>
                        </div>
                    </div>

                    <nav class="supplier-view-tabs" aria-label="Supplier activity sections">
                        <button
                            v-for="tab in tabs"
                            :key="tab.id"
                            type="button"
                            class="supplier-view-tabs__btn"
                            :class="{ 'supplier-view-tabs__btn--active': activeTab === tab.id }"
                            :aria-selected="activeTab === tab.id"
                            @click="activeTab = tab.id"
                        >
                            <component :is="tab.icon" size="xs" />
                            <span>{{ tab.label }}</span>
                        </button>
                    </nav>

                    <div class="supplier-view-tabs__panel">
                        <div v-if="activeTab === 'ledger'" class="supplier-view-ledger">
                            <div class="supplier-view-ledger-toolbar">
                                <div class="supplier-view-ledger-toolbar__copy">
                                    <h3 class="supplier-view-ledger-toolbar__title">Account ledger</h3>
                                    <p class="supplier-view-ledger-toolbar__subtitle">
                                        Period statement for {{ contact.business_name || 'this supplier' }}
                                    </p>
                                </div>

                                <div class="supplier-view-date-range">
                                    <div class="supplier-view-date-field">
                                        <label class="supplier-view-label" for="ledger-start-date">From</label>
                                        <input
                                            id="ledger-start-date"
                                            v-model="ledgerFilters.start_date"
                                            type="date"
                                            class="form-control form-control-sm supplier-view-date-input"
                                        />
                                    </div>
                                    <span class="supplier-view-date-range__sep" aria-hidden="true">→</span>
                                    <div class="supplier-view-date-field">
                                        <label class="supplier-view-label" for="ledger-end-date">To</label>
                                        <input
                                            id="ledger-end-date"
                                            v-model="ledgerFilters.end_date"
                                            type="date"
                                            class="form-control form-control-sm supplier-view-date-input"
                                        />
                                    </div>
                                </div>
                            </div>

                            <Loader v-if="ledgerLoading" message="Loading ledger…" :fields="4" />

                            <template v-else>
                                <div class="supplier-view-ledger-grid">
                                    <article class="supplier-view-summary-card">
                                        <div class="supplier-view-summary-card__head">
                                            <User size="xs" />
                                            <h4>Bill to</h4>
                                        </div>
                                        <p class="supplier-view-summary-card__name">{{ displayName }}</p>
                                        <p v-if="contact.business_name" class="supplier-view-summary-card__line">{{ contact.business_name }}</p>
                                        <p v-if="contact.address" class="supplier-view-summary-card__line">{{ contact.address }}</p>
                                        <p v-if="locationLine" class="supplier-view-summary-card__line">{{ locationLine }}</p>
                                        <div class="supplier-view-summary-card__contacts">
                                            <span><Envelope size="xs" /> {{ contact.email || '-' }}</span>
                                            <span><Phone size="xs" /> {{ contact.mobile || '-' }}</span>
                                        </div>
                                    </article>

                                    <article class="supplier-view-summary-card supplier-view-summary-card--accent">
                                        <div class="supplier-view-summary-card__head">
                                            <Receipt size="xs" />
                                            <h4>Account summary</h4>
                                        </div>
                                        <p class="supplier-view-summary-card__period">
                                            {{ ledgerFilters.start_date }} — {{ ledgerFilters.end_date }}
                                        </p>
                                        <dl class="supplier-view-summary-list">
                                            <div class="supplier-view-summary-list__row">
                                                <dt>Opening balance</dt>
                                                <dd>{{ currencyCode }} {{ formatAmount(ledgerData.openingbalance) }}</dd>
                                            </div>
                                            <div v-if="showPurchaseStats" class="supplier-view-summary-list__row">
                                                <dt>Total purchase</dt>
                                                <dd>{{ currencyCode }} {{ formatAmount(ledgerData.total_purchase) }}</dd>
                                            </div>
                                            <div v-if="showPurchaseStats" class="supplier-view-summary-list__row">
                                                <dt>Purchase paid</dt>
                                                <dd>{{ currencyCode }} {{ formatAmount(ledgerData.total_paid_purchase) }}</dd>
                                            </div>
                                            <div v-if="showSellStats" class="supplier-view-summary-list__row">
                                                <dt>Total sell</dt>
                                                <dd>{{ currencyCode }} {{ formatAmount(ledgerData.total_sell) }}</dd>
                                            </div>
                                            <div v-if="showSellStats" class="supplier-view-summary-list__row">
                                                <dt>Sell paid</dt>
                                                <dd>{{ currencyCode }} {{ formatAmount(ledgerData.total_paid_sell) }}</dd>
                                            </div>
                                            <div class="supplier-view-summary-list__row supplier-view-summary-list__row--total">
                                                <dt>Closing balance</dt>
                                                <dd>{{ currencyCode }} {{ formatAmount(closingBalance) }}</dd>
                                            </div>
                                        </dl>
                                    </article>
                                </div>

                                <div class="supplier-view-table-head">
                                    <p>
                                        Statement from <strong>{{ ledgerFilters.start_date }}</strong> to
                                        <strong>{{ ledgerFilters.end_date }}</strong>
                                    </p>
                                    <span class="supplier-view-table-head__count">{{ ledgerEntryCount }} entries</span>
                                </div>

                                <div class="supplier-view-table-wrap">
                                    <table class="table supplier-view-table">
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
                                            <tr class="supplier-view-table__opening">
                                                <td>{{ ledgerFilters.start_date }}</td>
                                                <td colspan="4">Opening balance</td>
                                                <td></td>
                                                <td class="text-end supplier-view-amount"></td>
                                                <td class="text-end supplier-view-amount"></td>
                                                <td class="text-end supplier-view-amount supplier-view-amount--strong">
                                                    {{ formatAmount(ledgerData.openingbalance) }}
                                                </td>
                                                <td colspan="2"></td>
                                            </tr>
                                            <tr
                                                v-for="row in ledgerRows"
                                                :key="row.id"
                                                class="supplier-view-table__row"
                                                :class="{ 'supplier-view-table__highlight': row.highlight === 1 }"
                                            >
                                                <td>{{ row.voucher_date || '-' }}</td>
                                                <td class="supplier-view-table__mono">{{ row.voucher_no || '-' }}</td>
                                                <td class="supplier-view-table__mono">{{ row.ref_no || '-' }}</td>
                                                <td class="supplier-view-table__narration" v-html="row.description || '-'" />
                                                <td>{{ row.branch?.name || '-' }}</td>
                                                <td>
                                                    <span :class="paymentStatusClass(row.transaction?.parent?.payment_status)">
                                                        {{ row.transaction?.parent?.payment_status || '-' }}
                                                    </span>
                                                </td>
                                                <td class="text-end supplier-view-amount">{{ row.debit ? formatAmount(row.debit) : '-' }}</td>
                                                <td class="text-end supplier-view-amount">{{ row.credit ? formatAmount(row.credit) : '-' }}</td>
                                                <td class="text-end supplier-view-amount supplier-view-amount--strong">
                                                    {{ formatAmount(row.balance_amount) }}
                                                </td>
                                                <td>{{ row.type || '-' }}</td>
                                                <td>{{ row.cheque_no || '-' }}</td>
                                            </tr>
                                            <tr v-if="ledgerRows.length === 0">
                                                <td colspan="11">
                                                    <div class="supplier-view-empty supplier-view-empty--inline">
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

                        <div v-else class="supplier-view-empty">
                            <div class="supplier-view-empty__icon">
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
.supplier-view-page {
    --sv-accent: #199683;
    --sv-accent-dark: #0f766e;
    --sv-accent-soft: rgba(25, 150, 131, 0.1);
    --sv-accent-border: rgba(25, 150, 131, 0.18);
}

.supplier-view-layout {
    display: grid;
    grid-template-columns: minmax(300px, 360px) minmax(0, 1fr);
    gap: 1rem;
    align-items: start;
}

.supplier-view-sidebar {
    position: sticky;
    top: 1rem;
}

.supplier-view-profile {
    position: relative;
    overflow: hidden;
    padding: 0;
}

.supplier-view-profile__banner {
    position: relative;
    height: 5.5rem;
    background: linear-gradient(135deg, #199683 0%, #0f766e 55%, #115e59 100%);
}

.supplier-view-profile__orb {
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.12);
}

.supplier-view-profile__orb--one {
    width: 7rem;
    height: 7rem;
    top: -2rem;
    right: -1rem;
}

.supplier-view-profile__orb--two {
    width: 4rem;
    height: 4rem;
    bottom: -1.5rem;
    left: 1rem;
}

.supplier-view-profile__toolbar {
    position: absolute;
    top: 0.75rem;
    left: 0.875rem;
    right: 0.875rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    z-index: 2;
}

.supplier-view-back {
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

.supplier-view-back:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.22);
}

.supplier-view-icon-btn {
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

.supplier-view-icon-btn:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.24);
}

.supplier-view-icon-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.supplier-view-spin {
    animation: supplier-view-spin 0.8s linear infinite;
}

@keyframes supplier-view-spin {
    to {
        transform: rotate(360deg);
    }
}

.supplier-view-profile__identity {
    display: flex;
    gap: 0.875rem;
    padding: 0 1.125rem 1rem;
    margin-top: -2rem;
    position: relative;
    z-index: 1;
}

.supplier-view-profile__avatar {
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

.supplier-view-profile__meta {
    min-width: 0;
    padding-top: 2.25rem;
}

.supplier-view-coa-alert {
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

.supplier-view-coa-alert__content {
    display: flex;
    gap: 0.625rem;
    align-items: flex-start;
    min-width: 0;
    color: #92400e;
}

.supplier-view-coa-alert__content strong {
    display: block;
    font-size: 0.8125rem;
    margin-bottom: 0.125rem;
}

.supplier-view-coa-alert__content p {
    margin: 0;
    font-size: 0.75rem;
    line-height: 1.45;
    color: #b45309;
}

.supplier-view-coa-alert__action {
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

.supplier-view-coa-alert__action:hover:not(:disabled) {
    background: #b45309;
}

.supplier-view-coa-alert__action:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.supplier-view-coa-linked {
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

.supplier-view-coa-linked code {
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.75);
    color: #065f46;
    font-size: 0.6875rem;
}

.supplier-view-profile__badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin-bottom: 0.5rem;
}

.supplier-view-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.18rem 0.55rem;
    border-radius: 999px;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.supplier-view-badge--primary {
    color: var(--sv-accent-dark);
    background: var(--sv-accent-soft);
    border: 1px solid var(--sv-accent-border);
}

.supplier-view-badge--muted {
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
}

.supplier-view-badge--success {
    color: #15803d;
    background: rgba(34, 197, 94, 0.12);
}

.supplier-view-badge--warning {
    color: #b45309;
    background: rgba(245, 158, 11, 0.12);
}

.supplier-view-badge--danger {
    color: #b91c1c;
    background: rgba(239, 68, 68, 0.12);
}

.supplier-view-profile__title {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 800;
    line-height: 1.25;
    color: var(--app-text, #111827);
}

.supplier-view-profile__person {
    margin: 0.25rem 0 0.625rem;
    font-size: 0.8125rem;
    color: var(--app-text-secondary, #64748b);
}

.supplier-view-profile__chips {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.supplier-view-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
    color: #475569;
}

.supplier-view-profile__stats {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    padding: 0 1.125rem 1rem;
}

.supplier-view-stat {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 0.875rem;
    border-radius: 0.875rem;
    background: #f8fafc;
    border: 1px solid #eef2f7;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.supplier-view-stat:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}

.supplier-view-stat--success {
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.08) 0%, rgba(34, 197, 94, 0.02) 100%);
    border-color: rgba(34, 197, 94, 0.18);
}

.supplier-view-stat--danger {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.08) 0%, rgba(239, 68, 68, 0.02) 100%);
    border-color: rgba(239, 68, 68, 0.18);
}

.supplier-view-stat__icon {
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

.supplier-view-stat--success .supplier-view-stat__icon {
    color: #15803d;
}

.supplier-view-stat--danger .supplier-view-stat__icon {
    color: #b91c1c;
}

.supplier-view-stat__content {
    min-width: 0;
}

.supplier-view-stat__label {
    display: block;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--app-text-muted, #94a3b8);
}

.supplier-view-stat__value {
    display: block;
    font-size: 1rem;
    font-weight: 800;
    color: var(--app-text, #111827);
    font-variant-numeric: tabular-nums;
}

.supplier-view-stat__value small {
    margin-right: 0.25rem;
    font-size: 0.6875rem;
    font-weight: 700;
    color: var(--app-text-secondary, #64748b);
}

.supplier-view-profile__details {
    padding: 0 1.125rem 1.125rem;
    border-top: 1px solid var(--app-border-subtle, #f1f5f9);
}

.supplier-view-profile__details-title {
    margin: 1rem 0 0.75rem;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--app-text-muted, #94a3b8);
}

.supplier-view-info-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.supplier-view-info-list__item {
    display: flex;
    gap: 0.75rem;
    padding: 0.625rem 0.75rem;
    border-radius: 0.75rem;
    background: #fafbfc;
    border: 1px solid #eef2f7;
}

.supplier-view-info-list__icon {
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

.supplier-view-info-list__body {
    min-width: 0;
}

.supplier-view-info-list__label {
    display: block;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--app-text-muted, #94a3b8);
}

.supplier-view-info-list__value {
    display: block;
    margin-top: 0.125rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: #334155;
    line-height: 1.45;
    word-break: break-word;
}

.supplier-view-main {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    min-width: 0;
}

.supplier-view-scope__toggle {
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

.supplier-view-scope__toggle-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--app-text, #111827);
}

.supplier-view-scope__toggle-hint {
    display: block;
    margin-top: 0.125rem;
    font-size: 0.75rem;
    color: var(--app-text-secondary, #64748b);
}

.supplier-view-scope__chevron--open {
    transform: rotate(180deg);
}

.supplier-view-scope__toggle svg {
    transition: transform 0.2s ease;
    color: var(--app-text-secondary, #64748b);
    flex-shrink: 0;
}

.supplier-view-scope__body {
    padding: 0 1.125rem 1.125rem;
    border-top: 1px solid var(--app-border-subtle, #f1f5f9);
}

.supplier-view-label {
    display: block;
    margin-bottom: 0.375rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--app-text-secondary, #64748b);
}

.supplier-view-select,
.supplier-view-date-input {
    border-radius: 0.625rem;
    border-color: var(--app-border, #e5e7eb);
    min-height: 2.375rem;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.03);
}

.supplier-view-workspace__head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.125rem 1.25rem 0;
}

.supplier-view-workspace__title {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
    color: var(--app-text, #111827);
}

.supplier-view-workspace__subtitle {
    margin: 0.25rem 0 0;
    font-size: 0.8125rem;
    color: var(--app-text-secondary, #64748b);
}

.supplier-view-kpis {
    display: flex;
    gap: 0.625rem;
}

.supplier-view-kpi {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    padding: 0.5rem 0.75rem;
    border-radius: 0.75rem;
    background: #f8fafc;
    border: 1px solid #eef2f7;
}

.supplier-view-kpi--accent {
    background: var(--sv-accent-soft);
    border-color: var(--sv-accent-border);
}

.supplier-view-kpi__label {
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--app-text-muted, #94a3b8);
}

.supplier-view-kpi__value {
    font-size: 0.9375rem;
    font-weight: 800;
    color: var(--app-text, #111827);
    font-variant-numeric: tabular-nums;
}

.supplier-view-kpi--accent .supplier-view-kpi__value {
    color: var(--sv-accent-dark);
}

.supplier-view-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 1rem 1.25rem 0;
}

.supplier-view-tabs__btn {
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

.supplier-view-tabs__btn:hover {
    color: #334155;
    border-color: #cbd5e1;
    background: #f8fafc;
}

.supplier-view-tabs__btn--active {
    color: #fff;
    background: linear-gradient(135deg, var(--sv-accent) 0%, var(--sv-accent-dark) 100%);
    border-color: transparent;
    box-shadow: 0 8px 18px rgba(25, 150, 131, 0.24);
}

.supplier-view-tabs__panel {
    padding: 1.125rem 1.25rem 1.25rem;
}

.supplier-view-ledger-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.supplier-view-ledger-toolbar__title {
    margin: 0;
    font-size: 0.9375rem;
    font-weight: 700;
    color: var(--app-text, #111827);
}

.supplier-view-ledger-toolbar__subtitle {
    margin: 0.25rem 0 0;
    font-size: 0.8125rem;
    color: var(--app-text-secondary, #64748b);
}

.supplier-view-date-range {
    display: flex;
    align-items: flex-end;
    gap: 0.625rem;
    padding: 0.75rem;
    border-radius: 0.875rem;
    background: #f8fafc;
    border: 1px solid #eef2f7;
}

.supplier-view-date-range__sep {
    padding-bottom: 0.55rem;
    color: var(--app-text-muted, #94a3b8);
    font-weight: 700;
}

.supplier-view-date-field {
    min-width: 9.5rem;
}

.supplier-view-ledger-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.875rem;
    margin-bottom: 1rem;
}

.supplier-view-summary-card {
    height: 100%;
    padding: 1rem 1.125rem;
    border-radius: 0.875rem;
    background: #f8fafc;
    border: 1px solid #eef2f7;
}

.supplier-view-summary-card--accent {
    background: linear-gradient(135deg, rgba(25, 150, 131, 0.08) 0%, rgba(25, 150, 131, 0.02) 100%);
    border-color: var(--sv-accent-border);
}

.supplier-view-summary-card__head {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
    color: var(--sv-accent-dark);
}

.supplier-view-summary-card__head h4 {
    margin: 0;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.supplier-view-summary-card__name {
    margin: 0 0 0.375rem;
    font-size: 1rem;
    font-weight: 800;
    color: var(--app-text, #111827);
}

.supplier-view-summary-card__line {
    margin: 0 0 0.25rem;
    font-size: 0.8125rem;
    color: #475569;
}

.supplier-view-summary-card__contacts {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px dashed #e2e8f0;
}

.supplier-view-summary-card__contacts span {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.8125rem;
    color: #475569;
}

.supplier-view-summary-card__period {
    margin: 0 0 0.875rem;
    font-size: 0.8125rem;
    color: var(--app-text-secondary, #64748b);
}

.supplier-view-summary-list {
    margin: 0;
}

.supplier-view-summary-list__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.5rem 0;
    border-bottom: 1px dashed rgba(25, 150, 131, 0.16);
}

.supplier-view-summary-list__row:last-child {
    border-bottom: 0;
}

.supplier-view-summary-list__row--total {
    margin-top: 0.25rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--sv-accent-border);
    border-bottom: 0;
}

.supplier-view-summary-list__row dt {
    margin: 0;
    font-size: 0.8125rem;
    color: #475569;
}

.supplier-view-summary-list__row dd {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 800;
    color: var(--app-text, #111827);
    font-variant-numeric: tabular-nums;
}

.supplier-view-summary-list__row--total dd {
    color: var(--sv-accent-dark);
}

.supplier-view-table-head {
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

.supplier-view-table-head p {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--app-text-secondary, #64748b);
}

.supplier-view-table-head__count {
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--sv-accent-dark);
    background: var(--sv-accent-soft);
    padding: 0.25rem 0.625rem;
    border-radius: 999px;
}

.supplier-view-table-wrap {
    overflow: auto;
    border: 1px solid #eef2f7;
    border-radius: 0.875rem;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
}

.supplier-view-table {
    margin: 0;
    font-size: 0.8125rem;
}

.supplier-view-table thead th {
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

.supplier-view-table tbody td {
    vertical-align: middle;
    border-color: #f1f5f9;
}

.supplier-view-table__row:hover {
    background: rgba(248, 250, 252, 0.9);
}

.supplier-view-table__opening {
    background: rgba(25, 150, 131, 0.05);
}

.supplier-view-table__highlight {
    background: var(--sv-accent-dark) !important;
    color: #fff;
}

.supplier-view-table__highlight .supplier-view-badge {
    background: rgba(255, 255, 255, 0.16);
    color: #fff;
}

.supplier-view-table__mono {
    font-family: var(--app-font-mono);
    font-size: 0.75rem;
}

.supplier-view-table__narration {
    max-width: 14rem;
    white-space: normal;
}

.supplier-view-amount {
    font-variant-numeric: tabular-nums;
}

.supplier-view-amount--strong {
    font-weight: 800;
    color: var(--sv-accent-dark);
}

.supplier-view-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 3rem 1rem;
    text-align: center;
    color: var(--app-text-secondary, #64748b);
}

.supplier-view-empty__icon {
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

.supplier-view-empty h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
    color: var(--app-text, #111827);
}

.supplier-view-empty p {
    margin: 0;
    font-size: 0.875rem;
}

.supplier-view-empty--inline {
    padding: 1.5rem 1rem;
}

@media (max-width: 1199.98px) {
    .supplier-view-layout {
        grid-template-columns: 1fr;
    }

    .supplier-view-sidebar {
        position: static;
    }
}

@media (max-width: 991.98px) {
    .supplier-view-ledger-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767.98px) {
    .supplier-view-kpis {
        width: 100%;
    }

    .supplier-view-kpi {
        flex: 1;
        align-items: flex-start;
    }

    .supplier-view-date-range {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
    }

    .supplier-view-date-range__sep {
        display: none;
    }

    .supplier-view-date-field {
        min-width: 0;
        width: 100%;
    }

    .supplier-view-ledger-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .supplier-view-table-head {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
