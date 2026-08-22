<script setup lang="ts">
    import {
        MAX_VARIANT_COMBINATIONS,
        activeValueNames,
        combinationCount,
        combinationFactors,
        firstValidationError,
        type ApplicableVariation,
        type VariationSelection,
        variationDisplayName,
    } from '@/utils/variantCombiner';
    import { Checks } from '@boxicons/vue';
    import { computed } from 'vue';

    const props = defineProps({
        variations: {
            type: Array as () => ApplicableVariation[],
            default: () => [],
        },
        selections: {
            type: Array as () => VariationSelection[],
            default: () => [],
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        generating: {
            type: Boolean,
            default: false,
        },
        scopeReady: {
            type: Boolean,
            default: false,
        },
        warning: {
            type: String,
            default: '',
        },
        error: {
            type: String,
            default: '',
        },
    });

    const emit = defineEmits<{
        'update:selections': [VariationSelection[]];
        generate: [];
    }>();

    const enabledSelections = computed(() => props.selections.filter((selection) => selection.enabled));

    const previewCount = computed(() => combinationCount(
        enabledSelections.value.map((selection) => ({ values: selection.selectedValues })),
    ));

    const previewFactors = computed(() => combinationFactors(
        enabledSelections.value.map((selection) => ({ values: selection.selectedValues })),
    ));

    const overLimit = computed(() => previewCount.value > MAX_VARIANT_COMBINATIONS);
    const validationError = computed(() => firstValidationError(props.variations, props.selections));
    const statusMessage = computed(() => props.error || validationError.value);
    const canGenerate = computed(() => ! props.disabled && ! props.generating && validationError.value === '');

    function selectionFor(variation: ApplicableVariation): VariationSelection {
        return props.selections.find((selection) => String(selection.variationId) === String(variation.id))
            ?? {
                variationId: variation.id,
                enabled: true,
                selectedValues: activeValueNames(variation),
            };
    }

    function selectedCount(variation: ApplicableVariation): number {
        return selectionFor(variation).selectedValues.length;
    }

    function updateSelection(variation: ApplicableVariation, patch: Partial<VariationSelection>) {
        const next = props.variations.map((item) => {
            const current = selectionFor(item);

            if (String(item.id) !== String(variation.id)) {
                return current;
            }

            return { ...current, ...patch };
        });

        emit('update:selections', next);
    }

    function toggleVariation(variation: ApplicableVariation, enabled: boolean) {
        const values = activeValueNames(variation);

        updateSelection(variation, {
            enabled,
            selectedValues: enabled
                ? (selectionFor(variation).selectedValues.length > 0
                    ? selectionFor(variation).selectedValues
                    : values)
                : [],
        });
    }

    function toggleValue(variation: ApplicableVariation, value: string) {
        const current = selectionFor(variation);

        if (! current.enabled) {
            updateSelection(variation, {
                enabled: true,
                selectedValues: [value],
            });

            return;
        }

        const selected = current.selectedValues.includes(value)
            ? current.selectedValues.filter((item) => item !== value)
            : [...current.selectedValues, value];

        updateSelection(variation, { selectedValues: selected });
    }

    function selectAllValues(variation: ApplicableVariation) {
        updateSelection(variation, {
            enabled: true,
            selectedValues: activeValueNames(variation),
        });
    }

    function clearValues(variation: ApplicableVariation) {
        updateSelection(variation, {
            enabled: selectionFor(variation).enabled,
            selectedValues: [],
        });
    }
</script>

<template>
    <div class="variation-picker">
        <div v-if="warning" class="variation-picker__notice is-warning">
            {{ warning }}
        </div>

        <div v-if="!scopeReady" class="variation-picker__empty">
            <strong>Choose category and item type</strong>
            <span>Matching variation sets will appear here.</span>
        </div>

        <div v-else-if="variations.length === 0" class="variation-picker__empty">
            <strong>No variations for this scope</strong>
            <span>Create them in Variation Master for this category, subcategory, and item type.</span>
        </div>

        <template v-else>
            <div class="variation-picker__toolbar">
                <span class="variation-picker__toolbar-label">Selected values</span>
                <span class="variation-picker__toolbar-hint">Click a value to include or exclude it</span>
            </div>

            <div class="variation-picker__list">
                <div
                    v-for="variation in variations"
                    :key="variation.id"
                    class="variation-picker__row"
                    :class="{ 'is-off': ! selectionFor(variation).enabled }"
                >
                    <button
                        type="button"
                        class="variation-picker__check"
                        :class="{ 'is-on': selectionFor(variation).enabled }"
                        :disabled="disabled"
                        :aria-pressed="selectionFor(variation).enabled"
                        :aria-label="`Include ${variationDisplayName(variation)}`"
                        @click="toggleVariation(variation, ! selectionFor(variation).enabled)"
                    >
                        <Checks v-if="selectionFor(variation).enabled" size="xs" />
                    </button>

                    <div class="variation-picker__meta">
                        <span class="variation-picker__name">{{ variationDisplayName(variation) }}</span>
                        <span class="variation-picker__count">
                            {{ selectedCount(variation) }}/{{ activeValueNames(variation).length }}
                        </span>
                    </div>

                    <div class="variation-picker__values">
                        <p v-if="activeValueNames(variation).length === 0" class="variation-picker__hint">
                            No active values
                        </p>
                        <button
                            v-for="value in activeValueNames(variation)"
                            :key="value"
                            type="button"
                            class="variation-picker__chip"
                            :class="{ 'is-on': selectionFor(variation).selectedValues.includes(value) }"
                            :disabled="disabled || ! selectionFor(variation).enabled"
                            @click="toggleValue(variation, value)"
                        >
                            {{ value }}
                        </button>
                    </div>

                    <div class="variation-picker__row-actions">
                        <button
                            type="button"
                            class="variation-picker__link"
                            :disabled="disabled || activeValueNames(variation).length === 0"
                            @click="selectAllValues(variation)"
                        >
                            All
                        </button>
                        <button
                            type="button"
                            class="variation-picker__link"
                            :disabled="disabled || selectedCount(variation) === 0"
                            @click="clearValues(variation)"
                        >
                            None
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <div class="variation-picker__footer" :class="{ 'is-over': overLimit }">
            <div class="variation-picker__summary">
                <span class="variation-picker__total" :class="{ 'is-over': overLimit }">
                    {{ previewCount }}
                </span>
                <div>
                    <div class="variation-picker__total-label">
                        {{ previewCount === 1 ? 'variant' : 'variants' }}
                        <span class="variation-picker__limit"> / {{ MAX_VARIANT_COMBINATIONS }} max</span>
                    </div>
                    <div v-if="previewFactors.length > 1" class="variation-picker__formula">
                        {{ previewFactors.join(' × ') }}
                    </div>
                    <div v-else-if="previewCount === 0" class="variation-picker__formula">
                        Select at least one variation and value
                    </div>
                </div>
            </div>

            <button
                type="button"
                class="variation-picker__generate"
                :disabled="! canGenerate"
                @click="emit('generate')"
            >
                <span
                    v-if="generating"
                    class="spinner-border spinner-border-sm"
                    role="status"
                    aria-hidden="true"
                ></span>
                {{ generating ? 'Generating…' : 'Generate Variations' }}
            </button>
        </div>

        <div v-if="statusMessage" class="variation-picker__notice" :class="overLimit ? 'is-warning' : 'is-error'">
            {{ statusMessage }}
        </div>
    </div>
</template>

<style scoped>
.variation-picker {
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-lg, 12px);
    background: #fff;
    overflow: hidden;
}

.variation-picker__notice {
    padding: 0.65rem 1rem;
    font-size: 0.75rem;
    line-height: 1.45;
}

.variation-picker__notice.is-warning {
    background: #fffbeb;
    color: #92400e;
    border-bottom: 1px solid #fde68a;
}

.variation-picker__notice.is-error {
    background: #fef2f2;
    color: #b91c1c;
    border-top: 1px solid #fecaca;
}

.variation-picker__empty {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    padding: 1.4rem 1rem;
    text-align: center;
    color: var(--app-text-secondary, #64748b);
    font-size: 0.8125rem;
    background: #f8fafc;
}

.variation-picker__empty strong {
    color: var(--app-text, #111827);
}

.variation-picker__toolbar {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.55rem 1rem;
    background: #f8fafc;
    border-bottom: 1px solid var(--app-border, #e5e7eb);
}

.variation-picker__toolbar-label {
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}

.variation-picker__toolbar-hint {
    font-size: 0.75rem;
    color: #94a3b8;
}

.variation-picker__list {
    display: flex;
    flex-direction: column;
}

.variation-picker__row {
    display: grid;
    grid-template-columns: 1.25rem 7.5rem minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.75rem;
    padding: 0.55rem 1rem;
    border-bottom: 1px solid var(--app-border-subtle, #f1f5f9);
}

.variation-picker__row.is-off {
    opacity: 0.5;
}

.variation-picker__check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.15rem;
    height: 1.15rem;
    padding: 0;
    border: 2px solid #334155;
    border-radius: 0.25rem;
    background: #fff;
    color: #fff;
}

.variation-picker__check.is-on {
    border-color: var(--app-primary, #6366f1);
    background: var(--app-primary, #6366f1);
}

.variation-picker__check:disabled {
    opacity: 0.45;
}

.variation-picker__meta {
    display: flex;
    flex-direction: column;
    min-width: 0;
    gap: 0.05rem;
}

.variation-picker__name {
    font-size: 0.8125rem;
    font-weight: 650;
    color: var(--app-text, #111827);
    line-height: 1.2;
}

.variation-picker__count {
    font-size: 0.6875rem;
    color: #94a3b8;
    font-variant-numeric: tabular-nums;
}

.variation-picker__values {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
    min-width: 0;
}

.variation-picker__hint {
    margin: 0;
    font-size: 0.75rem;
    color: #b45309;
}

.variation-picker__chip {
    min-height: 1.55rem;
    padding: 0.1rem 0.55rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    color: #64748b;
    font-size: 0.6875rem;
    font-weight: 600;
}

.variation-picker__chip.is-on {
    border-color: #c7d2fe;
    background: #eef2ff;
    color: #3730a3;
}

.variation-picker__chip:disabled {
    cursor: default;
}

.variation-picker__row-actions {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.variation-picker__link {
    padding: 0;
    border: 0;
    background: transparent;
    color: #6366f1;
    font-size: 0.6875rem;
    font-weight: 650;
}

.variation-picker__link:disabled {
    color: #cbd5e1;
}

.variation-picker__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.85rem;
    padding: 0.7rem 1rem;
    background: #f8fafc;
    border-top: 1px solid var(--app-border, #e5e7eb);
}

.variation-picker__footer.is-over {
    background: #fffbeb;
}

.variation-picker__summary {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    min-width: 0;
}

.variation-picker__total {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2.4rem;
    height: 2.15rem;
    padding: 0 0.45rem;
    border-radius: 0.45rem;
    background: #eef2ff;
    color: #3730a3;
    font-size: 0.9375rem;
    font-weight: 750;
    font-variant-numeric: tabular-nums;
}

.variation-picker__total.is-over {
    background: #fef3c7;
    color: #92400e;
}

.variation-picker__total-label {
    font-size: 0.8125rem;
    font-weight: 650;
    color: var(--app-text, #111827);
    line-height: 1.2;
}

.variation-picker__limit {
    font-weight: 500;
    color: #94a3b8;
}

.variation-picker__formula {
    margin-top: 0.1rem;
    font-size: 0.6875rem;
    color: #94a3b8;
    font-variant-numeric: tabular-nums;
}

.variation-picker__generate {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    min-height: 2.15rem;
    padding: 0.3rem 0.95rem;
    border: 0;
    border-radius: 0.45rem;
    background: var(--app-primary, #6366f1);
    color: #fff;
    font-size: 0.8125rem;
    font-weight: 650;
    white-space: nowrap;
}

.variation-picker__generate:disabled {
    opacity: 0.4;
}

@media (max-width: 767.98px) {
    .variation-picker__toolbar {
        flex-direction: column;
        gap: 0.15rem;
    }

    .variation-picker__row {
        grid-template-columns: 1.25rem minmax(0, 1fr) auto;
        grid-template-areas:
            "check meta actions"
            "values values values";
    }

    .variation-picker__check {
        grid-area: check;
    }

    .variation-picker__meta {
        grid-area: meta;
    }

    .variation-picker__values {
        grid-area: values;
        padding-left: 2rem;
    }

    .variation-picker__row-actions {
        grid-area: actions;
    }

    .variation-picker__footer {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>
