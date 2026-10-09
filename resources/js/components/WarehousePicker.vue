<script setup lang="ts">
    import { ref, watch } from 'vue';

    /**
     * An optional warehouse for a stock document, from the warehouses of its branch. Leaving it empty keeps the document
     * "unassigned": branch-level stock works exactly as before and only the Warehouse Stock report notices the tag.
     */
    const props = defineProps<{
        branchId: number | string | null | undefined;
        modelValue: number | string | null | undefined;
        label?: string;
        disabled?: boolean;
    }>();

    const emit = defineEmits<{ 'update:modelValue': [value: number | string] }>();

    const warehouses = ref<Array<Record<string, any>>>([]);

    async function load() {
        if (! props.branchId) {
            warehouses.value = [];
        } else {
            try {
                warehouses.value = (await window.axios.get('/api/fetchwarehouses', { params: { branch_id: props.branchId } })).data ?? [];
            } catch {
                warehouses.value = [];
            }
        }

        // A warehouse of another branch cannot stay selected.
        if (props.modelValue && ! warehouses.value.some((warehouse) => String(warehouse.id) === String(props.modelValue))) {
            emit('update:modelValue', '');
        }
    }

    watch(() => props.branchId, load, { immediate: true });
</script>

<template>
    <div class="mb-3">
        <label class="form-label">{{ label ?? 'Warehouse (optional)' }}</label>
        <select class="form-select" :disabled="disabled || ! branchId" :value="modelValue ?? ''" @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)">
            <option value="">{{ branchId ? 'No warehouse (unassigned)' : 'Choose a branch first' }}</option>
            <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.text ?? warehouse.name }}</option>
        </select>
    </div>
</template>
