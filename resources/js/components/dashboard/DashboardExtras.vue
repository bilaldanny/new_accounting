<script setup lang="ts">
    import { computed, onMounted, ref, watch } from 'vue';

    /**
     * More dashboard cards from one endpoint (`/api/dashboard-extras`): today against yesterday, the month's receipts by payment
     * method, the branches compared and the HR picture. Plain SVG / CSS bars, a skeleton while loading, an empty state when there is
     * nothing yet; a card the user may not see is simply not in the answer.
     */
    const props = defineProps<{ params: Record<string, string | number>; needsCompany?: boolean }>();

    type Day = { sales: number; purchases: number; received: number; invoices: number };
    const data = ref<{
        today_vs_yesterday?: { today: Day; yesterday: Day };
        payment_methods?: { method: string; amount: number }[];
        branches?: { name: string; sales: number }[];
        hrm?: { headcount: number; present: number; absent: number; on_leave: number; pending_leave: number };
    }>({});
    const loading = ref(true);

    const money = (value: number): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const compact = (value: number): string => new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
    const COLORS = ['#199683', '#6366f1', '#f59e0b', '#0ea5e9', '#ec4899'];
    const METHOD_LABEL: Record<string, string> = { cash: 'Cash', card: 'Card', cheque: 'Cheque', bank_transfer: 'Bank transfer', other: 'Other' };

    async function load() {
        if (props.needsCompany) {
            data.value = {};
            loading.value = false;

            return;
        }

        loading.value = true;

        try {
            const response = await window.axios.get('/api/dashboard-extras', { params: props.params });
            data.value = response.data?.data ?? {};
        } catch {
            data.value = {};
        } finally {
            loading.value = false;
        }
    }

    const compareRows = computed(() => {
        const block = data.value.today_vs_yesterday;

        if (! block) {
            return [];
        }

        return [
            { key: 'sales', label: 'Sales' }, { key: 'purchases', label: 'Purchases' }, { key: 'received', label: 'Received' }, { key: 'invoices', label: 'Invoices' },
        ].map((row) => {
            const today = (block.today as any)[row.key] as number;
            const yesterday = (block.yesterday as any)[row.key] as number;
            const top = Math.max(today, yesterday, 1);

            return { ...row, today, yesterday, todayWidth: (today / top) * 100, yesterdayWidth: (yesterday / top) * 100, delta: today - yesterday };
        });
    });

    const methodTotal = computed(() => (data.value.payment_methods ?? []).reduce((sum, row) => sum + row.amount, 0));
    const branchMax = computed(() => Math.max(...(data.value.branches ?? []).map((row) => row.sales), 1));
    const hasAnything = computed(() => Object.keys(data.value).length > 0);

    watch(() => props.params, load, { deep: true });
    watch(() => props.needsCompany, load);
    onMounted(load);
</script>

<template>
    <div v-if="loading || hasAnything" class="dash-extras" data-test="dashboard-extras">
        <div v-if="loading" class="dash-extras__grid" aria-busy="true">
            <div v-for="n in 3" :key="n" class="dash-card dash-extras__skeleton" />
        </div>

        <div v-else class="dash-extras__grid">
            <div v-if="data.today_vs_yesterday" class="dash-card" data-card="today-vs-yesterday">
                <div class="dash-card__header"><h3 class="dash-card__title">Today vs yesterday</h3></div>
                <div v-for="row in compareRows" :key="row.key" class="dash-extras__row">
                    <div class="d-flex justify-content-between small">
                        <span>{{ row.label }}</span>
                        <span><strong>{{ row.key === 'invoices' ? row.today : money(row.today) }}</strong>
                            <span :class="row.delta >= 0 ? 'text-success' : 'text-danger'"> {{ row.delta >= 0 ? '+' : '' }}{{ row.key === 'invoices' ? row.delta : compact(row.delta) }}</span></span>
                    </div>
                    <div class="dash-extras__bar"><i :style="{ width: `${row.todayWidth}%`, background: '#199683' }" /></div>
                    <div class="dash-extras__bar dash-extras__bar--thin"><i :style="{ width: `${row.yesterdayWidth}%`, background: '#cbd5e1' }" /></div>
                </div>
                <p class="small text-muted mb-0 mt-1"><i class="dash-extras__key" style="background: #199683" /> today <i class="dash-extras__key" style="background: #cbd5e1" /> yesterday</p>
            </div>

            <div v-if="data.payment_methods" class="dash-card" data-card="payment-methods">
                <div class="dash-card__header"><h3 class="dash-card__title">Receipts by payment method <span class="dash-card__badge">this month</span></h3></div>
                <p v-if="! data.payment_methods.length" class="small text-muted mb-0">No receipts yet this month.</p>
                <div v-else>
                    <div class="dash-extras__stack" role="img" :aria-label="`Receipts split into ${data.payment_methods.length} methods`">
                        <i v-for="(row, index) in data.payment_methods" :key="row.method" :style="{ width: `${(row.amount / methodTotal) * 100}%`, background: COLORS[index % COLORS.length] }" :title="`${METHOD_LABEL[row.method] ?? row.method}: ${money(row.amount)}`" />
                    </div>
                    <ul class="list-unstyled small mt-2 mb-0">
                        <li v-for="(row, index) in data.payment_methods" :key="row.method" class="d-flex justify-content-between">
                            <span><i class="dash-extras__key" :style="{ background: COLORS[index % COLORS.length] }" /> {{ METHOD_LABEL[row.method] ?? row.method }}</span>
                            <span>{{ money(row.amount) }} <span class="text-muted">({{ Math.round((row.amount / methodTotal) * 100) }}%)</span></span>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="data.branches" class="dash-card" data-card="branches">
                <div class="dash-card__header"><h3 class="dash-card__title">Branches <span class="dash-card__badge">sales this month</span></h3></div>
                <p v-if="! data.branches.length" class="small text-muted mb-0">No branch sales yet this month.</p>
                <div v-for="row in data.branches" :key="row.name" class="dash-extras__row">
                    <div class="d-flex justify-content-between small"><span>{{ row.name }}</span><strong>{{ money(row.sales) }}</strong></div>
                    <div class="dash-extras__bar"><i :style="{ width: `${(row.sales / branchMax) * 100}%`, background: '#6366f1' }" /></div>
                </div>
            </div>

            <div v-if="data.hrm" class="dash-card" data-card="hrm">
                <div class="dash-card__header"><h3 class="dash-card__title">People today</h3></div>
                <div class="dash-extras__tiles">
                    <div><strong>{{ data.hrm.headcount }}</strong><span>Headcount</span></div>
                    <div><strong class="text-success">{{ data.hrm.present }}</strong><span>Present</span></div>
                    <div><strong class="text-danger">{{ data.hrm.absent }}</strong><span>Absent</span></div>
                    <div><strong class="text-warning">{{ data.hrm.on_leave }}</strong><span>On leave</span></div>
                </div>
                <p class="small mb-0 mt-2"><a href="/hrm/leave">{{ data.hrm.pending_leave }} leave request{{ data.hrm.pending_leave === 1 ? '' : 's' }} waiting</a></p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dash-extras {
    margin-bottom: 1rem;
}
.dash-extras__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr));
    gap: 1rem;
}
.dash-extras__skeleton {
    min-height: 9rem;
    animation: dash-extras-pulse 1.4s ease-in-out infinite;
}
@keyframes dash-extras-pulse {
    50% {
        opacity: 0.5;
    }
}
.dash-extras__row {
    margin-bottom: 0.55rem;
}
.dash-extras__bar {
    height: 8px;
    background: #f1f5f9;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 2px;
}
.dash-extras__bar--thin {
    height: 5px;
}
.dash-extras__bar i {
    display: block;
    height: 100%;
    border-radius: 4px;
}
.dash-extras__stack {
    display: flex;
    height: 14px;
    border-radius: 7px;
    overflow: hidden;
    background: #f1f5f9;
}
.dash-extras__stack i {
    display: block;
    height: 100%;
}
.dash-extras__key {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.dash-extras__tiles {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
    text-align: center;
}
.dash-extras__tiles strong {
    display: block;
    font-size: 1.35rem;
}
.dash-extras__tiles span {
    font-size: 0.75rem;
    color: #64748b;
}
</style>
