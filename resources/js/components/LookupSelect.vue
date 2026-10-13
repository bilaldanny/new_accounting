<script setup lang="ts">
    import { ref, watch } from 'vue';

    /**
     * A select that fills itself from one of the existing "fetch" lookups (customers, products, accounts, users). Those
     * endpoints already return only the signed-in user's own company; `params` is how a superadmin names another one.
     */
    const props = withDefaults(defineProps<{
        endpoint: string;
        modelValue: number | string | null | undefined;
        params?: Record<string, unknown>;
        placeholder?: string;
        ariaLabel?: string;
        disabled?: boolean;
    }>(), { params: () => ({}), placeholder: 'Choose', ariaLabel: undefined, disabled: false });

    const emit = defineEmits<{ 'update:modelValue': [value: number | ''] }>();

    const options = ref<Array<{ id: number; text: string }>>([]);

    async function load() {
        try {
            const rows = (await window.axios.get(props.endpoint, { params: props.params })).data;
            const list = Array.isArray(rows) ? rows : (rows?.data ?? []);

            options.value = list.map((row: Record<string, any>) => ({ id: Number(row.id), text: String(row.text ?? row.name ?? row.business_name ?? row.id) }));
        } catch {
            options.value = [];
        }

        // A saved value that is not in the list (an inactive record, another company's id) stays visible instead of being
        // dropped silently, so saving the form does not wipe it.
        if (props.modelValue && ! options.value.some((option) => String(option.id) === String(props.modelValue))) {
            options.value.unshift({ id: Number(props.modelValue), text: `#${props.modelValue} (not in this list)` });
        }
    }

    watch(() => [props.endpoint, JSON.stringify(props.params)], load, { immediate: true });

    const choose = (event: Event) => {
        const value = (event.target as HTMLSelectElement).value;

        emit('update:modelValue', value === '' ? '' : Number(value));
    };
</script>

<template>
    <select class="form-select form-select-sm" :aria-label="ariaLabel ?? placeholder" :disabled="disabled" :value="modelValue ?? ''" @change="choose">
        <option value="">{{ placeholder }}</option>
        <option v-for="option in options" :key="option.id" :value="option.id">{{ option.text }}</option>
    </select>
</template>
