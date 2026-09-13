<script setup lang="ts">
    import { Sparkles } from '@lucide/vue';
    import { computed } from 'vue';
    import {
        MAX_VARIANT_COMBINATIONS,
        activeValueNames,
        combinationCount,
        combinationFactors,
        firstValidationError,
        variationDisplayName,
    } from '@/utils/variantCombiner';
    import type { ApplicableVariation, VariationSelection } from '@/utils/variantCombiner';

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

    function allSelected(variation: ApplicableVariation): boolean {
        const values = activeValueNames(variation);

        return values.length > 0 && selectedCount(variation) === values.length;
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
    <div class="variation-picker space-y-4">
        <div
            v-if="warning"
            class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
        >
            {{ warning }}
        </div>

        <div
            v-if="!scopeReady"
            class="flex flex-col gap-1 rounded-xl border border-slate-100 bg-slate-50 px-4 py-8 text-center text-xs text-slate-500"
        >
            <strong class="text-slate-800">Choose category and item type</strong>
            <span>Matching variation sets will appear here.</span>
        </div>

        <div
            v-else-if="variations.length === 0"
            class="flex flex-col gap-1 rounded-xl border border-slate-100 bg-slate-50 px-4 py-8 text-center text-xs text-slate-500"
        >
            <strong class="text-slate-800">No variations for this scope</strong>
            <span>Create them in Variation Master for this category, subcategory, and item type.</span>
        </div>

        <template v-else>
            <div class="flex items-center justify-between border-b border-slate-100 pb-1 text-xs text-slate-500">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    Selected values
                </span>
                <span class="italic">Click a value to include or exclude it</span>
            </div>

            <div class="space-y-3 divide-y divide-slate-100">
                <div
                    v-for="variation in variations"
                    :key="variation.id"
                    class="flex flex-col justify-between gap-3 pt-3 first:pt-0 sm:flex-row sm:items-center"
                    :class="{ 'opacity-50': ! selectionFor(variation).enabled }"
                >
                    <button
                        type="button"
                        class="flex w-32 items-center gap-2 text-left"
                        :disabled="disabled"
                        :aria-pressed="selectionFor(variation).enabled"
                        :aria-label="`Include ${variationDisplayName(variation)}`"
                        @click="toggleVariation(variation, ! selectionFor(variation).enabled)"
                    >
                        <span
                            class="flex h-5 w-5 items-center justify-center rounded-full border text-[10px] font-bold"
                            :class="selectionFor(variation).enabled
                                ? 'border-teal-200 bg-teal-50 text-teal-700'
                                : 'border-slate-200 bg-slate-50 text-slate-400'"
                        >
                            ✓
                        </span>
                        <span>
                            <span class="block text-xs font-bold text-slate-800">
                                {{ variationDisplayName(variation) }}
                            </span>
                            <span class="font-mono text-[10px] text-slate-400">
                                {{ selectedCount(variation) }}/{{ activeValueNames(variation).length }}
                            </span>
                        </span>
                    </button>

                    <div class="flex flex-1 flex-wrap items-center gap-2">
                        <p v-if="activeValueNames(variation).length === 0" class="m-0 text-xs text-amber-700">
                            No active values
                        </p>
                        <button
                            v-for="value in activeValueNames(variation)"
                            :key="value"
                            type="button"
                            class="variation-picker__chip cursor-pointer rounded-full border px-3 py-1 text-xs font-medium transition-all"
                            :class="selectionFor(variation).selectedValues.includes(value)
                                ? 'border-teal-300 bg-teal-50 font-semibold text-teal-800 shadow-[0_1px_2px_rgba(15,23,42,0.04)]'
                                : 'border-slate-200 bg-slate-50 text-slate-500 hover:bg-slate-100'"
                            :disabled="disabled || ! selectionFor(variation).enabled"
                            @click="toggleValue(variation, value)"
                        >
                            {{ value }}
                        </button>
                    </div>

                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                        <button
                            type="button"
                            class="cursor-pointer hover:text-teal-700 hover:underline"
                            :class="{ 'text-teal-700': allSelected(variation) }"
                            :disabled="disabled || activeValueNames(variation).length === 0"
                            @click="selectAllValues(variation)"
                        >
                            All
                        </button>
                        <span class="text-slate-300">|</span>
                        <button
                            type="button"
                            class="cursor-pointer hover:text-rose-600 hover:underline"
                            :disabled="disabled || selectedCount(variation) === 0"
                            @click="clearValues(variation)"
                        >
                            None
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <div
            class="variation-picker__footer flex flex-col justify-between gap-3 rounded-xl border border-slate-200/80 bg-slate-50/80 p-3.5 sm:flex-row sm:items-center"
            :class="{ 'border-amber-200 bg-amber-50': overLimit }"
        >
            <div class="flex items-center gap-2">
                <span
                    class="font-mono text-base font-bold text-teal-900"
                    :class="{ 'text-amber-800': overLimit }"
                >
                    {{ previewCount }}
                </span>
                <span class="text-xs text-slate-600">
                    {{ previewCount === 1 ? 'variant' : 'variants' }} / {{ MAX_VARIANT_COMBINATIONS }} max
                    <span v-if="previewFactors.length > 1" class="ml-1.5 font-mono text-slate-400">
                        ({{ previewFactors.join(' × ') }})
                    </span>
                    <span v-else-if="previewCount === 0" class="ml-1.5 text-slate-400">
                        Select at least one variation and value
                    </span>
                </span>
            </div>

            <button
                type="button"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-teal-700 px-4 py-2 text-xs font-bold text-white shadow-xs transition-colors hover:bg-teal-800 disabled:cursor-default disabled:opacity-40"
                :disabled="! canGenerate"
                @click="emit('generate')"
            >
                <span
                    v-if="generating"
                    class="spinner-border spinner-border-sm"
                    role="status"
                    aria-hidden="true"
                ></span>
                <Sparkles v-else class="h-3.5 w-3.5" />
                <span>{{ generating ? 'Generating…' : 'Generate Variations' }}</span>
            </button>
        </div>

        <div
            v-if="statusMessage"
            class="rounded-lg px-3 py-2 text-xs"
            :class="overLimit ? 'border border-amber-200 bg-amber-50 text-amber-800' : 'border border-rose-200 bg-rose-50 text-rose-700'"
        >
            {{ statusMessage }}
        </div>
    </div>
</template>

<style scoped>
.variation-picker {
    border-radius: 0.75rem;
}

.variation-picker__chip {
    border-radius: 9999px !important;
}

.variation-picker__footer {
    border-radius: 0.75rem !important;
}
</style>
