<script setup lang="ts">
    import { Plus, Trash } from '@boxicons/vue';
    import { ref, watch } from 'vue';

    type VariationValue = {
        name: string;
        active: boolean;
    };

    const props = defineProps({
        values: {
            type: Array as () => VariationValue[],
            default: () => [{ name: '', active: true }],
        },
    });

    const emit = defineEmits<{
        'update:values': [VariationValue[]];
    }>();

    const localValues = ref<VariationValue[]>([{ name: '', active: true }]);

    watch(
        () => props.values,
        (values) => {
            if (Array.isArray(values) && values.length > 0) {
                localValues.value = values.map((item) => ({ ...item }));

                return;
            }

            localValues.value = [{ name: '', active: true }];
        },
        { immediate: true, deep: true },
    );

    function updateValues(nextValues: VariationValue[]) {
        localValues.value = nextValues;
        emit('update:values', nextValues);
    }

    function addValue() {
        updateValues([
            ...localValues.value,
            { name: '', active: true },
        ]);
    }

    function removeValue(index: number) {
        if (localValues.value.length <= 1) {
            updateValues([{ name: '', active: true }]);

            return;
        }

        updateValues(localValues.value.filter((_, itemIndex) => itemIndex !== index));
    }

    function updateName(index: number, name: string) {
        const nextValues = localValues.value.map((item, itemIndex) => (
            itemIndex === index ? { ...item, name } : item
        ));

        updateValues(nextValues);
    }

    function updateActive(index: number, active: boolean) {
        const nextValues = localValues.value.map((item, itemIndex) => (
            itemIndex === index ? { ...item, active } : item
        ));

        updateValues(nextValues);
    }
</script>

<template>
    <div class="variation-values-editor">
        <div class="variation-values-editor__header">
            <div>
                <span class="variation-values-editor__title">Value options</span>
                <span class="variation-values-editor__hint">Add at least one named value (e.g. Small, Red, Cotton)</span>
            </div>
            <button
                type="button"
                class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1"
                @click="addValue"
            >
                <Plus size="xs" />
                Add value
            </button>
        </div>

        <div class="variation-values-editor__list">
            <div
                v-for="(item, index) in localValues"
                :key="index"
                class="variation-values-editor__row"
            >
                <div class="variation-values-editor__index">{{ index + 1 }}</div>

                <div class="variation-values-editor__field">
                    <label :for="`variation-value-${index}`" class="form-label variation-values-editor__label">
                        Value name
                    </label>
                    <input
                        :id="`variation-value-${index}`"
                        type="text"
                        class="form-control form-control-sm"
                        placeholder="e.g. Medium, Blue, Leather"
                        :value="item.name"
                        @input="updateName(index, ($event.target as HTMLInputElement).value)"
                    >
                </div>

                <div class="variation-values-editor__toggle">
                    <label class="form-label variation-values-editor__label">Active</label>
                    <div class="form-check form-switch variation-values-editor__switch">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            :id="`variation-active-${index}`"
                            :checked="item.active"
                            @change="updateActive(index, ($event.target as HTMLInputElement).checked)"
                        >
                        <label class="form-check-label" :for="`variation-active-${index}`">
                            {{ item.active ? 'Yes' : 'No' }}
                        </label>
                    </div>
                </div>

                <div class="variation-values-editor__actions">
                    <button
                        v-if="localValues.length > 1"
                        type="button"
                        class="btn btn-sm btn-outline-danger"
                        title="Remove value"
                        @click="removeValue(index)"
                    >
                        <Trash size="xs" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.variation-values-editor {
    border: 1px solid var(--bs-border-color, #e9ecef);
    border-radius: 0.75rem;
    background: var(--bs-body-bg, #fff);
    overflow: hidden;
}

.variation-values-editor__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--bs-border-color, #e9ecef);
    background: rgb(13 110 253 / 4%);
}

.variation-values-editor__title {
    display: block;
    font-weight: 600;
    font-size: 0.875rem;
}

.variation-values-editor__hint {
    display: block;
    color: var(--bs-secondary-color, #6c757d);
    font-size: 0.75rem;
    margin-top: 0.125rem;
}

.variation-values-editor__list {
    display: flex;
    flex-direction: column;
}

.variation-values-editor__row {
    display: grid;
    grid-template-columns: 2rem minmax(0, 1fr) 7rem 2.5rem;
    gap: 0.75rem;
    align-items: end;
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--bs-border-color-translucent, rgb(0 0 0 / 6%));
}

.variation-values-editor__row:last-child {
    border-bottom: 0;
}

.variation-values-editor__index {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    background: rgb(13 110 253 / 8%);
    color: var(--bs-primary, #0d6efd);
    font-size: 0.75rem;
    font-weight: 700;
}

.variation-values-editor__label {
    margin-bottom: 0.35rem;
    font-size: 0.75rem;
    color: var(--bs-secondary-color, #6c757d);
}

.variation-values-editor__switch {
    min-height: 1.875rem;
    padding-left: 2.5rem;
}

.variation-values-editor__actions {
    display: flex;
    align-items: center;
    justify-content: center;
    padding-bottom: 0.25rem;
}

@media (max-width: 767.98px) {
    .variation-values-editor__header {
        flex-direction: column;
        align-items: flex-start;
    }

    .variation-values-editor__row {
        grid-template-columns: 2rem minmax(0, 1fr);
        grid-template-areas: "index field" "toggle actions";
    }

    .variation-values-editor__index {
        grid-area: index;
    }

    .variation-values-editor__field {
        grid-area: field;
    }

    .variation-values-editor__toggle {
        grid-area: toggle;
    }

    .variation-values-editor__actions {
        grid-area: actions;
        justify-content: flex-start;
    }
}
</style>
