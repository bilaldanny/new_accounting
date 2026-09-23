<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    ClipboardCheck,
    Landmark,
    LayoutDashboard,
    Package,
    RefreshCw,
    ShoppingCart,
    TrendingDown,
    TrendingUp,
    TriangleAlert,
    Truck,
    Users,
} from '@lucide/vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import useCommons from '@/composables/common';

/*
 * The dashboard cards. Each group of cards is loaded by its own request (GET /api/dashboard/{widget}), so the
 * light ones appear at once and the heavy ones (the customer and supplier ledgers, stock value, profit and loss,
 * which the server keeps for five minutes) never hold the rest back. The server only sends a figure the user may
 * open the page of; the permission check here just decides which cards are drawn while they load.
 */

type WidgetName = 'sales' | 'receivables' | 'inventory' | 'approvals' | 'financial' | 'stats' | 'recent' | 'forecast';

interface WidgetState {
    status: 'loading' | 'ready' | 'error';
    data: Record<string, any>;
    needsCompany: string[];
    cachedAt: string | null;
}

const page = usePage();
const { fetchCompany, companiesdata } = useCommons();

const authUser = computed(() => page.props.auth?.user as {
    rolename?: string;
    company_id?: number | string | null;
    permission_paths?: string[];
} | null);

const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
const can = (path: string): boolean => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes(path);

const COMPANY_KEY = 'dashboardCompanyId';
const companyId = ref<string>('');

const widgets = reactive<Record<WidgetName, WidgetState>>({
    sales: { status: 'loading', data: {}, needsCompany: [], cachedAt: null },
    receivables: { status: 'loading', data: {}, needsCompany: [], cachedAt: null },
    inventory: { status: 'loading', data: {}, needsCompany: [], cachedAt: null },
    approvals: { status: 'loading', data: {}, needsCompany: [], cachedAt: null },
    financial: { status: 'loading', data: {}, needsCompany: [], cachedAt: null },
    stats: { status: 'loading', data: {}, needsCompany: [], cachedAt: null },
    recent: { status: 'loading', data: {}, needsCompany: [], cachedAt: null },
    forecast: { status: 'loading', data: {}, needsCompany: [], cachedAt: null },
});

const money = (value: unknown): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const whole = (value: unknown): string => Number(value ?? 0).toLocaleString();
const percent = (value: number | null | undefined): string => (value === null || value === undefined ? '' : `${value > 0 ? '+' : ''}${value}%`);
const shortTime = (value: string | null): string => (value ? new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '');

async function loadWidget(name: WidgetName, refresh = false): Promise<void> {
    const state = widgets[name];
    state.status = 'loading';

    try {
        const params: Record<string, string | number> = {};

        if (isSuperadmin.value && companyId.value !== '') {
            params.company_id = companyId.value;
        }

        if (refresh) {
            params.refresh = 1;
        }

        const response = await window.axios.get(`/api/dashboard/${name}`, { params });
        state.data = response.data?.data ?? {};
        state.needsCompany = response.data?.needs_company ?? [];
        state.cachedAt = response.data?.cached_at ?? null;
        state.status = 'ready';
    } catch {
        state.status = 'error';
    }
}

/** The quick, set-based cards first, then the ones that read every ledger or every stock movement. */
const LIGHT: WidgetName[] = ['sales', 'approvals', 'stats', 'recent'];
const HEAVY: WidgetName[] = ['inventory', 'financial', 'receivables', 'forecast'];

function loadAll(refresh = false): void {
    [...LIGHT, ...HEAVY].forEach((name) => {
        void loadWidget(name, refresh);
    });
}

onMounted(async () => {
    let remembered = '';

    if (isSuperadmin.value) {
        try {
            remembered = localStorage.getItem(COMPANY_KEY) ?? '';
        } catch {
            remembered = '';
        }

        await fetchCompany();
    }

    // picking the remembered company loads the cards through the watcher below; otherwise load them here
    if (remembered !== companyId.value) {
        companyId.value = remembered;

        return;
    }

    loadAll();
});

watch(companyId, (value) => {
    try {
        localStorage.setItem(COMPANY_KEY, value);
    } catch {
        // a private window: the choice just is not remembered
    }

    loadAll();
});

const isLoading = (name: WidgetName): boolean => widgets[name].status === 'loading';
const failed = (name: WidgetName): boolean => widgets[name].status === 'error';
const asksForCompany = (name: WidgetName, part: string): boolean => widgets[name].needsCompany.includes(part);
const refreshing = computed(() => Object.values(widgets).some((widget) => widget.status === 'loading'));

/** When the oldest cached figure was worked out, for the "as of" note. */
const cachedAt = computed(() => {
    const times = (Object.values(widgets) as WidgetState[]).map((widget) => widget.cachedAt).filter((time): time is string => time !== null).sort();

    return times[0] ?? null;
});

/* Sales and purchases (the dark band) */
const sales = computed(() => widgets.sales.data.sales as Record<string, any> | undefined);
const purchases = computed(() => widgets.sales.data.purchases as Record<string, any> | undefined);
const receivables = computed(() => widgets.receivables.data.receivables as Record<string, any> | undefined);
const payables = computed(() => widgets.receivables.data.payables as Record<string, any> | undefined);
const creditWatch = computed(() => widgets.receivables.data.credit_watch as { near: number; exceeded: number; items: Array<Record<string, any>> } | undefined);
const lowStock = computed(() => widgets.inventory.data.low_stock as { count: number; items: Array<Record<string, any>> } | undefined);
const stockValue = computed(() => widgets.inventory.data.stock_value as Record<string, any> | undefined);
const netProfit = computed(() => widgets.financial.data.net_profit as Record<string, any> | undefined);
const cashBank = computed(() => widgets.financial.data.cash_bank as { total: number; accounts: Array<Record<string, any>> } | undefined);
const recentSales = computed(() => (widgets.recent.data.recent_sales ?? []) as Array<Record<string, any>>);
const forecast = computed(() => widgets.forecast.data.sales_forecast as Record<string, any> | undefined);

/** The months the outlook card draws as bars: the history, then the estimate for next month. */
const outlookBars = computed(() => {
    const f = forecast.value;

    if (! f) {
        return [] as Array<{ month: string; value: number; estimate: boolean }>;
    }

    const bars = (f.history as Array<{ month: string; net_sales: number }>).map((row) => ({ month: row.month, value: Number(row.net_sales), estimate: false }));

    if (f.estimate !== null && f.estimate !== undefined) {
        bars.push({ month: f.target_month, value: Number(f.estimate), estimate: true });
    }

    return bars;
});
const outlookMax = computed(() => Math.max(...outlookBars.value.map((bar) => bar.value), 1));
const methodLabel = (method: string | null | undefined): string => (method === 'linear' ? 'trend line of the last months' : 'average of the last months');

/* Pending approvals: one row per approval page the user may open */
const approvalRows = [
    { key: 'purchase', label: 'Purchase orders', href: '/purchase/approval', permission: '/purchase/approval' },
    { key: 'sell', label: 'Sell orders', href: '/sell/approval', permission: '/sell/approval' },
    { key: 'journal', label: 'Journal entries', href: '/journalentry/approval', permission: '/journalentry/approval' },
    { key: 'payment', label: 'Payment vouchers', href: '/acpayment/approval', permission: '/acpayment/approval' },
    { key: 'expense', label: 'Expense vouchers', href: '/expense/approval', permission: '/expense/approval' },
    { key: 'deposit', label: 'Deposit vouchers', href: '/deposit/approval', permission: '/deposit/approval' },
    { key: 'fundtransfer', label: 'Fund transfers', href: '/fundtransfer/approval', permission: '/fundtransfer/approval' },
];

const visibleApprovals = computed(() => approvalRows.filter((row) => can(row.permission)));
const approvalsTotal = computed(() => visibleApprovals.value.reduce((sum, row) => sum + Number(widgets.approvals.data[row.key] ?? 0), 0));

/* Which cards are drawn */
const showSales = computed(() => can('/sell'));
const showPurchases = computed(() => can('/purchase'));
const showReceivables = computed(() => can('/report/customer-outstanding'));
const showPayables = computed(() => can('/report/supplier-outstanding'));
const showFinancial = computed(() => can('/report/profit-loss') || can('/chart-of-account'));
const showApprovals = computed(() => visibleApprovals.value.length > 0);
const showInventory = computed(() => can('/lowstock') || can('/report/stock'));
const showCredit = computed(() => can('/customer'));
const showStats = computed(() => can('/customer') || can('/supplier') || can('/product'));
const showRecent = computed(() => can('/sell'));
const showForecast = computed(() => can('/report/purchase-sale'));

const showAnything = computed(() => [
    showSales.value, showPurchases.value, showReceivables.value, showPayables.value, showFinancial.value,
    showApprovals.value, showInventory.value, showCredit.value, showStats.value, showRecent.value, showForecast.value,
].some(Boolean));

const statCards = computed(() => [
    { key: 'customers', label: 'Active customers', permission: '/customer', href: '/customer', icon: Users, tone: 'primary' },
    { key: 'suppliers', label: 'Active suppliers', permission: '/supplier', href: '/supplier', icon: Truck, tone: 'danger' },
    { key: 'products', label: 'Active products', permission: '/product', href: '/product', icon: Package, tone: 'success' },
].filter((card) => can(card.permission)));

const statusLabels: Record<string, string> = { final: 'Final', approved: 'Approved', draft: 'Draft', quotation: 'Quotation' };
</script>

<template>
    <Head title="Dashboard" />

    <div class="dash-shell">
        <!-- Dark KPI strip: sales, purchases, what is owed -->
        <div class="dash-kpi-band">
            <div class="dash-kpi-band__header">
                <div class="dash-kpi-band__title">
                    <LayoutDashboard aria-hidden="true" />
                    <span>Business overview</span>
                    <small v-if="cachedAt" class="text-secondary ms-2">Ledger figures as of {{ shortTime(cachedAt) }}</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <select
                        v-if="isSuperadmin"
                        v-model="companyId"
                        class="form-select form-select-sm dash-company-select"
                        aria-label="Company"
                    >
                        <option value="">All companies</option>
                        <option v-for="company in companiesdata" :key="company.id" :value="String(company.id)">
                            {{ company.text ?? company.name }}
                        </option>
                    </select>
                    <button type="button" class="dash-period-pill" :disabled="refreshing" title="Recalculate the figures kept for five minutes" @click="loadAll(true)">
                        <RefreshCw class="dash-refresh" :class="{ 'is-spinning': refreshing }" aria-hidden="true" />
                        Refresh
                    </button>
                </div>
            </div>

            <div class="dash-kpi-grid dash-kpi-grid--fit">
                <div v-if="showSales" class="dash-kpi-cell" data-card="sales-today">
                    <span class="dash-kpi-cell__label">Sales today</span>
                    <span v-if="isLoading('sales')" class="dash-skel dash-skel--dark" />
                    <span v-else-if="failed('sales')" class="dash-kpi-cell__value">–</span>
                    <span v-else class="dash-kpi-cell__value">{{ money(sales?.today) }}</span>
                    <div class="dash-kpi-cell__trend"><span>net of returns</span></div>
                </div>

                <div v-if="showSales" class="dash-kpi-cell" data-card="sales-month">
                    <span class="dash-kpi-cell__label">Sales this month</span>
                    <span v-if="isLoading('sales')" class="dash-skel dash-skel--dark" />
                    <span v-else-if="failed('sales')" class="dash-kpi-cell__value">–</span>
                    <span v-else class="dash-kpi-cell__value">{{ money(sales?.this_month) }}</span>
                    <div v-if="sales" class="dash-kpi-cell__trend">
                        <span v-if="sales.change_percent !== null" class="dash-kpi-cell__trend-badge" :class="sales.change_percent >= 0 ? 'is-up' : 'is-down'">
                            <TrendingUp v-if="sales.change_percent >= 0" :size="12" aria-hidden="true" />
                            <TrendingDown v-else :size="12" aria-hidden="true" />
                            {{ percent(sales.change_percent) }}
                        </span>
                        <span>{{ sales.change_percent !== null ? 'vs same days last month' : 'nothing sold last month' }}</span>
                    </div>
                    <div v-if="sales" class="dash-kpi-cell__trend"><span>Last month: {{ money(sales.last_month) }}</span></div>
                </div>

                <div v-if="showPurchases" class="dash-kpi-cell" data-card="purchases-month">
                    <span class="dash-kpi-cell__label">Purchases this month</span>
                    <span v-if="isLoading('sales')" class="dash-skel dash-skel--dark" />
                    <span v-else-if="failed('sales')" class="dash-kpi-cell__value">–</span>
                    <span v-else class="dash-kpi-cell__value">{{ money(purchases?.this_month) }}</span>
                    <div v-if="purchases" class="dash-kpi-cell__trend">
                        <span v-if="purchases.change_percent !== null" class="dash-kpi-cell__trend-badge" :class="purchases.change_percent <= 0 ? 'is-up' : 'is-down'">
                            <TrendingUp v-if="purchases.change_percent >= 0" :size="12" aria-hidden="true" />
                            <TrendingDown v-else :size="12" aria-hidden="true" />
                            {{ percent(purchases.change_percent) }}
                        </span>
                        <span>{{ purchases.change_percent !== null ? 'vs same days last month' : 'nothing bought last month' }}</span>
                    </div>
                </div>

                <div v-if="showReceivables" class="dash-kpi-cell" data-card="receivables">
                    <span class="dash-kpi-cell__label">Receivable from customers</span>
                    <span v-if="isLoading('receivables')" class="dash-skel dash-skel--dark" />
                    <span v-else-if="asksForCompany('receivables', 'receivables')" class="dash-kpi-cell__trend">Choose a company</span>
                    <span v-else-if="failed('receivables')" class="dash-kpi-cell__value">–</span>
                    <span v-else class="dash-kpi-cell__value">{{ money(receivables?.total_due) }}</span>
                    <div v-if="receivables" class="dash-kpi-cell__trend"><span>{{ whole(receivables.contacts) }} customers with a balance</span></div>
                </div>

                <div v-if="showPayables" class="dash-kpi-cell" data-card="payables">
                    <span class="dash-kpi-cell__label">Payable to suppliers</span>
                    <span v-if="isLoading('receivables')" class="dash-skel dash-skel--dark" />
                    <span v-else-if="asksForCompany('receivables', 'payables')" class="dash-kpi-cell__trend">Choose a company</span>
                    <span v-else-if="failed('receivables')" class="dash-kpi-cell__value">–</span>
                    <span v-else class="dash-kpi-cell__value">{{ money(payables?.total_due) }}</span>
                    <div v-if="payables" class="dash-kpi-cell__trend"><span>{{ whole(payables.contacts) }} suppliers with a balance</span></div>
                </div>
            </div>
        </div>

        <!-- Light content area -->
        <div class="dash-content">
            <p v-if="! showAnything" class="text-secondary mb-0">Nothing to show yet: your role has no dashboard figures assigned.</p>

            <!-- Row 1: financial snapshot and pending approvals -->
            <div v-if="showFinancial || showApprovals" class="dash-grid-2">
                <div v-if="showFinancial" class="dash-card" data-card="financial">
                    <div class="dash-card__header">
                        <div class="dash-card__title-group">
                            <h3 class="dash-card__title">Financial snapshot</h3>
                            <span v-if="netProfit" class="dash-card__badge">{{ netProfit.from }} to {{ netProfit.to }}</span>
                        </div>
                        <span class="dash-stat-card__icon dash-stat-card__icon--primary"><Landmark aria-hidden="true" /></span>
                    </div>

                    <p v-if="asksForCompany('financial', 'net_profit') || asksForCompany('financial', 'cash_bank')" class="text-secondary mb-0">Choose a company above to see its profit and cash.</p>
                    <p v-else-if="failed('financial')" class="text-danger mb-0">The figures could not be loaded. <a href="#" @click.prevent="loadWidget('financial')">Try again</a></p>
                    <template v-else>
                        <div v-if="can('/report/profit-loss')" class="dash-fin-row">
                            <span>Net profit this month</span>
                            <span v-if="isLoading('financial')" class="dash-skel" />
                            <strong v-else :class="Number(netProfit?.net_profit) < 0 ? 'text-danger' : 'text-success'">{{ money(netProfit?.net_profit) }}</strong>
                        </div>
                        <div v-if="netProfit && can('/report/profit-loss')" class="dash-fin-sub">
                            Revenue {{ money(netProfit.revenue) }} · Expenses {{ money(netProfit.expenses) }} · Margin {{ netProfit.net_margin }}%
                            <span v-if="netProfit.uncosted_stock > 0" class="text-warning"> · {{ netProfit.uncosted_stock }} stocked items have no purchase cost</span>
                        </div>

                        <div v-if="can('/chart-of-account')" class="dash-fin-row mt-3">
                            <span>Cash and bank</span>
                            <span v-if="isLoading('financial')" class="dash-skel" />
                            <strong v-else>{{ money(cashBank?.total) }}</strong>
                        </div>
                        <ul v-if="cashBank?.accounts?.length" class="dash-plain-list">
                            <li v-for="account in cashBank.accounts" :key="account.code">
                                <span>{{ account.name }}</span>
                                <span :class="{ 'text-danger': account.balance < 0 }">{{ money(account.balance) }}</span>
                            </li>
                        </ul>
                    </template>
                </div>

                <div v-if="showApprovals" class="dash-card" data-card="approvals">
                    <div class="dash-card__header">
                        <div class="dash-card__title-group">
                            <h3 class="dash-card__title">Pending approvals</h3>
                            <span v-if="widgets.approvals.status === 'ready'" class="dash-card__badge" :class="{ 'dash-card__badge--success': approvalsTotal === 0 }">
                                {{ approvalsTotal === 0 ? 'All clear' : `${approvalsTotal} waiting` }}
                            </span>
                        </div>
                        <span class="dash-stat-card__icon dash-stat-card__icon--danger"><ClipboardCheck aria-hidden="true" /></span>
                    </div>

                    <p v-if="failed('approvals')" class="text-danger mb-0">The counts could not be loaded. <a href="#" @click.prevent="loadWidget('approvals')">Try again</a></p>
                    <ul v-else class="dash-plain-list dash-plain-list--links">
                        <li v-for="row in visibleApprovals" :key="row.key">
                            <Link :href="row.href">{{ row.label }}</Link>
                            <span v-if="isLoading('approvals')" class="dash-skel dash-skel--small" />
                            <span v-else class="dash-count" :class="{ 'is-zero': ! widgets.approvals.data[row.key] }">{{ whole(widgets.approvals.data[row.key]) }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Row 2: inventory alerts and the credit limit watch -->
            <div v-if="showInventory || showCredit" class="dash-grid-2">
                <div v-if="showInventory" class="dash-card" data-card="inventory">
                    <div class="dash-card__header">
                        <div class="dash-card__title-group">
                            <h3 class="dash-card__title">Inventory alerts</h3>
                            <span v-if="lowStock" class="dash-card__badge" :class="{ 'dash-card__badge--success': lowStock.count === 0 }">
                                {{ lowStock.count === 0 ? 'Stock is fine' : `${whole(lowStock.count)} low` }}
                            </span>
                        </div>
                        <span class="dash-stat-card__icon dash-stat-card__icon--success"><Boxes aria-hidden="true" /></span>
                    </div>

                    <p v-if="failed('inventory')" class="text-danger mb-0">The figures could not be loaded. <a href="#" @click.prevent="loadWidget('inventory')">Try again</a></p>
                    <template v-else>
                        <div v-if="can('/lowstock')" class="dash-fin-row">
                            <Link href="/lowstock">Items at or under their alert quantity</Link>
                            <span v-if="isLoading('inventory')" class="dash-skel" />
                            <strong v-else>{{ whole(lowStock?.count) }}</strong>
                        </div>
                        <ul v-if="lowStock?.items?.length" class="dash-plain-list">
                            <li v-for="item in lowStock.items" :key="`${item.product_id}-${item.branch_name}`">
                                <span>{{ item.name }} <small class="text-secondary">{{ item.branch_name }}</small></span>
                                <span class="text-danger">{{ money(item.stock) }} / {{ money(item.alert_qty) }} {{ item.unit_name }}</span>
                            </li>
                        </ul>
                        <Link v-if="lowStock && lowStock.count > 0" href="/lowstock" class="dash-more">See the full low stock list</Link>

                        <div v-if="can('/report/stock')" class="dash-fin-row mt-3">
                            <Link href="/report/stock">Stock value today</Link>
                            <span v-if="isLoading('inventory')" class="dash-skel" />
                            <span v-else-if="asksForCompany('inventory', 'stock_value')" class="text-secondary">Choose a company</span>
                            <strong v-else>{{ money(stockValue?.value) }}</strong>
                        </div>
                        <div v-if="stockValue && stockValue.uncosted > 0" class="dash-fin-sub text-warning">{{ stockValue.uncosted }} stocked items have no purchase cost and are left out.</div>
                    </template>
                </div>

                <div v-if="showCredit" class="dash-card" data-card="credit">
                    <div class="dash-card__header">
                        <div class="dash-card__title-group">
                            <h3 class="dash-card__title">Credit limit watch</h3>
                            <span v-if="creditWatch" class="dash-card__badge" :class="{ 'dash-card__badge--success': creditWatch.near + creditWatch.exceeded === 0 }">
                                {{ creditWatch.exceeded }} over · {{ creditWatch.near }} close
                            </span>
                        </div>
                        <span class="dash-stat-card__icon dash-stat-card__icon--danger"><TriangleAlert aria-hidden="true" /></span>
                    </div>

                    <p v-if="asksForCompany('receivables', 'credit_watch')" class="text-secondary mb-0">Choose a company above to see its customers' credit.</p>
                    <p v-else-if="failed('receivables')" class="text-danger mb-0">The figures could not be loaded. <a href="#" @click.prevent="loadWidget('receivables')">Try again</a></p>
                    <div v-else-if="isLoading('receivables')"><span class="dash-skel dash-skel--wide" /></div>
                    <p v-else-if="! creditWatch?.items.length" class="text-secondary mb-0">No customer is close to their credit limit.</p>
                    <div v-else class="dash-country-list">
                        <div v-for="customer in creditWatch.items" :key="customer.id" class="dash-country-row">
                            <div class="dash-country-row__top">
                                <div class="dash-country-row__id">
                                    <div>
                                        <Link :href="`/customer/${customer.id}/view`" class="dash-country-row__name">{{ customer.name }}</Link>
                                        <span class="dash-country-row__share">limit {{ money(customer.credit_limit) }}</span>
                                    </div>
                                </div>
                                <div class="dash-country-row__value">
                                    <span class="dash-country-row__num">{{ money(customer.balance) }}</span>
                                    <span class="dash-country-row__growth" :class="customer.exceeded ? 'text-danger' : 'text-warning'">{{ customer.used_percent }}%</span>
                                </div>
                            </div>
                            <div class="dash-country-row__bar-track">
                                <div class="dash-country-row__bar-fill" :style="{ width: Math.min(customer.used_percent, 100) + '%', background: customer.exceeded ? '#ef4444' : '#f59e0b' }"></div>
                            </div>
                        </div>
                        <Link v-if="creditWatch.near + creditWatch.exceeded > creditWatch.items.length" href="/report/customer-outstanding" class="dash-more">
                            {{ creditWatch.near + creditWatch.exceeded - creditWatch.items.length }} more in the customer outstanding report
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Sales outlook: a simple estimate of next month from the recent ones -->
            <div v-if="showForecast" class="dash-card" data-card="forecast">
                <div class="dash-card__header">
                    <div class="dash-card__title-group">
                        <h3 class="dash-card__title">Sales outlook</h3>
                        <span v-if="forecast?.trend" class="dash-card__badge" :class="{ 'dash-card__badge--success': forecast.trend === 'up' }">
                            Trend: {{ forecast.trend }}
                        </span>
                    </div>
                    <span class="dash-stat-card__icon dash-stat-card__icon--primary"><TrendingUp aria-hidden="true" /></span>
                </div>

                <p v-if="failed('forecast')" class="text-danger mb-0">The outlook could not be loaded. <a href="#" @click.prevent="loadWidget('forecast')">Try again</a></p>
                <div v-else-if="isLoading('forecast')"><span class="dash-skel dash-skel--wide" /></div>
                <p v-else-if="! forecast || forecast.estimate === null" class="text-secondary mb-0">
                    Not enough sales history for an estimate yet: it needs at least two months of sales.
                </p>
                <template v-else>
                    <div class="dash-fin-row">
                        <span>Estimated sales in {{ forecast.target_month }}</span>
                        <strong data-test="forecast-estimate">{{ money(forecast.estimate) }}</strong>
                    </div>
                    <div class="dash-fin-sub">
                        Likely between {{ money(forecast.low) }} and {{ money(forecast.high) }} · from the {{ methodLabel(forecast.method) }}
                        <span v-if="forecast.method === 'linear'"> (fit {{ forecast.fit }})</span>
                    </div>

                    <div class="dash-country-list mt-3">
                        <div v-for="bar in outlookBars" :key="bar.month" class="dash-country-row">
                            <div class="dash-country-row__top">
                                <div class="dash-country-row__id"><span class="dash-country-row__name">{{ bar.month }}<span v-if="bar.estimate" class="text-muted"> (estimate)</span></span></div>
                                <div class="dash-country-row__value"><span class="dash-country-row__num">{{ money(bar.value) }}</span></div>
                            </div>
                            <div class="dash-country-row__bar-track">
                                <div class="dash-country-row__bar-fill" :style="{ width: Math.max((bar.value / outlookMax) * 100, 1) + '%', background: bar.estimate ? '#8b5cf6' : '#6366f1', opacity: bar.estimate ? 0.65 : 1 }"></div>
                            </div>
                        </div>
                    </div>

                    <div class="dash-fin-sub mt-2">
                        {{ forecast.this_month.month }} so far: {{ money(forecast.this_month.sold_so_far) }} after {{ forecast.this_month.days_elapsed }} of {{ forecast.this_month.days_in_month }} days,
                        on course for {{ money(forecast.this_month.run_rate) }}
                    </div>
                </template>
            </div>

            <!-- Row 3: quick stats and recent sales -->
            <div v-if="showStats || showRecent" class="dash-grid-3">
                <div v-if="showStats" class="dash-stats-column">
                    <Link v-for="card in statCards" :key="card.key" :href="card.href" class="dash-stat-card dash-stat-link" :data-card="`stat-${card.key}`">
                        <div>
                            <p class="dash-stat-card__label">{{ card.label }}</p>
                            <span v-if="isLoading('stats')" class="dash-skel" />
                            <h5 v-else class="dash-stat-card__value">{{ whole(widgets.stats.data[card.key]) }}</h5>
                        </div>
                        <span class="dash-stat-card__icon" :class="`dash-stat-card__icon--${card.tone}`"><component :is="card.icon" aria-hidden="true" /></span>
                    </Link>
                </div>

                <div v-if="showRecent" class="dash-card dash-card--wide" data-card="recent">
                    <div class="dash-card__header">
                        <div class="dash-card__title-group">
                            <h3 class="dash-card__title">Recent sales</h3>
                        </div>
                        <Link href="/sell" class="dash-more dash-more--inline"><ShoppingCart :size="14" aria-hidden="true" /> All sales</Link>
                    </div>

                    <p v-if="failed('recent')" class="text-danger mb-0">The sales could not be loaded. <a href="#" @click.prevent="loadWidget('recent')">Try again</a></p>
                    <div v-else-if="isLoading('recent')"><span class="dash-skel dash-skel--wide" /></div>
                    <p v-else-if="! recentSales.length" class="text-secondary mb-0">No sales yet.</p>
                    <div v-else class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr><th>Invoice</th><th>Customer</th><th>Date</th><th>Status</th><th class="text-end">Amount</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="sale in recentSales" :key="sale.id">
                                    <td><Link :href="`/sell/${sale.id}/view`">{{ sale.invoice_no }}</Link></td>
                                    <td>{{ sale.customer }}</td>
                                    <td>{{ sale.date }}</td>
                                    <td><span class="badge" :class="sale.status === 'draft' || sale.status === 'quotation' ? 'bg-secondary-subtle text-secondary' : 'bg-success-subtle text-success'">{{ statusLabels[sale.status] ?? sale.status }}</span></td>
                                    <td class="text-end">{{ money(sale.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dash-kpi-grid--fit {
    grid-template-columns: repeat(auto-fit, minmax(11.5rem, 1fr));
}

.dash-company-select {
    min-width: 12rem;
    max-width: 18rem;
}

.dash-period-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    background: rgba(255, 255, 255, 0.06);
}

.dash-period-pill:disabled {
    opacity: 0.6;
    cursor: default;
}

.dash-refresh {
    width: 13px;
    height: 13px;
}

.dash-refresh.is-spinning {
    animation: dash-spin 0.9s linear infinite;
}

@keyframes dash-spin {
    to {
        transform: rotate(360deg);
    }
}

.dash-skel {
    display: inline-block;
    width: 5.5rem;
    height: 1.1rem;
    border-radius: 6px;
    background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
    background-size: 200% 100%;
    animation: dash-pulse 1.3s ease-in-out infinite;
}

.dash-skel--dark {
    height: 1.7rem;
    margin: 0.3rem 0;
    background: linear-gradient(90deg, rgba(255, 255, 255, 0.08) 25%, rgba(255, 255, 255, 0.16) 50%, rgba(255, 255, 255, 0.08) 75%);
    background-size: 200% 100%;
}

.dash-skel--small {
    width: 2rem;
}

.dash-skel--wide {
    width: 100%;
    height: 4rem;
}

@keyframes dash-pulse {
    to {
        background-position: -200% 0;
    }
}

.dash-fin-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
    font-size: 0.9375rem;
}

.dash-fin-row strong {
    font-size: 1.25rem;
    font-variant-numeric: tabular-nums;
}

.dash-fin-sub {
    margin-top: 0.25rem;
    font-size: 0.75rem;
    color: var(--app-text-secondary, #64748b);
}

.dash-plain-list {
    list-style: none;
    margin: 0.5rem 0 0;
    padding: 0;
}

.dash-plain-list li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.4rem 0;
    font-size: 0.8125rem;
    border-bottom: 1px solid var(--app-border-subtle, #f1f5f9);
    font-variant-numeric: tabular-nums;
}

.dash-plain-list li:last-child {
    border-bottom: none;
}

.dash-plain-list--links li {
    font-size: 0.875rem;
}

.dash-count {
    min-width: 2rem;
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    text-align: center;
    font-weight: 700;
    font-size: 0.8125rem;
    color: #b91c1c;
    background: #fee2e2;
}

.dash-count.is-zero {
    color: #64748b;
    background: #f1f5f9;
    font-weight: 500;
}

.dash-more {
    display: inline-block;
    margin-top: 0.5rem;
    font-size: 0.75rem;
}

.dash-more--inline {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    margin-top: 0;
}

.dash-stats-column {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.dash-stat-link {
    color: inherit;
    text-decoration: none;
}

.dash-stat-link:hover {
    border-color: var(--app-primary, #6366f1);
}

@media (min-width: 992px) {
    .dash-card--wide {
        grid-column: span 2;
    }
}
</style>
