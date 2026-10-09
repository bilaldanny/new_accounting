<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    /**
     * CSS-bar-chart style like Dashboard.vue / CRM Analytics — no new charting library.
     */
    defineOptions({
        layout: {
            title: 'Subscription Analytics',
            subtitle: 'Active/Expired/Cancelled, Renewal Due, MRR, ARR, Churn, LTV, Revenue',
            breadcrumbs: [
                { title: 'Subscription Analytics', href: 'NULL' },
            ],
        },
    });

    type Summary = {
        active: number; trial: number; paused: number; cancelled: number; expired: number;
        renewal_due_7_days: number; mrr: number; arr: number;
    };
    type Churn = { days: number; active_at_start: number; cancelled_in_window: number; churn_rate_percent: number };
    type Ltv = { subscriptions_counted: number; total_revenue: number; average_ltv: number };
    type RevenueRow = { month: string; revenue: number };
    type RenewalRow = { id: number; customer_name: string | null; plan_name: string | null; renews_on: string };

    const { Notify } = useCommons();

    const loading = ref(true);
    const summary = ref<Summary | null>(null);
    const churn = ref<Churn | null>(null);
    const ltv = ref<Ltv | null>(null);
    const revenue = ref<RevenueRow[]>([]);
    const renewalDue = ref<RenewalRow[]>([]);

    const maxRevenue = () => Math.max(1, ...revenue.value.map((row) => row.revenue));

    async function load() {
        loading.value = true;

        try {
            const [summaryRes, churnRes, ltvRes, revenueRes, renewalRes] = await Promise.all([
                window.axios.get(API_ENDPOINTS.customerSubscriptionAnalyticsSummary),
                window.axios.get(API_ENDPOINTS.customerSubscriptionAnalyticsChurn),
                window.axios.get(API_ENDPOINTS.customerSubscriptionAnalyticsLtv),
                window.axios.get(API_ENDPOINTS.customerSubscriptionAnalyticsRevenue),
                window.axios.get(API_ENDPOINTS.customerSubscriptionAnalyticsRenewalDue),
            ]);
            summary.value = summaryRes.data;
            churn.value = churnRes.data;
            ltv.value = ltvRes.data;
            revenue.value = revenueRes.data?.data ?? [];
            renewalDue.value = renewalRes.data?.data ?? [];
        } catch {
            Notify('Unable to load subscription analytics.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Subscription Analytics" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading subscription analytics…" />

                <template v-else-if="summary">
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-2" v-for="stat in [
                            { label: 'Active', value: summary.active },
                            { label: 'Trial', value: summary.trial },
                            { label: 'Paused', value: summary.paused },
                            { label: 'Cancelled', value: summary.cancelled },
                            { label: 'Expired', value: summary.expired },
                            { label: 'Renewal due (7d)', value: summary.renewal_due_7_days },
                        ]" :key="stat.label">
                            <div class="border rounded p-3 text-center h-100">
                                <div class="fs-4 fw-semibold">{{ stat.value }}</div>
                                <div class="text-muted small">{{ stat.label }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="border rounded p-3 text-center h-100">
                                <div class="fs-4 fw-semibold">{{ summary.mrr }}</div>
                                <div class="text-muted small">MRR</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 text-center h-100">
                                <div class="fs-4 fw-semibold">{{ summary.arr }}</div>
                                <div class="text-muted small">ARR</div>
                            </div>
                        </div>
                        <div class="col-md-3" v-if="churn">
                            <div class="border rounded p-3 text-center h-100">
                                <div class="fs-4 fw-semibold">{{ churn.churn_rate_percent }}%</div>
                                <div class="text-muted small">Churn ({{ churn.days }}d)</div>
                            </div>
                        </div>
                        <div class="col-md-3" v-if="ltv">
                            <div class="border rounded p-3 text-center h-100">
                                <div class="fs-4 fw-semibold">{{ ltv.average_ltv }}</div>
                                <div class="text-muted small">Average LTV</div>
                            </div>
                        </div>
                    </div>

                    <h3 class="h6">Revenue, last 6 months</h3>
                    <div class="mb-4">
                        <div v-for="row in revenue" :key="row.month" class="d-flex align-items-center gap-2 mb-1">
                            <div class="text-muted small" style="width: 5rem">{{ row.month }}</div>
                            <div class="flex-grow-1 bg-light rounded" style="height: 1.25rem">
                                <div class="bg-primary rounded h-100" :style="{ width: `${(row.revenue / maxRevenue()) * 100}%` }"></div>
                            </div>
                            <div class="small" style="width: 5rem">{{ row.revenue }}</div>
                        </div>
                    </div>

                    <h3 class="h6">Renewal due (next 7 days)</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Customer</th><th>Plan</th><th>Renews on</th></tr></thead>
                            <tbody>
                                <tr v-for="row in renewalDue" :key="row.id">
                                    <td>{{ row.customer_name }}</td>
                                    <td>{{ row.plan_name }}</td>
                                    <td>{{ row.renews_on }}</td>
                                </tr>
                                <tr v-if="! renewalDue.length">
                                    <td colspan="3" class="text-center text-muted">Nothing renewing in the next 7 days.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
