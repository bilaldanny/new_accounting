<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Dashboard Insights',
            subtitle: 'Top customers and products, expenses and cash movement',
            breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Insights', href: 'NULL' }],
        },
    });

    type Insights = {
        period: { from: string; to: string };
        top_customers: { name: string; amount: number; documents: number }[];
        top_products: { name: string; amount: number; quantity: number }[];
        expenses: { code: string; name: string; amount: number }[];
        cash_flow: { in: number; out: number; net: number };
    };

    const { companyId, isSuperadmin, scope, money, run } = useCloseout();
    const data = ref<Insights | null>(null);
    const from = ref('');
    const to = ref('');
    const layout = ref<{ order: string[]; hidden: string[] }>({ order: [], hidden: [] });
    const widgets = ref<string[]>([]);

    async function load() {
        const [insights, prefs] = await Promise.all([
            run(() => window.axios.get('/api/dashboard-insights', { params: { ...scope(), from: from.value || undefined, to: to.value || undefined } })),
            run(() => window.axios.get('/api/dashboard-preferences')),
        ]);
        data.value = insights?.data?.data ?? null;
        layout.value = prefs?.data?.data ?? layout.value;
        widgets.value = prefs?.data?.widgets ?? [];
    }

    function exportCsv() {
        const params = new URLSearchParams({ export: 'csv', ...(from.value ? { from: from.value } : {}), ...(to.value ? { to: to.value } : {}), ...(isSuperadmin.value && companyId.value !== '' ? { company_id: String(companyId.value) } : {}) });
        window.location.href = `/api/dashboard-insights?${params.toString()}`;
    }

    function toggle(widget: string) {
        layout.value.hidden = layout.value.hidden.includes(widget) ? layout.value.hidden.filter((item) => item !== widget) : [...layout.value.hidden, widget];
    }

    function move(widget: string, by: -1 | 1) {
        const order = [...layout.value.order];
        const index = order.indexOf(widget);
        const target = index + by;

        if (index < 0 || target < 0 || target >= order.length) {
            return;
        }

        [order[index], order[target]] = [order[target], order[index]];
        layout.value.order = order;
    }

    async function saveLayout() {
        await run(() => window.axios.put('/api/dashboard-preferences', layout.value), 'Layout saved');
    }

    onMounted(load);
</script>

<template>
    <Head title="Dashboard Insights" />

    <div class="container-fluid py-3">
        <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm mb-3" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
        <div class="row g-2 align-items-end mb-3">
            <div class="col-auto"><label class="form-label small" for="di-from">From</label><input id="di-from" v-model="from" type="date" class="form-control form-control-sm" @change="load" /></div>
            <div class="col-auto"><label class="form-label small" for="di-to">To</label><input id="di-to" v-model="to" type="date" class="form-control form-control-sm" @change="load" /></div>
            <div class="col-auto"><button type="button" class="btn btn-outline-dark btn-sm" @click="exportCsv">Export CSV</button></div>
        </div>

        <div v-if="data" class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Top customers</h6>
                    <table class="table table-sm mb-0"><tbody>
                        <tr v-for="row in data.top_customers" :key="row.name"><td>{{ row.name }}</td><td class="text-muted">{{ row.documents }} sales</td><td class="text-end">{{ money(row.amount) }}</td></tr>
                        <tr v-if="! data.top_customers.length"><td class="text-muted">No sales in this period.</td></tr>
                    </tbody></table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Top products</h6>
                    <table class="table table-sm mb-0"><tbody>
                        <tr v-for="row in data.top_products" :key="row.name"><td>{{ row.name }}</td><td class="text-muted">{{ row.quantity }} sold</td><td class="text-end">{{ money(row.amount) }}</td></tr>
                        <tr v-if="! data.top_products.length"><td class="text-muted">No sales in this period.</td></tr>
                    </tbody></table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Expenses</h6>
                    <table class="table table-sm mb-0"><tbody>
                        <tr v-for="row in data.expenses" :key="row.code"><td>{{ row.code }}</td><td>{{ row.name }}</td><td class="text-end">{{ money(row.amount) }}</td></tr>
                        <tr v-if="! data.expenses.length"><td class="text-muted">No expenses in this period.</td></tr>
                    </tbody></table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100"><h6>Cash and bank</h6>
                    <div class="d-flex justify-content-between"><span>In</span><strong>{{ money(data.cash_flow.in) }}</strong></div>
                    <div class="d-flex justify-content-between"><span>Out</span><strong>{{ money(data.cash_flow.out) }}</strong></div>
                    <div class="d-flex justify-content-between"><span>Net</span><strong>{{ money(data.cash_flow.net) }}</strong></div>
                </div>
            </div>
        </div>

        <div class="border rounded p-3">
            <h6>My dashboard layout</h6>
            <p class="small text-muted">Choose the cards you see and their order; it is saved for your sign-in only.</p>
            <div v-for="widget in layout.order" :key="widget" class="d-flex align-items-center gap-2 mb-1">
                <input type="checkbox" :checked="! layout.hidden.includes(widget)" :aria-label="`Show ${widget}`" @change="toggle(widget)" />
                <span class="text-capitalize flex-grow-1">{{ widget.replace('_', ' ') }}</span>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="move(widget, -1)">up</button>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="move(widget, 1)">down</button>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-2" @click="saveLayout">Save layout</button>
        </div>
    </div>
</template>
