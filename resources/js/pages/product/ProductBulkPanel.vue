<script setup lang="ts">
    import { ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    /**
     * Bulk update for the products ticked in the list: one field at a time (the server limits the fields and the company),
     * plus the complete export of every product field.
     */
    const props = defineProps<{ ids: number[] }>();
    const emit = defineEmits<{ done: [] }>();

    const { can, run } = useCloseout();

    const fields = [
        { key: 'category_id', label: 'Category id', kind: 'number' },
        { key: 'subcategory_id', label: 'Subcategory id', kind: 'number' },
        { key: 'brand_id', label: 'Brand id', kind: 'number' },
        { key: 'itemtype_id', label: 'Item type id', kind: 'number' },
        { key: 'warranty_id', label: 'Warranty id', kind: 'number' },
        { key: 'active', label: 'Active', kind: 'boolean' },
        { key: 'alert_qty', label: 'Alert quantity', kind: 'number' },
        { key: 'reorder_level', label: 'Reorder level', kind: 'number' },
        { key: 'reorder_qty', label: 'Reorder quantity', kind: 'number' },
        { key: 'min_stock', label: 'Minimum stock', kind: 'number' },
        { key: 'max_stock', label: 'Maximum stock', kind: 'number' },
        { key: 'lead_time_days', label: 'Lead time (days)', kind: 'number' },
        { key: 'ownership', label: 'Ownership (own / consignment)', kind: 'text' },
        { key: 'consignment_supplier_id', label: 'Consigning supplier id', kind: 'number' },
        { key: 'tags_add', label: 'Add tags (comma separated)', kind: 'tags' },
        { key: 'tags_remove', label: 'Remove tags (comma separated)', kind: 'tags' },
    ] as const;

    const field = ref<(typeof fields)[number]['key']>('category_id');
    const value = ref<string>('');

    async function apply() {
        const definition = fields.find((item) => item.key === field.value)!;
        const converted: unknown = definition.kind === 'number' ? Number(value.value)
            : definition.kind === 'boolean' ? value.value === 'true'
                : definition.kind === 'tags' ? value.value.split(',').map((tag) => tag.trim()).filter(Boolean)
                    : value.value;

        if (await run(() => window.axios.post('/api/products/bulk-update', { ids: props.ids, fields: { [field.value]: converted } }))) {
            emit('done');
        }
    }
</script>

<template>
    <div v-if="ids.length && can('/product/:id/edit')" class="border rounded p-2 mb-3 d-flex flex-wrap gap-2 align-items-end" data-test="bulk-panel">
        <div class="small fw-semibold align-self-center">{{ ids.length }} selected</div>
        <select v-model="field" class="form-select form-select-sm" style="width: auto" aria-label="Field">
            <option v-for="item in fields" :key="item.key" :value="item.key">{{ item.label }}</option>
        </select>
        <select v-if="fields.find((item) => item.key === field)?.kind === 'boolean'" v-model="value" class="form-select form-select-sm" style="width: auto" aria-label="Value">
            <option value="true">Yes</option><option value="false">No</option>
        </select>
        <input v-else v-model="value" class="form-control form-control-sm" style="width: 14rem" aria-label="Value" />
        <button type="button" class="btn btn-dark btn-sm" @click="apply">Update selected</button>
    </div>
    <div v-if="can('/product/export')" class="mb-2 text-end">
        <a class="btn btn-outline-dark btn-sm" href="/api/products/export-all" download>Export all fields (CSV)</a>
    </div>
</template>
