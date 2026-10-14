<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Product Discount Rules',
            subtitle: 'Automatic discounts by product, category or brand',
            breadcrumbs: [{ title: 'Product Discount Rules', href: 'NULL' }],
        },
    });

    type Rule = {
        id: number; name: string; scope: string; discount_type: string; value: number; min_quantity: number | null;
        starts_on: string | null; ends_on: string | null; priority: number; is_active: boolean; target_name?: string | null;
        product_id: number | null; category_id: number | null; brand_id: number | null;
    };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Rule[]>([]);
    const editing = ref<number | null>(null);
    const blank = () => ({
        name: '', scope: 'product', product_id: '' as number | '', category_id: '' as number | '', brand_id: '' as number | '',
        discount_type: 'percent', value: 0, min_quantity: '' as number | '', starts_on: '', ends_on: '', priority: 0, is_active: true,
    });
    const form = ref(blank());

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/product-discount-rules', { params: scope() })))?.data?.data ?? [];
    }

    function edit(row: Rule) {
        editing.value = row.id;
        form.value = {
            name: row.name, scope: row.scope, product_id: row.product_id ?? '', category_id: row.category_id ?? '', brand_id: row.brand_id ?? '',
            discount_type: row.discount_type, value: Number(row.value), min_quantity: row.min_quantity ?? '', starts_on: row.starts_on ?? '',
            ends_on: row.ends_on ?? '', priority: row.priority, is_active: row.is_active,
        };
    }

    async function save() {
        const clean = (value: number | string) => (value === '' ? null : value);
        const body = {
            ...scope(), ...form.value,
            product_id: form.value.scope === 'product' ? clean(form.value.product_id) : null,
            category_id: form.value.scope === 'category' ? clean(form.value.category_id) : null,
            brand_id: form.value.scope === 'brand' ? clean(form.value.brand_id) : null,
            min_quantity: clean(form.value.min_quantity), starts_on: clean(form.value.starts_on), ends_on: clean(form.value.ends_on),
        };
        const done = await run(
            () => (editing.value === null ? window.axios.post('/api/product-discount-rules', body) : window.axios.put(`/api/product-discount-rules/${editing.value}`, body)),
            'Saved',
        );

        if (done) {
            editing.value = null;
            form.value = blank();
            await load();
        }
    }

    async function remove(row: Rule) {
        if (window.confirm(`Delete ${row.name}?`) && await run(() => window.axios.delete(`/api/product-discount-rules/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Product Discount Rules" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div v-if="isSuperadmin" class="mb-3" style="max-width: 14rem">
            <label class="form-label small" for="rule-company">Company id</label>
            <input id="rule-company" v-model.number="companyId" type="number" class="form-control form-control-sm" @change="load" />
        </div>

        <div v-if="can('/productdiscountrule/add') || editing !== null" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label small" for="rule-name">Name</label><input id="rule-name" v-model="form.name" class="form-control form-control-sm" /></div>
                <div class="col-md-2">
                    <label class="form-label small" for="rule-scope">Applies to</label>
                    <select id="rule-scope" v-model="form.scope" class="form-select form-select-sm">
                        <option value="product">One product</option><option value="category">A category</option><option value="brand">A brand</option><option value="all">Every product</option>
                    </select>
                </div>
                <div v-if="form.scope === 'product'" class="col-md-2"><label class="form-label small" for="rule-product">Product id</label><input id="rule-product" v-model.number="form.product_id" type="number" class="form-control form-control-sm" /></div>
                <div v-if="form.scope === 'category'" class="col-md-2"><label class="form-label small" for="rule-category">Category id</label><input id="rule-category" v-model.number="form.category_id" type="number" class="form-control form-control-sm" /></div>
                <div v-if="form.scope === 'brand'" class="col-md-2"><label class="form-label small" for="rule-brand">Brand id</label><input id="rule-brand" v-model.number="form.brand_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2">
                    <label class="form-label small" for="rule-type">Discount</label>
                    <div class="input-group input-group-sm">
                        <select id="rule-type" v-model="form.discount_type" class="form-select"><option value="percent">%</option><option value="fixed">Amount</option></select>
                        <input v-model.number="form.value" type="number" min="0" step="any" class="form-control" aria-label="Discount value" />
                    </div>
                </div>
                <div class="col-md-1"><label class="form-label small" for="rule-min">Min qty</label><input id="rule-min" v-model.number="form.min_quantity" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="rule-from">From</label><input id="rule-from" v-model="form.starts_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="rule-to">To</label><input id="rule-to" v-model="form.ends_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-1"><label class="form-label small" for="rule-priority">Priority</label><input id="rule-priority" v-model.number="form.priority" type="number" min="0" class="form-control form-control-sm" /></div>
                <div class="col-md-2 d-flex align-items-end"><label class="small"><input v-model="form.is_active" type="checkbox" class="me-1" />Active</label></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3" @click="save">{{ editing === null ? 'Add rule' : 'Save changes' }}</button>
            <button v-if="editing !== null" type="button" class="btn btn-link btn-sm mt-3" @click="editing = null; form = blank()">Cancel</button>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No rules yet."  :columns="[{ key: 'c0', label: 'Name' }, { key: 'c1', label: 'Applies to' }, { key: 'c2', label: 'Discount' }, { key: 'c3', label: 'Min qty' }, { key: 'c4', label: 'Dates' }, { key: 'c5', label: 'Priority' }, { key: 'c6', label: 'Active' }, { key: 'c7', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.name), c1: '', c2: (row.discount_type === 'percent' ? `${row.value}%` : row.value), c3: (row.min_quantity ?? ''), c4: ([row.starts_on, row.ends_on].filter(Boolean).join(' to ')), c5: (row.priority), c6: (row.is_active ? 'Yes' : 'No'), c7: '' }))" export-name="name">
<template #cell-c1="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ row.scope }} <span class="text-muted small">{{ row.target_name }}</span></template></template>
<template #cell-c7="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                            <button v-if="can('/productdiscountrule/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(row)">Edit</button>
                            <button v-if="can('/productdiscountrule/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                        </template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
