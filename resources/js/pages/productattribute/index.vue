<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Custom Attributes',
            subtitle: 'Extra fields every product of the company carries',
            breadcrumbs: [{ title: 'Custom Attributes', href: 'NULL' }],
        },
    });

    type Definition = { id: number; name: string; field_key: string; type: string; options: string[] | null; is_required: boolean; is_active: boolean; sort_order: number };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Definition[]>([]);
    const editing = ref<number | null>(null);
    const blank = () => ({ name: '', type: 'text', options: '', is_required: false, is_active: true, sort_order: 0 });
    const form = ref(blank());

    async function load() {
        const response = await run(() => window.axios.get('/api/product-attributes', { params: scope() }));
        rows.value = response?.data?.data ?? [];
    }

    function edit(row: Definition) {
        editing.value = row.id;
        form.value = { name: row.name, type: row.type, options: (row.options ?? []).join(', '), is_required: row.is_required, is_active: row.is_active, sort_order: row.sort_order };
    }

    async function save() {
        const body = {
            ...scope(),
            ...form.value,
            options: form.value.type === 'select' ? form.value.options.split(',').map((option) => option.trim()).filter(Boolean) : [],
        };
        const done = await run(
            () => (editing.value === null ? window.axios.post('/api/product-attributes', body) : window.axios.put(`/api/product-attributes/${editing.value}`, body)),
            'Saved',
        );

        if (done) {
            editing.value = null;
            form.value = blank();
            await load();
        }
    }

    async function remove(row: Definition) {
        if (window.confirm(`Delete ${row.name}?`) && await run(() => window.axios.delete(`/api/product-attributes/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Custom Attributes" />

    <div class="container-fluid py-3">
        <div v-if="isSuperadmin" class="mb-3" style="max-width: 14rem">
            <label class="form-label small" for="attr-company">Company id</label>
            <input id="attr-company" v-model.number="companyId" type="number" class="form-control form-control-sm" @change="load" />
        </div>

        <div v-if="can('/productattribute/add') || editing !== null" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label small" for="attr-name">Name</label><input id="attr-name" v-model="form.name" class="form-control form-control-sm" maxlength="100" /></div>
                <div class="col-md-2">
                    <label class="form-label small" for="attr-type">Type</label>
                    <select id="attr-type" v-model="form.type" class="form-select form-select-sm">
                        <option value="text">Text</option><option value="number">Number</option><option value="date">Date</option><option value="select">Choice</option><option value="checkbox">Yes / No</option>
                    </select>
                </div>
                <div v-if="form.type === 'select'" class="col-md-4"><label class="form-label small" for="attr-options">Choices (comma separated)</label><input id="attr-options" v-model="form.options" class="form-control form-control-sm" /></div>
                <div class="col-md-1"><label class="form-label small" for="attr-order">Order</label><input id="attr-order" v-model.number="form.sort_order" type="number" min="0" class="form-control form-control-sm" /></div>
                <div class="col-md-2 d-flex align-items-end gap-3">
                    <label class="small"><input v-model="form.is_required" type="checkbox" class="me-1" />Required</label>
                    <label class="small"><input v-model="form.is_active" type="checkbox" class="me-1" />Active</label>
                </div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3" @click="save">{{ editing === null ? 'Add attribute' : 'Save changes' }}</button>
            <button v-if="editing !== null" type="button" class="btn btn-link btn-sm mt-3" @click="editing = null; form = blank()">Cancel</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Name</th><th>Key</th><th>Type</th><th>Choices</th><th>Required</th><th>Active</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.name }}</td><td><code>{{ row.field_key }}</code></td><td class="text-capitalize">{{ row.type }}</td>
                        <td>{{ (row.options ?? []).join(', ') }}</td><td>{{ row.is_required ? 'Yes' : '' }}</td><td>{{ row.is_active ? 'Yes' : 'No' }}</td>
                        <td class="text-end text-nowrap">
                            <button v-if="can('/productattribute/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(row)">Edit</button>
                            <button v-if="can('/productattribute/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="7" class="text-center text-muted">No custom attributes yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
