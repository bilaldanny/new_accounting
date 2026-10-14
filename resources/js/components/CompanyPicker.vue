<script setup lang="ts">
    import { onMounted, ref } from 'vue';

    /**
     * The company chooser of the pages a superadmin uses for any company: a named drop-down instead of a bare id box. It fills itself from
     * the companies the user may see, shows the choice, and tells the page (`change`) so the page reloads its data for that company.
     * With exactly one company it is chosen on its own.
     */
    const model = defineModel<number | string | ''>({ default: '' });
    const emit = defineEmits<{ change: [value: number | string | ''] }>();

    type Option = { id: number | string; text: string };

    const options = ref<Option[]>([]);
    const loading = ref(true);

    function onChange(event: Event) {
        const value = (event.target as HTMLSelectElement).value;
        model.value = value === '' ? '' : Number(value);
        emit('change', model.value);
    }

    onMounted(async () => {
        try {
            const response = await window.axios.get('/api/fetchcompanies');
            const rows = Array.isArray(response.data) ? response.data : (response.data?.data ?? []);
            options.value = rows.map((row: Record<string, any>) => ({ id: row.id, text: String(row.text ?? row.name ?? row.id) }));

            if (model.value === '' && options.value.length === 1) {
                model.value = Number(options.value[0].id);
                emit('change', model.value);
            }
        } catch {
            options.value = [];
        } finally {
            loading.value = false;
        }
    });
</script>

<template>
    <div class="company-picker" data-test="company-picker">
        <label class="company-picker__label" for="company-picker-select">Company</label>
        <select id="company-picker-select" class="company-picker__select" :value="model" :disabled="loading" aria-label="Company" @change="onChange">
            <option value="">{{ loading ? 'Loading companies...' : 'Select a company' }}</option>
            <option v-for="option in options" :key="option.id" :value="option.id">{{ option.text }}</option>
        </select>
        <span v-if="model === '' && ! loading" class="company-picker__hint">Choose a company to see its data.</span>
    </div>
</template>

<style scoped>
.company-picker {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
    margin: 0 0 1rem;
    padding: 0.55rem 0.9rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.95rem;
    background: #fff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.company-picker__label {
    margin: 0;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #64748b;
}

.company-picker__select {
    min-width: 17rem;
    max-width: 100%;
    padding: 0.45rem 2rem 0.45rem 0.85rem;
    border: 1px solid #dbe4ee;
    border-radius: 999px;
    background-color: #f8fafc;
    font-size: 0.9rem;
    font-weight: 600;
    color: #0f172a;
}

.company-picker__select:focus {
    outline: none;
    border-color: #199683;
    box-shadow: 0 0 0 3px rgba(25, 150, 131, 0.16);
}

.company-picker__hint {
    font-size: 0.82rem;
    color: #94a3b8;
}
</style>
