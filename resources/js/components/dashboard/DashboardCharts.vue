<script setup lang="ts">
    import { computed, onMounted, ref, watch } from 'vue';

    /**
     * The dashboard's graphs, drawn as plain SVG (no chart library): sales against purchases by month, the profit trend, where the
     * receivables and payables stand by age, cash and bank by account, the best selling products, and, from the insights endpoint, the
     * top customers and the largest expenses of the month. Every figure is one the dashboard already loads for the user; a graph whose
     * data the user may not see is not drawn.
     */
    type Trend = { month: string; sales: number; purchases: number };
    type Profit = { month: string; net_profit: number };
    type Aging = { buckets: Array<{ label: string; amount: number; count: number }>; total: number };
    type Pair = { name: string; amount: number; extra?: string };

    const props = defineProps<{
        trends: Trend[];
        profitTrend: Profit[];
        receivable?: Aging;
        payable?: Aging;
        topSelling: Array<{ name: string; quantity: number }>;
        cashAccounts: Array<{ name: string; balance: number }>;
        loading: { trends: boolean; aging: boolean; movement: boolean; financial: boolean };
        show: { trends: boolean; aging: boolean; movement: boolean; financial: boolean; insights: boolean };
        params: Record<string, string | number>;
        needsCompany?: boolean;
    }>();

    const COLORS = { sales: '#199683', purchases: '#f59e0b', profit: '#6366f1', loss: '#ef4444', grid: '#e2e8f0', text: '#64748b' };
    const DONUT = ['#199683', '#6366f1', '#f59e0b', '#0ea5e9', '#ec4899', '#84cc16', '#a855f7', '#f97316'];

    const compact = (value: number): string => new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
    const full = (value: number): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const monthName = (month: string): string => {
        const date = new Date(`${month}-01T00:00:00`);

        return Number.isNaN(date.getTime()) ? month : date.toLocaleString('en', { month: 'short' });
    };

    /** A round upper bound for an axis, so the grid lines fall on clean numbers. */
    function niceMax(value: number): number {
        if (value <= 0) {
            return 1;
        }

        const magnitude = 10 ** Math.floor(Math.log10(value));
        const scaled = value / magnitude;

        return (scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10) * magnitude;
    }

    /* ---- sales against purchases: grouped bars ---- */
    const W = 620;
    const H = 250;
    const PAD = { left: 52, right: 14, top: 14, bottom: 30 };
    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top - PAD.bottom;

    const barMax = computed(() => niceMax(Math.max(...props.trends.flatMap((row) => [Number(row.sales), Number(row.purchases)]), 0)));
    const barY = (value: number): number => PAD.top + innerH - (Number(value) / barMax.value) * innerH;
    const groupW = computed(() => innerW / Math.max(props.trends.length, 1));
    const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((fraction) => ({ y: PAD.top + innerH - fraction * innerH, label: compact(barMax.value * fraction) })));
    const hasTrends = computed(() => props.trends.some((row) => Number(row.sales) > 0 || Number(row.purchases) > 0));

    /* ---- profit trend: area and line around a zero line ---- */
    const profitValues = computed(() => props.profitTrend.map((row) => Number(row.net_profit)));
    const profitHigh = computed(() => niceMax(Math.max(...profitValues.value, 0)));
    const profitLow = computed(() => (Math.min(...profitValues.value, 0) < 0 ? -niceMax(-Math.min(...profitValues.value, 0)) : 0));
    const profitY = (value: number): number => PAD.top + innerH - ((value - profitLow.value) / (profitHigh.value - profitLow.value || 1)) * innerH;
    const profitX = (index: number): number => PAD.left + (props.profitTrend.length <= 1 ? innerW / 2 : (index / (props.profitTrend.length - 1)) * innerW);
    const profitPath = computed(() => props.profitTrend.map((row, index) => `${index === 0 ? 'M' : 'L'}${profitX(index).toFixed(1)},${profitY(Number(row.net_profit)).toFixed(1)}`).join(' '));
    const profitArea = computed(() => {
        if (props.profitTrend.length === 0) {
            return '';
        }

        const base = profitY(0).toFixed(1);

        return `${profitPath.value} L${profitX(props.profitTrend.length - 1).toFixed(1)},${base} L${profitX(0).toFixed(1)},${base} Z`;
    });
    const hasProfit = computed(() => profitValues.value.some((value) => value !== 0));

    /* ---- donuts ---- */
    type Slice = { label: string; value: number; color: string };
    const cashSlices = computed<Slice[]>(() => props.cashAccounts.filter((account) => Number(account.balance) > 0).slice(0, 6).map((account, index) => ({ label: account.name, value: Number(account.balance), color: DONUT[index % DONUT.length] })));
    const agingSlices = (aging?: Aging): Slice[] => (aging?.buckets ?? []).map((bucket, index) => ({ label: bucket.label, value: Number(bucket.amount), color: ['#199683', '#84cc16', '#f59e0b', '#ef4444'][index % 4] }));

    const R = 54;
    const CIRC = 2 * Math.PI * R;

    function arcs(slices: Slice[]): Array<Slice & { dash: string; offset: number; share: number }> {
        const total = slices.reduce((sum, slice) => sum + slice.value, 0);
        let acc = 0;

        return slices.filter((slice) => slice.value > 0).map((slice) => {
            const share = total > 0 ? slice.value / total : 0;
            const length = share * CIRC;
            const out = { ...slice, dash: `${length.toFixed(2)} ${(CIRC - length).toFixed(2)}`, offset: -acc, share };
            acc += length;

            return out;
        });
    }

    const total = (slices: Slice[]): number => slices.reduce((sum, slice) => sum + slice.value, 0);

    /* ---- insights: top customers and largest expenses ---- */
    const insights = ref<{ top_customers: Pair[]; expenses: Pair[]; cash_flow: { in: number; out: number; net: number } | null }>({ top_customers: [], expenses: [], cash_flow: null });
    const insightsLoading = ref(false);

    async function loadInsights() {
        if (! props.show.insights || props.needsCompany) {
            insights.value = { top_customers: [], expenses: [], cash_flow: null };

            return;
        }

        insightsLoading.value = true;

        try {
            const response = await window.axios.get('/api/dashboard-insights', { params: props.params });
            const data = response.data?.data ?? {};
            insights.value = {
                top_customers: (data.top_customers ?? []).slice(0, 6).map((row: any) => ({ name: row.name, amount: Number(row.amount), extra: `${row.documents} invoices` })),
                expenses: (data.expenses ?? []).slice(0, 6).map((row: any) => ({ name: row.name, amount: Number(row.amount) })),
                cash_flow: data.cash_flow ?? null,
            };
        } catch {
            insights.value = { top_customers: [], expenses: [], cash_flow: null };
        } finally {
            insightsLoading.value = false;
        }
    }

    const barWidth = (value: number, list: Array<{ amount?: number; quantity?: number }>): string => {
        const max = Math.max(...list.map((row) => Number(row.amount ?? row.quantity ?? 0)), 1);

        return `${Math.max(3, (Number(value) / max) * 100)}%`;
    };

    onMounted(loadInsights);
    watch(() => [JSON.stringify(props.params), props.needsCompany, props.show.insights], loadInsights);
</script>

<template>
    <section class="dc" data-test="dashboard-charts" aria-label="Charts">
        <article v-if="show.trends" class="dc__card dc__card--wide">
            <header class="dc__head">
                <div><h3>Sales and purchases</h3><p>Month by month, the last six months</p></div>
                <div class="dc__legend"><span><i :style="{ background: COLORS.sales }" /> Sales</span><span><i :style="{ background: COLORS.purchases }" /> Purchases</span></div>
            </header>
            <div v-if="loading.trends" class="dc__empty">Loading...</div>
            <div v-else-if="! hasTrends" class="dc__empty">{{ needsCompany ? 'Choose a company above to see its graphs.' : 'No sales or purchases in the last six months yet.' }}</div>
            <svg v-else :viewBox="`0 0 ${W} ${H}`" class="dc__svg" role="img" aria-label="Sales and purchases by month">
                <g v-for="tick in ticks" :key="tick.y">
                    <line :x1="PAD.left" :x2="W - PAD.right" :y1="tick.y" :y2="tick.y" :stroke="COLORS.grid" stroke-dasharray="3 4" />
                    <text :x="PAD.left - 8" :y="tick.y + 4" text-anchor="end" class="dc__tick">{{ tick.label }}</text>
                </g>
                <g v-for="(row, index) in trends" :key="row.month">
                    <rect :x="PAD.left + index * groupW + groupW * 0.16" :y="barY(row.sales)" :width="groupW * 0.32" :height="PAD.top + innerH - barY(row.sales)" rx="4" :fill="COLORS.sales">
                        <title>{{ monthName(row.month) }} sales {{ full(row.sales) }}</title>
                    </rect>
                    <rect :x="PAD.left + index * groupW + groupW * 0.52" :y="barY(row.purchases)" :width="groupW * 0.32" :height="PAD.top + innerH - barY(row.purchases)" rx="4" :fill="COLORS.purchases">
                        <title>{{ monthName(row.month) }} purchases {{ full(row.purchases) }}</title>
                    </rect>
                    <text :x="PAD.left + index * groupW + groupW / 2" :y="H - 8" text-anchor="middle" class="dc__tick">{{ monthName(row.month) }}</text>
                </g>
            </svg>
        </article>

        <article v-if="show.financial" class="dc__card">
            <header class="dc__head"><div><h3>Profit trend</h3><p>Net profit by month</p></div></header>
            <div v-if="loading.trends" class="dc__empty">Loading...</div>
            <div v-else-if="! hasProfit" class="dc__empty">{{ needsCompany ? 'Choose a company above.' : 'No profit figures yet.' }}</div>
            <svg v-else :viewBox="`0 0 ${W} ${H}`" class="dc__svg" role="img" aria-label="Net profit by month">
                <defs>
                    <linearGradient id="dc-profit" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" :stop-color="COLORS.profit" stop-opacity="0.35" /><stop offset="100%" :stop-color="COLORS.profit" stop-opacity="0.02" /></linearGradient>
                </defs>
                <line :x1="PAD.left" :x2="W - PAD.right" :y1="profitY(0)" :y2="profitY(0)" :stroke="COLORS.text" stroke-opacity="0.45" />
                <text :x="PAD.left - 8" :y="profitY(profitHigh) + 4" text-anchor="end" class="dc__tick">{{ compact(profitHigh) }}</text>
                <text :x="PAD.left - 8" :y="profitY(0) + 4" text-anchor="end" class="dc__tick">0</text>
                <text v-if="profitLow < 0" :x="PAD.left - 8" :y="profitY(profitLow) + 4" text-anchor="end" class="dc__tick">{{ compact(profitLow) }}</text>
                <path :d="profitArea" fill="url(#dc-profit)" />
                <path :d="profitPath" fill="none" :stroke="COLORS.profit" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
                <g v-for="(row, index) in profitTrend" :key="row.month">
                    <circle :cx="profitX(index)" :cy="profitY(Number(row.net_profit))" r="4.5" :fill="Number(row.net_profit) < 0 ? COLORS.loss : COLORS.profit" stroke="#fff" stroke-width="2">
                        <title>{{ monthName(row.month) }}: {{ full(row.net_profit) }}</title>
                    </circle>
                    <text :x="profitX(index)" :y="H - 8" text-anchor="middle" class="dc__tick">{{ monthName(row.month) }}</text>
                </g>
            </svg>
        </article>

        <article v-if="show.aging" class="dc__card">
            <header class="dc__head"><div><h3>Receivable by age</h3><p>What customers owe, by invoice date</p></div></header>
            <div v-if="loading.aging" class="dc__empty">Loading...</div>
            <div v-else-if="! receivable || total(agingSlices(receivable)) === 0" class="dc__empty">{{ needsCompany ? 'Choose a company above.' : 'Nothing is owed by customers.' }}</div>
            <div v-else class="dc__donut-row">
                <svg viewBox="0 0 140 140" class="dc__donut" role="img" aria-label="Receivable by age">
                    <circle cx="70" cy="70" :r="R" fill="none" stroke="#f1f5f9" stroke-width="22" />
                    <circle v-for="arc in arcs(agingSlices(receivable))" :key="arc.label" cx="70" cy="70" :r="R" fill="none" :stroke="arc.color" stroke-width="22" :stroke-dasharray="arc.dash" :stroke-dashoffset="arc.offset" transform="rotate(-90 70 70)"><title>{{ arc.label }}: {{ full(arc.value) }}</title></circle>
                    <text x="70" y="66" text-anchor="middle" class="dc__donut-label">Total</text>
                    <text x="70" y="84" text-anchor="middle" class="dc__donut-total">{{ compact(receivable.total) }}</text>
                </svg>
                <ul class="dc__keys">
                    <li v-for="slice in agingSlices(receivable)" :key="slice.label"><i :style="{ background: slice.color }" /><span>{{ slice.label }}</span><strong>{{ full(slice.value) }}</strong></li>
                </ul>
            </div>
        </article>

        <article v-if="show.aging" class="dc__card">
            <header class="dc__head"><div><h3>Payable by age</h3><p>What you owe suppliers, by invoice date</p></div></header>
            <div v-if="loading.aging" class="dc__empty">Loading...</div>
            <div v-else-if="! payable || total(agingSlices(payable)) === 0" class="dc__empty">{{ needsCompany ? 'Choose a company above.' : 'Nothing is owed to suppliers.' }}</div>
            <div v-else class="dc__donut-row">
                <svg viewBox="0 0 140 140" class="dc__donut" role="img" aria-label="Payable by age">
                    <circle cx="70" cy="70" :r="R" fill="none" stroke="#f1f5f9" stroke-width="22" />
                    <circle v-for="arc in arcs(agingSlices(payable))" :key="arc.label" cx="70" cy="70" :r="R" fill="none" :stroke="arc.color" stroke-width="22" :stroke-dasharray="arc.dash" :stroke-dashoffset="arc.offset" transform="rotate(-90 70 70)"><title>{{ arc.label }}: {{ full(arc.value) }}</title></circle>
                    <text x="70" y="66" text-anchor="middle" class="dc__donut-label">Total</text>
                    <text x="70" y="84" text-anchor="middle" class="dc__donut-total">{{ compact(payable.total) }}</text>
                </svg>
                <ul class="dc__keys">
                    <li v-for="slice in agingSlices(payable)" :key="slice.label"><i :style="{ background: slice.color }" /><span>{{ slice.label }}</span><strong>{{ full(slice.value) }}</strong></li>
                </ul>
            </div>
        </article>

        <article v-if="show.financial" class="dc__card">
            <header class="dc__head"><div><h3>Cash and bank</h3><p>Balance of each account</p></div></header>
            <div v-if="loading.financial" class="dc__empty">Loading...</div>
            <div v-else-if="cashSlices.length === 0" class="dc__empty">{{ needsCompany ? 'Choose a company above.' : 'No cash or bank balance yet.' }}</div>
            <div v-else class="dc__donut-row">
                <svg viewBox="0 0 140 140" class="dc__donut" role="img" aria-label="Cash and bank by account">
                    <circle cx="70" cy="70" :r="R" fill="none" stroke="#f1f5f9" stroke-width="22" />
                    <circle v-for="arc in arcs(cashSlices)" :key="arc.label" cx="70" cy="70" :r="R" fill="none" :stroke="arc.color" stroke-width="22" :stroke-dasharray="arc.dash" :stroke-dashoffset="arc.offset" transform="rotate(-90 70 70)"><title>{{ arc.label }}: {{ full(arc.value) }}</title></circle>
                    <text x="70" y="66" text-anchor="middle" class="dc__donut-label">Total</text>
                    <text x="70" y="84" text-anchor="middle" class="dc__donut-total">{{ compact(total(cashSlices)) }}</text>
                </svg>
                <ul class="dc__keys">
                    <li v-for="slice in cashSlices" :key="slice.label"><i :style="{ background: slice.color }" /><span>{{ slice.label }}</span><strong>{{ full(slice.value) }}</strong></li>
                </ul>
            </div>
        </article>

        <article v-if="show.movement" class="dc__card">
            <header class="dc__head"><div><h3>Best selling products</h3><p>Quantity sold in the last 30 days</p></div></header>
            <div v-if="loading.movement" class="dc__empty">Loading...</div>
            <div v-else-if="topSelling.length === 0" class="dc__empty">{{ needsCompany ? 'Choose a company above.' : 'No sales in the last 30 days.' }}</div>
            <ul v-else class="dc__bars">
                <li v-for="row in topSelling.slice(0, 7)" :key="row.name">
                    <div class="dc__bar-top"><span :title="row.name">{{ row.name }}</span><strong>{{ full(row.quantity) }}</strong></div>
                    <div class="dc__bar"><span :style="{ width: barWidth(row.quantity, topSelling) , background: COLORS.sales }" /></div>
                </li>
            </ul>
        </article>

        <article v-if="show.insights" class="dc__card">
            <header class="dc__head"><div><h3>Top customers</h3><p>By sales this month</p></div></header>
            <div v-if="insightsLoading" class="dc__empty">Loading...</div>
            <div v-else-if="insights.top_customers.length === 0" class="dc__empty">{{ needsCompany ? 'Choose a company above.' : 'No sales this month yet.' }}</div>
            <ul v-else class="dc__bars">
                <li v-for="row in insights.top_customers" :key="row.name">
                    <div class="dc__bar-top"><span :title="row.name">{{ row.name }}</span><strong>{{ full(row.amount) }}</strong></div>
                    <div class="dc__bar"><span :style="{ width: barWidth(row.amount, insights.top_customers), background: '#6366f1' }" /></div>
                </li>
            </ul>
        </article>

        <article v-if="show.insights" class="dc__card">
            <header class="dc__head"><div><h3>Largest expenses</h3><p>This month, by account</p></div></header>
            <div v-if="insightsLoading" class="dc__empty">Loading...</div>
            <div v-else-if="insights.expenses.length === 0" class="dc__empty">{{ needsCompany ? 'Choose a company above.' : 'No expenses this month yet.' }}</div>
            <ul v-else class="dc__bars">
                <li v-for="row in insights.expenses" :key="row.name">
                    <div class="dc__bar-top"><span :title="row.name">{{ row.name }}</span><strong>{{ full(row.amount) }}</strong></div>
                    <div class="dc__bar"><span :style="{ width: barWidth(row.amount, insights.expenses), background: '#ef4444' }" /></div>
                </li>
            </ul>
        </article>
    </section>
</template>

<style scoped>
.dc {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.dc__card {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    min-width: 0;
    padding: 1rem 1.15rem;
    border: 1px solid var(--bs-border-color, #e2e8f0);
    border-radius: 1rem;
    background: var(--bs-body-bg, #fff);
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.dc__card--wide {
    grid-column: span 2;
}

@media (max-width: 900px) {
    .dc__card--wide {
        grid-column: auto;
    }
}

.dc__head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.5rem;
}

.dc__head h3 {
    margin: 0;
    font-size: 0.98rem;
    font-weight: 700;
}

.dc__head p {
    margin: 0.1rem 0 0;
    font-size: 0.78rem;
    color: #94a3b8;
}

.dc__legend {
    display: flex;
    gap: 0.9rem;
    font-size: 0.8rem;
    color: #64748b;
}

.dc__legend i,
.dc__keys i {
    display: inline-block;
    width: 0.7rem;
    height: 0.7rem;
    margin-right: 0.35rem;
    border-radius: 3px;
}

.dc__svg {
    width: 100%;
    height: auto;
}

.dc__tick {
    font-size: 10.5px;
    fill: #94a3b8;
}

.dc__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 9rem;
    border: 1px dashed #cbd5e1;
    border-radius: 0.8rem;
    background: rgba(148, 163, 184, 0.06);
    font-size: 0.85rem;
    color: #94a3b8;
    text-align: center;
    padding: 0.5rem 1rem;
}

.dc__donut-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1rem;
}

.dc__donut {
    flex: 0 0 9.5rem;
    width: 9.5rem;
    height: 9.5rem;
}

.dc__donut-label {
    font-size: 9px;
    fill: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.dc__donut-total {
    font-size: 15px;
    font-weight: 800;
    fill: currentColor;
}

.dc__keys {
    flex: 1 1 9rem;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 0.82rem;
}

.dc__keys li {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0;
    border-bottom: 1px solid rgba(148, 163, 184, 0.18);
}

.dc__keys li span {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #64748b;
}

.dc__bars {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.dc__bar-top {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    font-size: 0.83rem;
}

.dc__bar-top span {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #475569;
}

.dc__bar {
    height: 0.5rem;
    margin-top: 0.25rem;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.2);
    overflow: hidden;
}

.dc__bar span {
    display: block;
    height: 100%;
    border-radius: 999px;
}
</style>
