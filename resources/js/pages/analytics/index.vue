<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Analytics',
            subtitle: 'Sales trend, forecast and the split of sales and profit',
            breadcrumbs: [{ title: 'Reports', href: 'NULL' }, { title: 'Analytics', href: 'NULL' }],
        },
    });

    type Named = { name: string; net?: number; sales_count?: number; share?: number; profit?: number; sales?: number; margin?: number | null };
    type Overview = {
        period: { from: string; to: string };
        totals: Record<string, number>;
        monthly_sales: { month: string; net_sales: number }[];
        forecast: { target_month: string; estimate: number | null; low: number | null; high: number | null; method: string | null; trend: string | null; reason: string | null };
        by_salesperson: Named[];
        by_payment_method: Named[];
        profit_by_branch: Named[];
        profit_by_customer: Named[];
    };

    const { companyId, isSuperadmin, scope, money, run } = useCloseout();
    const data = ref<Overview | null>(null);
    const from = ref('');
    const to = ref('');

    const peak = computed(() => Math.max(1, ...(data.value?.monthly_sales ?? []).map((month) => Math.abs(month.net_sales))));

    async function load() {
        const response = await run(() => window.axios.get('/api/analytics-overview', { params: { ...scope(), from: from.value || undefined, to: to.value || undefined } }));
        data.value = response?.data?.data ?? null;
    }

    onMounted(load);
</script>

<template>
    <Head title="Analytics" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        <div class="row g-2 align-items-end mb-3">
            <div class="col-auto"><label class="form-label small" for="an-from">From</label><input id="an-from" v-model="from" type="date" class="form-control form-control-sm" @change="load" /></div>
            <div class="col-auto"><label class="form-label small" for="an-to">To</label><input id="an-to" v-model="to" type="date" class="form-control form-control-sm" @change="load" /></div>
        </div>

        <div v-if="data" class="row g-3">
            <div class="col-12">
                <div class="border rounded p-3">
                    <h6>Net sales, last six months</h6>
                    <div v-for="month in data.monthly_sales" :key="month.month" class="d-flex align-items-center gap-2 mb-1">
                        <span class="text-muted small" style="width: 5rem">{{ month.month }}</span>
                        <div class="flex-grow-1 bg-light rounded" style="height: 1.1rem">
                            <div class="bg-dark rounded" :style="{ width: `${Math.max(0, (month.net_sales / peak) * 100)}%`, height: '100%' }" />
                        </div>
                        <span class="small text-end" style="width: 8rem">{{ money(month.net_sales) }}</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <h6>Forecast for {{ data.forecast.target_month }}</h6>
                    <template v-if="data.forecast.estimate !== null">
                        <div class="fs-4">{{ money(data.forecast.estimate) }}</div>
                        <div class="text-muted small">Between {{ money(data.forecast.low) }} and {{ money(data.forecast.high) }} ({{ data.forecast.method }}, trend {{ data.forecast.trend }}). An estimate from history, not a plan.</div>
                    </template>
                    <div v-else class="text-muted">Not enough history yet: the forecast needs at least two months with sales.</div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <h6>The period at a glance</h6>
                    <div class="d-flex justify-content-between"><span>Net sales</span><strong>{{ money(data.totals.net_sales) }}</strong></div>
                    <div class="d-flex justify-content-between"><span>Net purchases</span><strong>{{ money(data.totals.net_purchases) }}</strong></div>
                    <div class="text-muted small mt-2">{{ data.period.from }} to {{ data.period.to }}</div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Sales by salesperson</h6>
                    <ClientTable
                        empty-text="No sales in this period."
                        :columns="[{ key: 'c0', label: 'Salesperson' }, { key: 'c1', label: 'Sales' }, { key: 'c2', label: 'Net' }]"
                        :rows="(data.by_salesperson).map((row) => ({ __s: { row }, c0: row.name, c1: `${row.sales_count} sales`, c2: money(row.net) }))"
                        :page-size="10"
                        export-name="by-salesperson"
                    />
                </div>
            </div>

            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Received by payment method</h6>
                    <ClientTable
                        empty-text="No payments in this period."
                        :columns="[{ key: 'c0', label: 'Method' }, { key: 'c1', label: 'Share' }, { key: 'c2', label: 'Received' }]"
                        :rows="(data.by_payment_method).map((row) => ({ __s: { row }, c0: row.name, c1: `${row.share}%`, c2: money(row.net) }))"
                        :page-size="10"
                        export-name="by-payment-method"
                    />
                </div>
            </div>

            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Profit by branch</h6>
                    <ClientTable
                        empty-text="No sold lines in this period."
                        :columns="[{ key: 'c0', label: 'Branch' }, { key: 'c1', label: 'Margin' }, { key: 'c2', label: 'Profit' }]"
                        :rows="(data.profit_by_branch).map((row) => ({ __s: { row }, c0: row.name, c1: `${row.margin === null ? '-' : `${row.margin}%`}`, c2: money(row.profit) }))"
                        :page-size="10"
                        export-name="profit-by-branch"
                    />
                </div>
            </div>

            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Profit by customer</h6>
                    <ClientTable
                        empty-text="No sold lines in this period."
                        :columns="[{ key: 'c0', label: 'Customer' }, { key: 'c1', label: 'Margin' }, { key: 'c2', label: 'Profit' }]"
                        :rows="(data.profit_by_customer).map((row) => ({ __s: { row }, c0: row.name, c1: `${row.margin === null ? '-' : `${row.margin}%`}`, c2: money(row.profit) }))"
                        :page-size="10"
                        export-name="profit-by-customer"
                    />
                </div>
            </div>
        </div>
    </div></div></div>
</template>
