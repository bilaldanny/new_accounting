<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Inventory Valuation',
            subtitle: 'Costing method, standard costs and cost recalculation',
            breadcrumbs: [{ title: 'Inventory Valuation', href: 'NULL' }],
        },
    });

    type Change = { id: number; method: string; effective_from: string; note: string | null };
    type Line = { sell_line_id: number; invoice_no: string | null; date: string; product: string; base_quantity: number; current_unit_cost: number | null; new_unit_cost: number | null; current_cost: number | null; new_cost: number | null; difference: number | null };
    type Preview = { method: string; blocked: boolean; locked_through: string | null; lines: Line[]; summary: { lines: number; changed: number; uncosted: number; cost_before: number; cost_after: number; difference: number } };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const current = ref<{ method: string; effective_from: string | null }>({ method: 'average', effective_from: null });
    const history = ref<Change[]>([]);
    const lifoNote = ref('');
    const standardCount = ref(0);
    const method = ref('fifo');
    const effectiveFrom = ref('');
    const note = ref('');
    const standardVariation = ref<number | ''>('');
    const standardCost = ref<number | ''>('');
    const standardFrom = ref('');
    const from = ref('');
    const to = ref('');
    const preview = ref<Preview | null>(null);
    const reconciliation = ref<Record<string, number | string | boolean> | null>(null);

    async function load() {
        const response = await run(() => window.axios.get('/api/valuation', { params: scope() }));
        current.value = response?.data?.current ?? current.value;
        history.value = response?.data?.history ?? [];
        lifoNote.value = response?.data?.lifo ?? '';
        standardCount.value = response?.data?.standard_costs ?? 0;
    }

    async function saveMethod() {
        if (await run(() => window.axios.post('/api/valuation/method', { ...scope(), method: method.value, effective_from: effectiveFrom.value, note: note.value }), 'Method saved')) {
            await load();
        }
    }

    async function saveStandard() {
        const items = [{ variation_id: Number(standardVariation.value), cost: Number(standardCost.value) }];

        if (await run(() => window.axios.post('/api/valuation/standard-costs', { ...scope(), effective_from: standardFrom.value, items }), 'Standard cost saved')) {
            standardCost.value = '';
            await load();
        }
    }

    async function showPreview() {
        const response = await run(() => window.axios.get('/api/valuation/recalculation', { params: { ...scope(), from: from.value, to: to.value } }));
        preview.value = response?.data ?? null;
    }

    async function recalculate() {
        if (! window.confirm('Lock the recalculated cost on every sale line in this range? Nothing is posted to the ledger.')) {
            return;
        }

        if (await run(() => window.axios.post('/api/valuation/recalculate', { ...scope(), from: from.value, to: to.value }), 'Costs recalculated')) {
            await showPreview();
            await load();
        }
    }

    async function reconcile() {
        const response = await run(() => window.axios.get('/api/valuation/reconciliation', { params: { ...scope(), as_of: to.value || undefined } }));
        reconciliation.value = response?.data ?? null;
    }

    onMounted(load);
</script>

<template>
    <Head title="Inventory Valuation" />

    <div class="container-fluid py-3">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div class="border rounded p-3 mb-4">
            <div class="text-muted small">Costing method in force</div>
            <div class="fs-4 fw-semibold text-capitalize" data-test="method">{{ current.method }}</div>
            <div class="small text-muted">{{ current.effective_from ? `from ${current.effective_from}` : 'weighted average, the default' }}. {{ lifoNote }}</div>
        </div>

        <div class="row g-3 mb-4">
            <div v-if="can('/valuation/method')" class="col-md-6">
                <div class="border rounded p-3">
                    <h6>Change method</h6>
                    <p class="small text-muted">It applies from the date forward. Earlier days keep their method and nothing is backfilled; stock on hand that day becomes the opening layer.</p>
                    <select v-model="method" class="form-select form-select-sm mb-2" aria-label="Method">
                        <option value="average">Weighted average</option>
                        <option value="fifo">FIFO</option>
                        <option value="standard">Standard cost</option>
                    </select>
                    <input v-model="effectiveFrom" type="date" class="form-control form-control-sm mb-2" aria-label="Effective from" />
                    <input v-model="note" class="form-control form-control-sm mb-2" placeholder="Note" aria-label="Note" />
                    <button type="button" class="btn btn-dark btn-sm" @click="saveMethod">Save method</button>
                </div>
            </div>
            <div v-if="can('/valuation/standard')" class="col-md-6">
                <div class="border rounded p-3">
                    <h6>Standard cost <span class="text-muted small">({{ standardCount }} set)</span></h6>
                    <input v-model.number="standardVariation" type="number" class="form-control form-control-sm mb-2" placeholder="Variation id" aria-label="Variation id" />
                    <input v-model.number="standardCost" type="number" step="0.0001" class="form-control form-control-sm mb-2" placeholder="Cost per base unit" aria-label="Cost per base unit" />
                    <input v-model="standardFrom" type="date" class="form-control form-control-sm mb-2" aria-label="Standard cost effective from" />
                    <button type="button" class="btn btn-outline-dark btn-sm" @click="saveStandard">Save standard cost</button>
                </div>
            </div>
        </div>

        <div class="border rounded p-3 mb-4">
            <h6>Cost recalculation</h6>
            <div class="row g-2 align-items-end">
                <div class="col-auto"><label class="form-label small" for="rc-from">From</label><input id="rc-from" v-model="from" type="date" class="form-control form-control-sm" /></div>
                <div class="col-auto"><label class="form-label small" for="rc-to">To</label><input id="rc-to" v-model="to" type="date" class="form-control form-control-sm" /></div>
                <div class="col-auto"><button type="button" class="btn btn-outline-dark btn-sm" @click="showPreview">Preview</button></div>
                <div v-if="can('/valuation/recalculate')" class="col-auto"><button type="button" class="btn btn-dark btn-sm" :disabled="! preview || preview.blocked" @click="recalculate">Recalculate and lock costs</button></div>
                <div class="col-auto"><button type="button" class="btn btn-outline-secondary btn-sm" @click="reconcile">Check reports agree</button></div>
            </div>
            <div v-if="preview?.blocked" class="alert alert-warning mt-3 mb-0 py-2 small">The books are locked through {{ preview.locked_through }}; a range that starts on or before that day cannot be recalculated.</div>
            <div v-if="reconciliation" class="mt-3 small" data-test="reconciliation">
                Stock valuation {{ money(reconciliation.stock_valuation as number) }}, stock report {{ money(reconciliation.stock_report as number) }}, profit and loss closing stock {{ money(reconciliation.profit_loss_closing_stock as number) }}:
                <strong>{{ reconciliation.matches ? 'they agree' : 'MISMATCH' }}</strong>
            </div>
            <div v-if="preview" class="mt-3">
                <div class="small mb-2">{{ preview.summary.lines }} lines, {{ preview.summary.changed }} change, {{ preview.summary.uncosted }} without a cost. Before {{ money(preview.summary.cost_before) }}, after {{ money(preview.summary.cost_after) }}, difference {{ money(preview.summary.difference) }}.</div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Date</th><th>Invoice</th><th>Product</th><th class="text-end">Qty</th><th class="text-end">Before</th><th class="text-end">After</th><th class="text-end">Difference</th></tr></thead>
                        <tbody>
                            <tr v-for="line in preview.lines" :key="line.sell_line_id">
                                <td>{{ line.date }}</td><td>{{ line.invoice_no }}</td><td>{{ line.product }}</td><td class="text-end">{{ line.base_quantity }}</td>
                                <td class="text-end">{{ line.current_cost === null ? '-' : money(line.current_cost) }}</td><td class="text-end">{{ line.new_cost === null ? '-' : money(line.new_cost) }}</td>
                                <td class="text-end">{{ line.difference === null ? '-' : money(line.difference) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <h6>Method history</h6>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>From</th><th>Method</th><th>Note</th></tr></thead>
                <tbody>
                    <tr v-for="change in history" :key="change.id"><td>{{ change.effective_from }}</td><td class="text-capitalize">{{ change.method }}</td><td>{{ change.note }}</td></tr>
                    <tr v-if="! history.length"><td colspan="3" class="text-center text-muted">Never changed: weighted average.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
