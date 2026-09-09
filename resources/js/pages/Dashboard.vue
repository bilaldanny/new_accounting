<script setup lang="ts">
import {
    Bookmark,
    ChevronDown,
    Cloud,
    Cookie,
    DotsHorizontalRounded,
    Female,
    Globe,
    Male,
    Target,
    TrendingDown,
    TrendingUp,
} from '@boxicons/vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type PeriodType = 'Today' | 'This Week' | 'This Month' | 'This Year';

interface MetricEntry {
    value: string;
    trend: string;
    up: boolean;
}

interface PeriodMetrics {
    sessions: MetricEntry;
    users: MetricEntry;
    pageViews: MetricEntry;
    bounceRate: MetricEntry;
    avgDuration: MetricEntry;
}

const periodMetrics: Record<PeriodType, PeriodMetrics> = {
    Today: {
        sessions: { value: '142', trend: '3.8%', up: true },
        users: { value: '42.1K', trend: '5.1%', up: true },
        pageViews: { value: '3,842', trend: '1.2%', up: false },
        bounceRate: { value: '39.40%', trend: '1.8%', up: true },
        avgDuration: { value: '00:03:45', trend: '2.4%', up: true },
    },
    'This Week': {
        sessions: { value: '418', trend: '4.6%', up: true },
        users: { value: '1.2M', trend: '3.9%', up: true },
        pageViews: { value: '21,450', trend: '2.1%', up: false },
        bounceRate: { value: '41.15%', trend: '1.2%', up: true },
        avgDuration: { value: '00:04:10', trend: '1.8%', up: false },
    },
    'This Month': {
        sessions: { value: '876', trend: '2.1%', up: true },
        users: { value: '4.5M', trend: '4.2%', up: true },
        pageViews: { value: '64,835', trend: '3.6%', up: false },
        bounceRate: { value: '42.68%', trend: '2.5%', up: true },
        avgDuration: { value: '00:04:60', trend: '5.2%', up: false },
    },
    'This Year': {
        sessions: { value: '9,450', trend: '12.8%', up: true },
        users: { value: '38.2M', trend: '14.5%', up: true },
        pageViews: { value: '840,210', trend: '8.4%', up: true },
        bounceRate: { value: '40.22%', trend: '3.1%', up: false },
        avgDuration: { value: '00:05:12', trend: '4.8%', up: true },
    },
};

const periods: PeriodType[] = ['Today', 'This Week', 'This Month', 'This Year'];
const selectedPeriod = ref<PeriodType>('This Month');
const currentMetrics = computed(() => periodMetrics[selectedPeriod.value]);

const usersBarHeights = [14, 22, 16, 28, 18, 26, 16, 22];
const bounceBarHeights = [14, 22, 16, 28, 20, 26, 18, 24];

/* Sales Overview */
const salesMonthlyPoints = [
    { month: 'Jan', value: '$4,250', orders: 580, change: '+12.4%', x: 50, y: 160 },
    { month: 'Feb', value: '$7,840', orders: 920, change: '+18.2%', x: 121, y: 80 },
    { month: 'Mar', value: '$6,120', orders: 740, change: '-4.1%', x: 192, y: 120 },
    { month: 'Apr', value: '$9,480', orders: 1240, change: '+28.5%', x: 263, y: 45 },
    { month: 'May', value: '$5,980', orders: 710, change: '-6.2%', x: 334, y: 120 },
    { month: 'Jun', value: '$7,150', orders: 860, change: '+14.1%', x: 405, y: 95 },
    { month: 'Jul', value: '$3,840', orders: 490, change: '-12.0%', x: 476, y: 175 },
    { month: 'Aug', value: '$4,920', orders: 630, change: '+8.4%', x: 547, y: 160 },
    { month: 'Sep', value: '$2,140', orders: 310, change: '-15.8%', x: 618, y: 205 },
    { month: 'Oct', value: '$3,450', orders: 460, change: '+9.2%', x: 690, y: 190 },
];
const salesSplinePath =
    'M 50 160 C 85 150, 100 85, 125 80 C 155 75, 175 125, 200 120 C 230 115, 245 45, 275 45 C 305 45, 320 125, 345 120 C 375 115, 395 95, 420 95 C 450 95, 470 175, 500 175 C 530 175, 550 160, 580 160 C 610 160, 630 205, 660 205 C 675 205, 685 195, 690 190';
const salesAreaPath = `${salesSplinePath} L 690 205 L 50 205 Z`;
const salesMetricType = ref<'revenue' | 'orders'>('revenue');
const hoveredSalesIndex = ref<number | null>(null);
const showPeriodDropdown = ref(false);

/* Visitor Status */
const visitorData = [
    { month: 'Jan', newHeight: 62, oldHeight: 52, newCount: '62K', oldCount: '52K', total: '114K' },
    { month: 'Feb', newHeight: 74, oldHeight: 44, newCount: '74K', oldCount: '44K', total: '118K' },
    { month: 'Mar', newHeight: 82, oldHeight: 58, newCount: '82K', oldCount: '58K', total: '140K' },
    { month: 'Apr', newHeight: 100, oldHeight: 62, newCount: '100K', oldCount: '62K', total: '162K' },
    { month: 'May', newHeight: 62, oldHeight: 58, newCount: '62K', oldCount: '58K', total: '120K' },
    { month: 'Jun', newHeight: 84, oldHeight: 60, newCount: '84K', oldCount: '60K', total: '144K' },
    { month: 'Jul', newHeight: 104, oldHeight: 56, newCount: '104K', oldCount: '56K', total: '160K' },
    { month: 'Aug', newHeight: 88, oldHeight: 62, newCount: '88K', oldCount: '62K', total: '150K' },
    { month: 'Sep', newHeight: 84, oldHeight: 56, newCount: '84K', oldCount: '56K', total: '140K' },
];
const showNewVisitors = ref(true);
const showOldVisitors = ref(true);
const hoveredVisitorIndex = ref<number | null>(null);

/* Impressions by country (same regions as the previous static dashboard) */
const countryImpressions = [
    { id: 'us', country: 'United States', flag: '/assets/images/icons/united-states.png', value: '445,85', percent: 86, growth: '+14.2%', share: '34.8%', color: '#6366f1' },
    { id: 'de', country: 'Germany', flag: '/assets/images/icons/germany.png', value: '683,46', percent: 66, growth: '+8.6%', share: '24.1%', color: '#8b5cf6' },
    { id: 'ca', country: 'Canada', flag: '/assets/images/icons/canada.png', value: '982,43', percent: 56, growth: '+18.5%', share: '19.4%', color: '#f43f5e' },
    { id: 'in', country: 'India', flag: '/assets/images/icons/india.png', value: '852,35', percent: 45, growth: '+22.4%', share: '14.8%', color: '#10b981' },
    { id: 'nl', country: 'Netherlands', flag: '/assets/images/icons/netherlands.png', value: '785,24', percent: 38, growth: '+5.3%', share: '6.9%', color: '#f97316' },
];

/* Global reach summary (a lighter stand-in for the reference's bespoke world map) */
const globalReachRegions = [
    { name: 'North America', pct: '54.2%', color: '#6366f1' },
    { name: 'Europe', pct: '30.9%', color: '#8b5cf6' },
    { name: 'Asia Pacific', pct: '14.8%', color: '#10b981' },
];
const globalReachTotal = '3.7M';
const globalReachCircumference = 2 * Math.PI * 60;

/* Goal statistics */
const goalStats = [
    { label: 'Sales', current: 1580, target: 875, percent: 85, color: 'var(--app-primary, #6366f1)' },
    { label: 'Users', current: 1852, target: 356, percent: 65, color: 'var(--app-danger, #dc2626)' },
    { label: 'Visits', current: 1280, target: 867, percent: 45, color: '#16a34a' },
];

/* Device type */
const deviceStats = [
    { name: 'Android', pct: 61, delta: '+8.4%', up: true, color: '#0ea5e9' },
    { name: 'iOS', pct: 28, delta: '-1.9%', up: false, color: '#16a34a' },
    { name: 'Other', pct: 11, delta: '+6.8%', up: true, color: '#f59e0b' },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="dash-shell">
        <!-- Dark KPI strip -->
        <div class="dash-kpi-band">
            <div class="dash-kpi-grid">
                <div class="dash-kpi-cell">
                    <span class="dash-kpi-cell__label">Sessions</span>
                    <span class="dash-kpi-cell__value">{{ currentMetrics.sessions.value }}</span>
                    <div class="dash-kpi-cell__trend">
                        <span class="dash-kpi-cell__trend-badge" :class="currentMetrics.sessions.up ? 'is-up' : 'is-down'">
                            <TrendingUp v-if="currentMetrics.sessions.up" size="xs" aria-hidden="true" />
                            <TrendingDown v-else size="xs" aria-hidden="true" />
                            {{ currentMetrics.sessions.trend }}
                        </span>
                        <span>vs last period</span>
                    </div>
                    <svg class="dash-kpi-cell__spark" viewBox="0 0 160 36" preserveAspectRatio="none">
                        <path d="M 0 26 C 25 32, 50 10, 75 20 C 100 30, 125 8, 160 20 L 160 36 L 0 36 Z" fill="rgba(168,85,247,0.18)" />
                        <path d="M 0 26 C 25 32, 50 10, 75 20 C 100 30, 125 8, 160 20" fill="none" stroke="#a855f7" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </div>

                <div class="dash-kpi-cell">
                    <span class="dash-kpi-cell__label">Total Users</span>
                    <span class="dash-kpi-cell__value">{{ currentMetrics.users.value }}</span>
                    <div class="dash-kpi-cell__trend">
                        <span class="dash-kpi-cell__trend-badge" :class="currentMetrics.users.up ? 'is-up' : 'is-down'">
                            <TrendingUp v-if="currentMetrics.users.up" size="xs" aria-hidden="true" />
                            <TrendingDown v-else size="xs" aria-hidden="true" />
                            {{ currentMetrics.users.trend }}
                        </span>
                        <span>vs last period</span>
                    </div>
                    <div class="dash-kpi-cell__bars">
                        <span v-for="(h, i) in usersBarHeights" :key="i" class="dash-kpi-cell__bar" :style="{ height: h + 'px', background: '#ef4444' }"></span>
                    </div>
                </div>

                <div class="dash-kpi-cell">
                    <span class="dash-kpi-cell__label">Page Views</span>
                    <span class="dash-kpi-cell__value">{{ currentMetrics.pageViews.value }}</span>
                    <div class="dash-kpi-cell__trend">
                        <span class="dash-kpi-cell__trend-badge" :class="currentMetrics.pageViews.up ? 'is-up' : 'is-down'">
                            <TrendingUp v-if="currentMetrics.pageViews.up" size="xs" aria-hidden="true" />
                            <TrendingDown v-else size="xs" aria-hidden="true" />
                            {{ currentMetrics.pageViews.trend }}
                        </span>
                        <span>vs last period</span>
                    </div>
                    <svg class="dash-kpi-cell__spark" viewBox="0 0 160 36" preserveAspectRatio="none">
                        <path d="M 0 30 L 40 24 L 75 8 L 105 28 L 130 18 L 160 26 L 160 36 L 0 36 Z" fill="rgba(234,179,8,0.16)" />
                        <path d="M 0 30 L 40 24 L 75 8 L 105 28 L 130 18 L 160 26" fill="none" stroke="#eab308" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </div>

                <div class="dash-kpi-cell">
                    <span class="dash-kpi-cell__label">Bounce Rate</span>
                    <span class="dash-kpi-cell__value">{{ currentMetrics.bounceRate.value }}</span>
                    <div class="dash-kpi-cell__trend">
                        <span class="dash-kpi-cell__trend-badge" :class="currentMetrics.bounceRate.up ? 'is-up' : 'is-down'">
                            <TrendingUp v-if="currentMetrics.bounceRate.up" size="xs" aria-hidden="true" />
                            <TrendingDown v-else size="xs" aria-hidden="true" />
                            {{ currentMetrics.bounceRate.trend }}
                        </span>
                        <span>vs last period</span>
                    </div>
                    <div class="dash-kpi-cell__bars">
                        <span v-for="(h, i) in bounceBarHeights" :key="i" class="dash-kpi-cell__bar" :style="{ height: h + 'px', background: '#06b6d4' }"></span>
                    </div>
                </div>

                <div class="dash-kpi-cell">
                    <span class="dash-kpi-cell__label">Avg. Session Duration</span>
                    <span class="dash-kpi-cell__value">{{ currentMetrics.avgDuration.value }}</span>
                    <div class="dash-kpi-cell__trend">
                        <span class="dash-kpi-cell__trend-badge" :class="currentMetrics.avgDuration.up ? 'is-up' : 'is-down'">
                            <TrendingUp v-if="currentMetrics.avgDuration.up" size="xs" aria-hidden="true" />
                            <TrendingDown v-else size="xs" aria-hidden="true" />
                            {{ currentMetrics.avgDuration.trend }}
                        </span>
                        <span>vs last period</span>
                    </div>
                    <svg class="dash-kpi-cell__spark" viewBox="0 0 160 36" preserveAspectRatio="none">
                        <path d="M 0 25 C 25 32, 50 10, 75 22 C 100 34, 125 12, 160 20 L 160 36 L 0 36 Z" fill="rgba(34,197,94,0.16)" />
                        <path d="M 0 25 C 25 32, 50 10, 75 22 C 100 34, 125 12, 160 20" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </div>
            </div>
        </div>

        <!-- Light content area -->
        <div class="dash-content">
            <!-- Row 1: Sales Overview & Visitor Status -->
            <div class="dash-grid-2">
                <!-- Sales Overview -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <div class="dash-card__title-group">
                            <h3 class="dash-card__title">Sales Overview</h3>
                            <span class="dash-card__badge">+18.4% YoY</span>
                        </div>
                        <div class="dash-card__controls">
                            <div class="dash-toggle-group">
                                <button type="button" class="dash-toggle-btn" :class="{ 'is-active': salesMetricType === 'revenue' }" @click="salesMetricType = 'revenue'">Revenue ($)</button>
                                <button type="button" class="dash-toggle-btn" :class="{ 'is-active': salesMetricType === 'orders' }" @click="salesMetricType = 'orders'">Orders</button>
                            </div>
                            <div class="dash-dropdown">
                                <button type="button" class="dash-dropdown__trigger" @click="showPeriodDropdown = !showPeriodDropdown">
                                    <span>{{ selectedPeriod }}</span>
                                    <ChevronDown aria-hidden="true" />
                                </button>
                                <div v-if="showPeriodDropdown" class="dash-dropdown__menu">
                                    <button
                                        v-for="period in periods"
                                        :key="period"
                                        type="button"
                                        class="dash-dropdown__item"
                                        :class="{ 'is-active': selectedPeriod === period }"
                                        @click="selectedPeriod = period; showPeriodDropdown = false"
                                    >
                                        {{ period }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="dash-chart-wrap">
                        <div v-if="hoveredSalesIndex !== null" class="dash-chart-tooltip" style="top: 0; right: 0;">
                            <div class="dash-chart-tooltip__header">
                                <span>{{ salesMonthlyPoints[hoveredSalesIndex].month }}</span>
                                <span style="color: #4ade80;">{{ salesMonthlyPoints[hoveredSalesIndex].change }}</span>
                            </div>
                            <div class="dash-chart-tooltip__value">
                                {{ salesMetricType === 'revenue' ? salesMonthlyPoints[hoveredSalesIndex].value : `${salesMonthlyPoints[hoveredSalesIndex].orders} orders` }}
                            </div>
                        </div>

                        <svg viewBox="0 0 700 240" class="w-100" style="height: 15rem; overflow: visible;">
                            <defs>
                                <linearGradient id="purpleSalesGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#8b5cf6" stop-opacity="0.4" />
                                    <stop offset="100%" stop-color="#8b5cf6" stop-opacity="0" />
                                </linearGradient>
                            </defs>

                            <line v-for="y in [35, 75, 115, 155, 195]" :key="y" x1="45" :y1="y" x2="690" :y2="y" stroke="#e2e8f0" stroke-dasharray="3 3" />

                            <text x="35" y="39" text-anchor="end" font-size="11" fill="#94a3b8">10K</text>
                            <text x="35" y="79" text-anchor="end" font-size="11" fill="#94a3b8">8K</text>
                            <text x="35" y="119" text-anchor="end" font-size="11" fill="#94a3b8">6K</text>
                            <text x="35" y="159" text-anchor="end" font-size="11" fill="#94a3b8">4K</text>
                            <text x="35" y="199" text-anchor="end" font-size="11" fill="#94a3b8">2K</text>

                            <path :d="salesAreaPath" fill="url(#purpleSalesGrad)" />
                            <path :d="salesSplinePath" fill="none" stroke="#8b5cf6" stroke-width="2.8" stroke-linecap="round" />

                            <line
                                v-if="hoveredSalesIndex !== null"
                                :x1="salesMonthlyPoints[hoveredSalesIndex].x"
                                y1="35"
                                :x2="salesMonthlyPoints[hoveredSalesIndex].x"
                                y2="205"
                                stroke="#8b5cf6"
                                stroke-width="1.5"
                                stroke-dasharray="2 2"
                            />

                            <g v-for="(pt, idx) in salesMonthlyPoints" :key="pt.month">
                                <circle
                                    :cx="pt.x" :cy="pt.y" r="16" fill="transparent" style="cursor: pointer;"
                                    @mouseenter="hoveredSalesIndex = idx" @mouseleave="hoveredSalesIndex = null"
                                />
                                <circle
                                    :cx="pt.x" :cy="pt.y" :r="hoveredSalesIndex === idx ? 6 : 3.5"
                                    :fill="hoveredSalesIndex === idx ? '#8b5cf6' : '#ffffff'"
                                    stroke="#8b5cf6" :stroke-width="hoveredSalesIndex === idx ? 3 : 2"
                                    style="pointer-events: none; transition: all 150ms ease;"
                                />
                            </g>

                            <text
                                v-for="(pt, idx) in salesMonthlyPoints" :key="`label-${pt.month}`"
                                :x="pt.x" y="228" text-anchor="middle" font-size="11"
                                :fill="hoveredSalesIndex === idx ? '#7c3aed' : '#94a3b8'"
                                :font-weight="hoveredSalesIndex === idx ? 700 : 400"
                                style="cursor: pointer;"
                                @mouseenter="hoveredSalesIndex = idx" @mouseleave="hoveredSalesIndex = null"
                            >{{ pt.month }}</text>
                        </svg>
                    </div>
                </div>

                <!-- Visitor Status -->
                <div class="dash-card">
                    <div class="dash-card__header">
                        <div class="dash-card__title-group">
                            <h3 class="dash-card__title">Visitor Status</h3>
                            <span class="dash-card__badge dash-card__badge--success">94.2% Return Rate</span>
                        </div>
                        <div class="dash-legend-pills">
                            <button type="button" class="dash-legend-pill" :class="showNewVisitors ? 'is-active' : 'is-off'" @click="showNewVisitors = !showNewVisitors">
                                <span class="dash-legend-dot" style="background:#7c3aed;"></span>
                                New Visitor
                            </button>
                            <button type="button" class="dash-legend-pill" :class="showOldVisitors ? 'is-active' : 'is-off'" @click="showOldVisitors = !showOldVisitors">
                                <span class="dash-legend-dot" style="background:#c4b5fd;"></span>
                                Old Visitor
                            </button>
                        </div>
                    </div>

                    <div class="dash-chart-wrap">
                        <div v-if="hoveredVisitorIndex !== null" class="dash-chart-tooltip" style="top: 0; right: 0;">
                            <div class="dash-chart-tooltip__header"><span>{{ visitorData[hoveredVisitorIndex].month }} Traffic</span></div>
                            <div class="dash-chart-tooltip__row">
                                <span><span class="dash-chart-tooltip__dot" style="background:#7c3aed;"></span>New</span>
                                <strong>{{ visitorData[hoveredVisitorIndex].newCount }}</strong>
                            </div>
                            <div class="dash-chart-tooltip__row">
                                <span><span class="dash-chart-tooltip__dot" style="background:#c4b5fd;"></span>Returning</span>
                                <strong>{{ visitorData[hoveredVisitorIndex].oldCount }}</strong>
                            </div>
                            <div class="dash-chart-tooltip__total">
                                <span>Total</span>
                                <span style="color:#c4b5fd;">{{ visitorData[hoveredVisitorIndex].total }}</span>
                            </div>
                        </div>

                        <div style="height: 15rem; padding-left: 2.5rem; display: flex; align-items: flex-end; justify-content: space-between; gap: 0.5rem;">
                            <div
                                v-for="(item, idx) in visitorData" :key="item.month"
                                style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.375rem; cursor: pointer; height: 100%; justify-content: flex-end;"
                                @mouseenter="hoveredVisitorIndex = idx" @mouseleave="hoveredVisitorIndex = null"
                            >
                                <div style="display:flex; align-items:flex-end; gap:3px; height: 11rem; width: 100%; justify-content:center;">
                                    <span v-if="showNewVisitors" :style="{ height: item.newHeight + '%', width: '10px', background: '#7c3aed', borderRadius: '2px 2px 0 0', opacity: hoveredVisitorIndex === idx ? 1 : 0.9 }"></span>
                                    <span v-if="showOldVisitors" :style="{ height: item.oldHeight + '%', width: '10px', background: '#c4b5fd', borderRadius: '2px 2px 0 0', opacity: hoveredVisitorIndex === idx ? 1 : 0.9 }"></span>
                                </div>
                                <span :style="{ fontSize: '0.6875rem', color: hoveredVisitorIndex === idx ? '#7c3aed' : '#94a3b8', fontWeight: hoveredVisitorIndex === idx ? 700 : 400 }">{{ item.month }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 2: Global Reach & Impressions By Country -->
            <div class="dash-grid-2">
                <div class="dash-card">
                    <div class="dash-card__header">
                        <div class="dash-card__title-group">
                            <h3 class="dash-card__title">Global Reach</h3>
                            <span class="dash-card__badge"><Globe size="xs" aria-hidden="true" /> Live</span>
                        </div>
                        <button type="button" class="dash-icon-btn"><DotsHorizontalRounded aria-hidden="true" /></button>
                    </div>

                    <div class="dash-globe-summary">
                        <div class="dash-globe-ring">
                            <svg viewBox="0 0 140 140" width="148" height="148">
                                <circle cx="70" cy="70" r="60" fill="none" stroke="#f1f5f9" stroke-width="14" />
                                <circle
                                    cx="70" cy="70" r="60" fill="none" stroke="#6366f1" stroke-width="14"
                                    stroke-linecap="round"
                                    :stroke-dasharray="`${globalReachCircumference * 0.542} ${globalReachCircumference}`"
                                    transform="rotate(-90 70 70)"
                                />
                                <circle
                                    cx="70" cy="70" r="60" fill="none" stroke="#8b5cf6" stroke-width="14"
                                    stroke-linecap="round"
                                    :stroke-dasharray="`${globalReachCircumference * 0.309} ${globalReachCircumference}`"
                                    :stroke-dashoffset="-(globalReachCircumference * 0.542)"
                                    transform="rotate(-90 70 70)"
                                />
                                <circle
                                    cx="70" cy="70" r="60" fill="none" stroke="#10b981" stroke-width="14"
                                    stroke-linecap="round"
                                    :stroke-dasharray="`${globalReachCircumference * 0.148} ${globalReachCircumference}`"
                                    :stroke-dashoffset="-(globalReachCircumference * (0.542 + 0.309))"
                                    transform="rotate(-90 70 70)"
                                />
                            </svg>
                            <div class="dash-globe-ring__value">
                                <span class="dash-globe-ring__num">{{ globalReachTotal }}</span>
                                <span class="dash-globe-ring__label">Sessions</span>
                            </div>
                        </div>

                        <div class="dash-globe-regions">
                            <div v-for="region in globalReachRegions" :key="region.name" class="dash-globe-region">
                                <span class="dash-globe-region__dot" :style="{ background: region.color }"></span>
                                <div>
                                    <span class="dash-globe-region__name">{{ region.name }}</span>
                                    <span class="dash-globe-region__pct">{{ region.pct }} of total</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dash-card">
                    <div class="dash-card__header">
                        <div class="dash-card__title-group">
                            <h3 class="dash-card__title">Impressions By Country</h3>
                            <span class="dash-card__badge">5 Top Regions</span>
                        </div>
                        <button type="button" class="dash-icon-btn"><DotsHorizontalRounded aria-hidden="true" /></button>
                    </div>

                    <div class="dash-country-list">
                        <div v-for="item in countryImpressions" :key="item.id" class="dash-country-row">
                            <div class="dash-country-row__top">
                                <div class="dash-country-row__id">
                                    <img :src="item.flag" :alt="item.country" class="dash-country-flag">
                                    <div>
                                        <span class="dash-country-row__name">{{ item.country }}</span>
                                        <span class="dash-country-row__share">{{ item.share }} total volume</span>
                                    </div>
                                </div>
                                <div class="dash-country-row__value">
                                    <span class="dash-country-row__num">{{ item.value }}</span>
                                    <span class="dash-country-row__growth">{{ item.growth }}</span>
                                </div>
                            </div>
                            <div class="dash-country-row__bar-track">
                                <div class="dash-country-row__bar-fill" :style="{ width: item.percent + '%', background: item.color }"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 3: Small stats, Goal Statistics, Device Type -->
            <div class="dash-grid-3">
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div class="dash-stat-card">
                        <div>
                            <p class="dash-stat-card__label">New Sessions</p>
                            <h5 class="dash-stat-card__value">54.6%</h5>
                        </div>
                        <span class="dash-stat-card__icon dash-stat-card__icon--primary"><Cookie aria-hidden="true" /></span>
                    </div>
                    <div class="dash-stat-card">
                        <div>
                            <p class="dash-stat-card__label">Average Pages</p>
                            <h5 class="dash-stat-card__value">38.5%</h5>
                        </div>
                        <span class="dash-stat-card__icon dash-stat-card__icon--danger"><Bookmark aria-hidden="true" /></span>
                    </div>
                    <div class="dash-stat-card">
                        <div>
                            <p class="dash-stat-card__label">Cloud Download</p>
                            <h5 class="dash-stat-card__value">24.5K</h5>
                        </div>
                        <span class="dash-stat-card__icon dash-stat-card__icon--success"><Cloud aria-hidden="true" /></span>
                    </div>
                </div>

                <div class="dash-card">
                    <div class="dash-card__header">
                        <div class="dash-card__title-group">
                            <h3 class="dash-card__title">Goal Statistics</h3>
                            <span class="dash-card__badge"><Target size="xs" aria-hidden="true" /> Q3</span>
                        </div>
                    </div>
                    <div>
                        <div v-for="goal in goalStats" :key="goal.label" class="dash-goal-row">
                            <span class="dash-goal-row__label">{{ goal.label }}</span>
                            <span class="dash-goal-row__num">{{ goal.current }}</span>
                            <span class="dash-goal-row__num">{{ goal.target }}</span>
                            <div class="dash-goal-row__track">
                                <div class="dash-goal-row__fill" :style="{ width: goal.percent + '%', background: goal.color }"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div class="dash-card" style="flex-direction: row; align-items: center; justify-content: center; gap: 1.5rem;">
                        <svg viewBox="0 0 42 42" width="84" height="84">
                            <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#fee2e2" stroke-width="4" />
                            <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#f43f5e" stroke-width="4" stroke-dasharray="65 100" stroke-linecap="round" transform="rotate(-90 21 21)" />
                        </svg>
                        <div class="dash-gender-split" style="flex-direction: column; align-items: flex-start; gap: 0.5rem;">
                            <div class="dash-gender-row"><Male size="sm" style="color:#f43f5e;" aria-hidden="true" /> Male <strong>65%</strong></div>
                            <div class="dash-gender-row"><Female size="sm" style="color:#6366f1;" aria-hidden="true" /> Female <strong>35%</strong></div>
                        </div>
                    </div>

                    <div class="dash-card">
                        <div class="dash-card__header" style="margin-bottom: 0.75rem; padding-bottom: 0.75rem;">
                            <h3 class="dash-card__title">Device Type</h3>
                            <button type="button" class="dash-icon-btn"><DotsHorizontalRounded aria-hidden="true" /></button>
                        </div>
                        <div class="dash-device-grid">
                            <div v-for="device in deviceStats" :key="device.name">
                                <div class="dash-device-cell__pct">
                                    <span class="dash-device-cell__num">{{ device.pct }}%</span>
                                    <span class="dash-device-cell__delta" :class="device.up ? 'is-up' : 'is-down'">{{ device.delta }}</span>
                                </div>
                                <div class="dash-device-cell__name">
                                    <span class="dash-device-dot" :style="{ background: device.color }"></span>
                                    {{ device.name }}
                                </div>
                            </div>
                        </div>
                        <div class="dash-device-track">
                            <span v-for="device in deviceStats" :key="`bar-${device.name}`" :style="{ width: device.pct + '%', background: device.color }"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
