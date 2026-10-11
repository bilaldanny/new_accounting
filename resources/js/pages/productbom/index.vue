<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
            <input v-model="search" class="form-control form-control-sm" style="width: 16rem" placeholder="Search products" aria-label="Search" @keyup.enter="load" />
            <label class="small align-self-center"><input v-model="showAll" type="checkbox" class="me-1" @change="load" />Include products with no components</label>
            <button type="button" class="btn btn-dark btn-sm" @click="load">Search</button>
        </div>

        <div v-if="selected" class="border rounded p-3 mb-4">
            <div class="d-flex justify-content-between"><h6>{{ selected.name }}</h6><button type="button" class="btn btn-link btn-sm" @click="selected = null">Close</button></div>
            <table class="table table-sm align-middle">
                <thead><tr><th>Component product id</th><th>Name</th><th style="width: 10rem">Quantity per unit</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="(row, index) in components" :key="index">
                        <td><input v-model.number="row.component_product_id" type="number" class="form-control form-control-sm" aria-label="Component product id" /></td>
                        <td>{{ row.name }}</td>
                        <td><input v-model.number="row.quantity" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Quantity" /></td>
                        <td class="text-end"><button type="button" class="btn btn-link btn-sm text-danger" @click="components.splice(index, 1)">Remove</button></td>
                    </tr>
                </tbody>
            </table>
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
            <table class="table table-sm align-middle">
                <thead><tr><th>Product</th><th>SKU</th><th>Item type</th><th class="text-end">Components</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in products" :key="row.id">
                        <td>{{ row.name }}</td><td>{{ row.sku }}</td><td class="text-capitalize">{{ (row.behaviour ?? 'inventory').replace('_', ' ') }}</td><td class="text-end">{{ row.component_count }}</td>
                        <td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="open(row)">Open</button></td>
                    </tr>
                    <tr v-if="! products.length"><td colspan="5" class="text-center text-muted">No products with components yet. Tick the box above to pick any product.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
