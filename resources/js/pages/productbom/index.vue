<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Kits & BOM',
            subtitle: 'Components of a kit or assembly, with kitting and de-kitting',
            breadcrumbs: [{ title: 'Kits & BOM', href: 'NULL' }],
        },
    });

    type Listed = { id: number; name: string; sku: string | null; behaviour: string | null; component_count: number };
    type Component = { component_product_id: number | ''; component_variation_id: number | null; quantity: number; name?: string | null; sku?: string | null };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const products = ref<Listed[]>([]);
    const search = ref('');
    const showAll = ref(false);
    const selected = ref<{ id: number; name: string } | null>(null);
    const components = ref<Component[]>([]);
    const assemble = ref({ mode: 'kit', quantity: 1, branch_id: '' as number | '', date: new Date().toISOString().slice(0, 10), note: '' });

    async function load() {
        products.value = (await run(() => window.axios.get('/api/product-components', { params: { ...scope(), search: search.value, all: showAll.value ? 1 : 0 } })))?.data?.data ?? [];
    }

    async function open(row: Listed) {
        const response = await run(() => window.axios.get(`/api/products/${row.id}/components`));

        if (response) {
            selected.value = { id: row.id, name: row.name };
            components.value = response.data.components;
        }
    }

    async function save() {
        if (selected.value === null) {
            return;
        }

        const body = { components: components.value.filter((row) => row.component_product_id !== '').map((row) => ({ component_product_id: row.component_product_id, component_variation_id: row.component_variation_id, quantity: row.quantity })) };

        if (await run(() => window.axios.put(`/api/products/${selected.value!.id}/components`, body), 'Components saved')) {
            await load();
        }
    }

    async function run_assembly() {
        if (selected.value === null) {
            return;
        }

        const body = { ...assemble.value, branch_id: assemble.value.branch_id === '' ? null : assemble.value.branch_id };

        await run(() => window.axios.post(`/api/products/${selected.value!.id}/assemble`, body), assemble.value.mode === 'kit' ? 'Kit assembled' : 'Kit taken apart');
    }

    onMounted(load);
</script>

<template>
    <Head title="Kits & BOM" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <input v-model="search" class="form-control form-control-sm" style="width: 16rem" placeholder="Search products" aria-label="Search" @keyup.enter="load" />
            <label class="small align-self-center"><input v-model="showAll" type="checkbox" class="me-1" @change="load" />Include products with no components</label>
            <button type="button" class="btn btn-dark btn-sm" @click="load">Search</button>
        </div>

        <div v-if="selected" class="border rounded p-3 mb-4">
            <div class="d-flex justify-content-between"><h6>{{ selected.name }}</h6><button type="button" class="btn btn-link btn-sm" @click="selected = null">Close</button></div>
            <ClientTable  :columns="[{ key: 'c0', label: 'Component product id' }, { key: 'c1', label: 'Name' }, { key: 'c2', label: 'Quantity per unit' }, { key: 'c3', label: '' }]" :rows="(components).map((row, index) => ({ __s: { row, index }, c0: '', c1: (row.name), c2: '', c3: '' }))" export-name="component-product-id">
<template #cell-c0="{ row: __m }"><template v-for="{ row, index } in [__m.__s]" :key="0"><input v-model.number="row.component_product_id" type="number" class="form-control form-control-sm" aria-label="Component product id" /></template></template>
<template #cell-c2="{ row: __m }"><template v-for="{ row, index } in [__m.__s]" :key="0"><input v-model.number="row.quantity" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Quantity" /></template></template>
<template #cell-c3="{ row: __m }"><template v-for="{ row, index } in [__m.__s]" :key="0"><button type="button" class="btn btn-link btn-sm text-danger" @click="components.splice(index, 1)">Remove</button></template></template>
</ClientTable>
            <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="components.push({ component_product_id: '', component_variation_id: null, quantity: 1 })">Add component</button>
            <button v-if="can('/productbom/:id/edit')" type="button" class="btn btn-dark btn-sm" @click="save">Save components</button>

            <div v-if="can('/productbom/kitting')" class="border-top mt-3 pt-3">
                <h6>Kitting / de-kitting</h6>
                <div class="row g-2">
                    <div class="col-md-2">
                        <label class="form-label small" for="asm-mode">Action</label>
                        <select id="asm-mode" v-model="assemble.mode" class="form-select form-select-sm"><option value="kit">Assemble (kit)</option><option value="dekit">Take apart (de-kit)</option></select>
                    </div>
                    <div class="col-md-2"><label class="form-label small" for="asm-qty">Quantity</label><input id="asm-qty" v-model.number="assemble.quantity" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                    <div class="col-md-2"><label class="form-label small" for="asm-branch">Branch id</label><input id="asm-branch" v-model.number="assemble.branch_id" type="number" class="form-control form-control-sm" /></div>
                    <div class="col-md-2"><label class="form-label small" for="asm-date">Date</label><input id="asm-date" v-model="assemble.date" type="date" class="form-control form-control-sm" /></div>
                    <div class="col-md-4"><label class="form-label small" for="asm-note">Note</label><input id="asm-note" v-model="assemble.note" class="form-control form-control-sm" /></div>
                </div>
                <button type="button" class="btn btn-dark btn-sm mt-2" @click="run_assembly">Run</button>
            </div>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No products with components yet. Tick the box above to pick any product."  :columns="[{ key: 'c0', label: 'Product' }, { key: 'c1', label: 'SKU' }, { key: 'c2', label: 'Item type' }, { key: 'c3', label: 'Components' }, { key: 'c4', label: '' }]" :rows="(products).map((row) => ({ __s: { row }, c0: (row.name), c1: (row.sku), c2: ((row.behaviour ?? 'inventory').replace('_', ' ')), c3: (row.component_count), c4: '' }))" export-name="product">
<template #cell-c4="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><button type="button" class="btn btn-outline-dark btn-sm" @click="open(row)">Open</button></template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
