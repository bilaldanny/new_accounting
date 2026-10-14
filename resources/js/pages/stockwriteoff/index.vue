<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Damage, Expired & Wastage',
            subtitle: 'Write stock off with a reason; it posts as a stock adjustment',
            breadcrumbs: [{ title: 'Damage, Expired & Wastage', href: 'NULL' }],
        },
    });

    type Row = { id: number; adjustment_id: number; reason: string; total_quantity: number; total_value: number | null; note: string | null; created_at: string };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const rows = ref<Row[]>([]);
    const byReason = ref<Record<string, { entries: number; quantity: number; value: number }>>({});
    const reasonFilter = ref('all');
    const labels: Record<string, string> = { damage: 'Damage', expired: 'Expired', near_expiry: 'Near-expiry disposal', wastage: 'Wastage' };
    const form = ref({ reason: 'damage', branch_id: '' as number | '', date: new Date().toISOString().slice(0, 10), note: '' });
    const lines = ref([{ product_id: '' as number | '', variation_id: '' as number | '', quantity: 1 }]);

    async function load() {
        const response = await run(() => window.axios.get('/api/stock-writeoffs', { params: { ...scope(), reason: reasonFilter.value } }));
        rows.value = response?.data?.data?.data ?? [];
        byReason.value = response?.data?.by_reason ?? {};
    }

    async function save() {
        const body = {
            ...scope(), ...form.value, branch_id: form.value.branch_id === '' ? null : form.value.branch_id,
            rows: lines.value.filter((line) => line.product_id !== '').map((line) => ({ product_id: line.product_id, variation_id: line.variation_id === '' ? null : line.variation_id, quantity: line.quantity })),
        };

        if (await run(() => window.axios.post('/api/stock-writeoffs', body), 'Stock written off')) {
            lines.value = [{ product_id: '', variation_id: '', quantity: 1 }];
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Damage, Expired & Wastage" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-3 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <div v-for="(label, key) in labels" :key="key" class="border rounded px-3 py-2">
                <div class="small text-muted">{{ label }}</div>
                <div class="fw-semibold">{{ byReason[key]?.quantity ?? 0 }} units, {{ money(byReason[key]?.value ?? 0) }}</div>
            </div>
        </div>

        <div v-if="can('/stockwriteoff/add')" class="border rounded p-3 mb-4">
            <div class="row g-2 mb-2">
                <div class="col-md-3">
                    <label class="form-label small" for="wo-reason">Reason</label>
                    <select id="wo-reason" v-model="form.reason" class="form-select form-select-sm"><option v-for="(label, key) in labels" :key="key" :value="key">{{ label }}</option></select>
                </div>
                <div class="col-md-2"><label class="form-label small" for="wo-branch">Branch id</label><input id="wo-branch" v-model.number="form.branch_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="wo-date">Date</label><input id="wo-date" v-model="form.date" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-5"><label class="form-label small" for="wo-note">Note</label><input id="wo-note" v-model="form.note" class="form-control form-control-sm" /></div>
            </div>
            <table class="table table-sm align-middle">
                <thead><tr><th>Product id</th><th>Variation id (optional)</th><th style="width: 10rem">Quantity</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="(line, index) in lines" :key="index">
                        <td><input v-model.number="line.product_id" type="number" class="form-control form-control-sm" aria-label="Product id" /></td>
                        <td><input v-model.number="line.variation_id" type="number" class="form-control form-control-sm" aria-label="Variation id" /></td>
                        <td><input v-model.number="line.quantity" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Quantity" /></td>
                        <td class="text-end"><button type="button" class="btn btn-link btn-sm text-danger" @click="lines.splice(index, 1)">Remove</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="lines.push({ product_id: '', variation_id: '', quantity: 1 })">Add line</button>
            <button type="button" class="btn btn-dark btn-sm" @click="save">Write off</button>
        </div>

        <select v-model="reasonFilter" class="form-select form-select-sm mb-2" style="width: auto" aria-label="Reason" @change="load">
            <option value="all">All reasons</option><option v-for="(label, key) in labels" :key="key" :value="key">{{ label }}</option>
        </select>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Date</th><th>Reason</th><th class="text-end">Quantity</th><th class="text-end">Value</th><th>Adjustment</th><th>Note</th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.created_at.slice(0, 10) }}</td><td>{{ labels[row.reason] }}</td><td class="text-end">{{ row.total_quantity }}</td>
                        <td class="text-end">{{ row.total_value === null ? '-' : money(row.total_value) }}</td><td>#{{ row.adjustment_id }}</td><td>{{ row.note }}</td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="6" class="text-center text-muted">Nothing written off.</td></tr>
                </tbody>
            </table>
        </div>
    </div></div></div>
</template>
