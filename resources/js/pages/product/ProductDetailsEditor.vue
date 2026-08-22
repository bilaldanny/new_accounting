<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { openLfmImagePickerCallback } from '@/utils/openLfmImagePicker';
    import { Checks, ImagePlus, Trash } from '@boxicons/vue';
    import { computed, ref, watch } from 'vue';

    export type ProductDetailRow = {
        id?: number | string;
        variation_name: string;
        default_purchase_price: number | string;
        largequantity: number | string;
        smallquantity: number | string;
        profit_percent: number | string;
        default_sell_price: number | string;
        variation_image?: string;
        sku?: string;
        name?: string;
    };

    const props = defineProps({
        details: {
            type: Array as () => ProductDetailRow[],
            default: () => [],
        },
        productType: {
            type: String,
            default: 'single',
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        embedded: {
            type: Boolean,
            default: false,
        },
    });

    const emit = defineEmits<{
        'update:details': [ProductDetailRow[]];
    }>();

    const { appUrl } = useCommons();
    const localDetails = ref<ProductDetailRow[]>([]);
    const selectedIndexes = ref<number[]>([]);

    const isVariable = computed(() => props.productType === 'variable');
    const showApplyAll = computed(() => isVariable.value && localDetails.value.length > 1);

    function canApplyBelow(index: number): boolean {
        return showApplyAll.value && index < localDetails.value.length - 1;
    }
    const allSelected = computed(() => (
        isVariable.value
        && localDetails.value.length > 0
        && selectedIndexes.value.length === localDetails.value.length
    ));

    function pricingRowsMatch(left: ProductDetailRow[], right: ProductDetailRow[]): boolean {
        if (left.length !== right.length) {
            return false;
        }

        return left.every((row, index) => {
            const other = right[index];

            return Boolean(other)
                && row.variation_name === other.variation_name
                && String(row.default_purchase_price) === String(other.default_purchase_price)
                && String(row.profit_percent) === String(other.profit_percent)
                && String(row.default_sell_price) === String(other.default_sell_price)
                && String(row.largequantity) === String(other.largequantity)
                && String(row.smallquantity) === String(other.smallquantity)
                && String(row.variation_image ?? '') === String(other.variation_image ?? '')
                && String(row.sku ?? '') === String(other.sku ?? '');
        });
    }

    watch(
        () => props.details,
        (details) => {
            if (! Array.isArray(details) || details.length === 0) {
                localDetails.value = [];
                selectedIndexes.value = [];

                return;
            }

            if (pricingRowsMatch(details, localDetails.value)) {
                return;
            }

            localDetails.value = details.map((item) => ({ ...item }));
            selectedIndexes.value = selectedIndexes.value.filter((index) => index < localDetails.value.length);
        },
        { immediate: true, deep: true },
    );

    function toNumber(value: unknown): number {
        const parsed = Number(value);

        return Number.isFinite(parsed) ? parsed : 0;
    }

    function calculatedSellPrice(row: ProductDetailRow): string {
        const purchase = toNumber(row.default_purchase_price);
        const margin = toNumber(row.profit_percent);

        return (purchase + ((purchase * margin) / 100)).toFixed(2);
    }

    function variationLabel(name: string): string {
        return name === 'dummy' ? 'Standard' : name;
    }

    function updateDetails(nextDetails: ProductDetailRow[]) {
        localDetails.value = nextDetails;
        emit('update:details', nextDetails.map((item) => ({ ...item })));
    }

    function withCalculatedSellPrice(row: ProductDetailRow): ProductDetailRow {
        return {
            ...row,
            default_sell_price: calculatedSellPrice(row),
        };
    }

    function updateRow(index: number, patch: Partial<ProductDetailRow>, recalculate = false) {
        const nextDetails = localDetails.value.map((item, itemIndex) => {
            if (itemIndex !== index) {
                return item;
            }

            const nextRow = { ...item, ...patch };

            if (
                recalculate
                || Object.prototype.hasOwnProperty.call(patch, 'default_purchase_price')
                || Object.prototype.hasOwnProperty.call(patch, 'profit_percent')
            ) {
                return withCalculatedSellPrice(nextRow);
            }

            return nextRow;
        });

        updateDetails(nextDetails);
    }

    function applyToAll(
        index: number,
        field: 'default_purchase_price' | 'profit_percent' | 'default_sell_price',
    ) {
        const source = localDetails.value[index];

        if (! source) {
            return;
        }

        const value = source[field];
        const nextDetails = localDetails.value.map((item, itemIndex) => {
            if (itemIndex <= index) {
                return item;
            }

            const nextRow = { ...item, [field]: value };

            if (field === 'default_sell_price') {
                return nextRow;
            }

            return withCalculatedSellPrice(nextRow);
        });

        updateDetails(nextDetails);
    }

    function removeRow(index: number) {
        if (! isVariable.value || localDetails.value.length <= 1) {
            return;
        }

        updateDetails(localDetails.value.filter((_, itemIndex) => itemIndex !== index));
        selectedIndexes.value = [];
    }

    function toggleSelect(index: number, checked: boolean) {
        if (checked) {
            selectedIndexes.value = [...new Set([...selectedIndexes.value, index])];

            return;
        }

        selectedIndexes.value = selectedIndexes.value.filter((item) => item !== index);
    }

    function toggleSelectAll(checked: boolean) {
        selectedIndexes.value = checked ? localDetails.value.map((_, index) => index) : [];
    }

    function bulkRemove() {
        if (! isVariable.value || selectedIndexes.value.length === 0) {
            return;
        }

        const remaining = localDetails.value.filter((_, index) => ! selectedIndexes.value.includes(index));
        updateDetails(remaining.length > 0 ? remaining : localDetails.value);
        selectedIndexes.value = [];
    }

    function resolveMediaUrl(path: unknown): string {
        const value = String(path ?? '').trim();

        if (! value) {
            return '';
        }

        if (
            value.startsWith('http://')
            || value.startsWith('https://')
            || value.startsWith('data:')
            || value.startsWith('blob:')
        ) {
            return value;
        }

        const base = String(appUrl ?? '').replace(/\/$/, '');

        return `${base}/${value.replace(/^\//, '')}`;
    }

    function chooseVariationImage(event: MouseEvent, index: number) {
        if (props.disabled) {
            return;
        }

        openLfmImagePickerCallback(event, appUrl, (path) => {
            updateRow(index, { variation_image: path });
        });
    }
</script>

<template>
    <div class="pricing-table" :class="{ 'is-embedded': embedded }">
        <div v-if="!embedded" class="pricing-table__intro">
            <span class="pricing-table__intro-title">
                {{ isVariable ? 'Generated variants' : 'Product price' }}
            </span>
            <span class="pricing-table__intro-hint">Sell price updates from purchase plus margin.</span>
        </div>

        <div v-if="isVariable && selectedIndexes.length > 0" class="pricing-table__toolbar">
            <span class="pricing-table__toolbar-count">{{ selectedIndexes.length }} selected</span>
            <button
                type="button"
                class="pricing-table__bulk-remove"
                :disabled="disabled || selectedIndexes.length >= localDetails.length"
                @click="bulkRemove"
            >
                <Trash size="xs" />
                Remove selected
            </button>
        </div>

        <div v-if="localDetails.length === 0" class="pricing-table__empty">
            <strong>No generated variants yet</strong>
            <span>Select variations and values, then click Generate Variations.</span>
        </div>

        <div v-else class="pricing-table__wrap">
            <table class="pricing-table__grid">
                <thead>
                    <tr>
                        <th v-if="isVariable" class="is-check">
                            <button
                                type="button"
                                class="pricing-table__check"
                                :class="{ 'is-on': allSelected }"
                                :disabled="disabled"
                                :aria-pressed="allSelected"
                                :aria-label="allSelected ? 'Clear row selection' : 'Select all rows'"
                                @click="toggleSelectAll(! allSelected)"
                            >
                                <Checks v-if="allSelected" size="xs" />
                            </button>
                        </th>
                        <th v-if="isVariable" class="is-name">Variation</th>
                        <th v-if="isVariable" class="is-sku">SKU</th>
                        <th class="is-money">Purchase</th>
                        <th class="is-qty">Large</th>
                        <th class="is-qty">Small</th>
                        <th class="is-money">Margin %</th>
                        <th class="is-money">Sell price</th>
                        <th class="is-image">Image</th>
                        <th v-if="isVariable" class="is-action"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(item, index) in localDetails"
                        :key="item.id ?? index"
                        :class="{ 'is-selected': selectedIndexes.includes(index) }"
                    >
                        <td v-if="isVariable" class="is-check">
                            <button
                                type="button"
                                class="pricing-table__check"
                                :class="{ 'is-on': selectedIndexes.includes(index) }"
                                :disabled="disabled"
                                :aria-pressed="selectedIndexes.includes(index)"
                                :aria-label="`Select ${variationLabel(item.variation_name)}`"
                                @click="toggleSelect(index, ! selectedIndexes.includes(index))"
                            >
                                <Checks v-if="selectedIndexes.includes(index)" size="xs" />
                            </button>
                        </td>
                        <td v-if="isVariable" class="is-name">
                            <span class="pricing-table__name">{{ variationLabel(item.variation_name) }}</span>
                        </td>
                        <td v-if="isVariable" class="is-sku">
                            <input
                                type="text"
                                class="pricing-table__input is-text"
                                placeholder="Auto on save"
                                :value="item.sku"
                                :disabled="disabled"
                                @input="updateRow(index, { sku: ($event.target as HTMLInputElement).value })"
                            >
                        </td>
                        <td class="is-money">
                            <div class="pricing-table__control">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="pricing-table__input"
                                    placeholder="0.00"
                                    :value="item.default_purchase_price"
                                    :disabled="disabled"
                                    @input="updateRow(index, { default_purchase_price: ($event.target as HTMLInputElement).value }, true)"
                                >
                                <button
                                    v-if="canApplyBelow(index)"
                                    type="button"
                                    class="pricing-table__apply"
                                    title="Apply this purchase price to rows below"
                                    :disabled="disabled"
                                    @click="applyToAll(index, 'default_purchase_price')"
                                >
                                    <Checks size="xs" />
                                </button>
                            </div>
                        </td>
                        <td class="is-qty">
                            <input
                                type="number"
                                min="0"
                                class="pricing-table__input"
                                placeholder="0"
                                :value="item.largequantity"
                                :disabled="disabled"
                                @input="updateRow(index, { largequantity: ($event.target as HTMLInputElement).value })"
                            >
                        </td>
                        <td class="is-qty">
                            <input
                                type="number"
                                min="0"
                                class="pricing-table__input"
                                placeholder="0"
                                :value="item.smallquantity"
                                :disabled="disabled"
                                @input="updateRow(index, { smallquantity: ($event.target as HTMLInputElement).value })"
                            >
                        </td>
                        <td class="is-money">
                            <div class="pricing-table__control">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="pricing-table__input"
                                    placeholder="0"
                                    :value="item.profit_percent"
                                    :disabled="disabled"
                                    @input="updateRow(index, { profit_percent: ($event.target as HTMLInputElement).value }, true)"
                                >
                                <button
                                    v-if="canApplyBelow(index)"
                                    type="button"
                                    class="pricing-table__apply"
                                    title="Apply this margin to rows below"
                                    :disabled="disabled"
                                    @click="applyToAll(index, 'profit_percent')"
                                >
                                    <Checks size="xs" />
                                </button>
                            </div>
                        </td>
                        <td class="is-money">
                            <div class="pricing-table__control">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="pricing-table__input"
                                    placeholder="0.00"
                                    :value="item.default_sell_price"
                                    :disabled="disabled"
                                    @input="updateRow(index, { default_sell_price: ($event.target as HTMLInputElement).value })"
                                >
                                <button
                                    v-if="canApplyBelow(index)"
                                    type="button"
                                    class="pricing-table__apply"
                                    title="Apply this sell price to rows below"
                                    :disabled="disabled"
                                    @click="applyToAll(index, 'default_sell_price')"
                                >
                                    <Checks size="xs" />
                                </button>
                            </div>
                        </td>
                        <td class="is-image">
                            <button
                                type="button"
                                class="pricing-table__photo"
                                :class="{ 'has-file': Boolean(item.variation_image) }"
                                title="Choose image"
                                :disabled="disabled"
                                @click="chooseVariationImage($event, index)"
                            >
                                <img v-if="item.variation_image" :src="resolveMediaUrl(item.variation_image)" alt="">
                                <ImagePlus v-else size="xs" />
                            </button>
                        </td>
                        <td v-if="isVariable" class="is-action">
                            <button
                                type="button"
                                class="pricing-table__remove"
                                title="Remove variation"
                                :disabled="disabled || localDetails.length <= 1"
                                @click="removeRow(index)"
                            >
                                <Trash size="xs" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
.pricing-table {
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-lg, 12px);
    background: #fff;
    overflow: hidden;
}

.pricing-table.is-embedded {
    border: 0;
    border-radius: 0;
}

.pricing-table__intro {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.9rem 1.1rem;
    border-bottom: 1px solid var(--app-border-subtle, #f1f5f9);
}

.pricing-table__intro-title {
    font-size: 0.875rem;
    font-weight: 650;
    color: var(--app-text, #111827);
}

.pricing-table__intro-hint {
    font-size: 0.75rem;
    color: var(--app-text-muted, #94a3b8);
}

.pricing-table__toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.55rem 0.85rem;
    background: #eef2ff;
    border-bottom: 1px solid #c7d2fe;
}

.pricing-table__toolbar-count {
    font-size: 0.75rem;
    font-weight: 650;
    color: #3730a3;
}

.pricing-table__bulk-remove {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    min-height: 1.85rem;
    padding: 0.2rem 0.65rem;
    border: 1px solid #fecaca;
    border-radius: 0.4rem;
    background: #fff;
    color: #dc2626;
    font-size: 0.75rem;
    font-weight: 600;
}

.pricing-table__bulk-remove:disabled {
    opacity: 0.45;
}

.pricing-table__empty {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 1.75rem 1rem;
    text-align: center;
    color: var(--app-text-secondary, #64748b);
    font-size: 0.8125rem;
    background: #f8fafc;
}

.pricing-table__empty strong {
    color: var(--app-text, #111827);
}

.pricing-table__wrap {
    overflow-x: auto;
}

.pricing-table__grid {
    width: 100%;
    margin: 0;
    border-collapse: collapse;
}

.pricing-table__grid th,
.pricing-table__grid td {
    padding: 0.65rem 0.75rem;
    border-bottom: 1px solid var(--app-border-subtle, #f1f5f9);
    vertical-align: middle;
}

.pricing-table__grid thead th {
    padding: 0.7rem 0.75rem;
    background: #f8fafc;
    color: #64748b;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
    border-bottom: 1px solid var(--app-border, #e5e7eb);
}

.pricing-table__grid tbody tr:last-child td {
    border-bottom: 0;
}

.pricing-table__grid tbody tr:hover td {
    background: #f8fafc;
}

.pricing-table__grid tbody tr.is-selected td {
    background: #eef2ff;
}

.pricing-table__grid th.is-money,
.pricing-table__grid td.is-money {
    width: 9.5rem;
}

.pricing-table__grid th.is-qty,
.pricing-table__grid td.is-qty {
    width: 6.5rem;
}

.pricing-table__grid th.is-check,
.pricing-table__grid td.is-check {
    width: 2.75rem;
    min-width: 2.75rem;
    text-align: center;
    padding-left: 0.75rem;
    padding-right: 0.4rem;
}

.pricing-table__check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.2rem;
    height: 1.2rem;
    padding: 0;
    border: 2px solid #334155 !important;
    border-style: solid !important;
    border-radius: 0.25rem;
    background: #fff !important;
    color: #fff;
    box-shadow: none;
    appearance: none;
    vertical-align: middle;
}

.pricing-table__check.is-on {
    border-color: var(--app-primary, #6366f1) !important;
    background: var(--app-primary, #6366f1) !important;
}

.pricing-table__check:disabled {
    opacity: 0.45;
}

.pricing-table__grid th.is-name,
.pricing-table__grid td.is-name {
    width: 12rem;
    min-width: 10rem;
}

.pricing-table__grid th.is-sku,
.pricing-table__grid td.is-sku {
    width: 10.5rem;
}

.pricing-table__input.is-text {
    text-align: left;
}

.pricing-table__grid th.is-image,
.pricing-table__grid td.is-image,
.pricing-table__grid th.is-action,
.pricing-table__grid td.is-action {
    width: 3.25rem;
    text-align: center;
    padding-left: 0.5rem;
    padding-right: 0.5rem;
}

.pricing-table__grid th.is-money,
.pricing-table__grid th.is-qty {
    text-align: right;
}

.pricing-table__name {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--app-text, #111827);
    word-break: break-word;
}

.pricing-table__input {
    width: 100%;
    min-height: 2.15rem;
    padding: 0.35rem 0.55rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.45rem;
    background: #fff;
    color: var(--app-text, #111827);
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
    text-align: right;
}

.pricing-table__input:focus {
    outline: none;
    border-color: var(--app-primary, #6366f1);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}

.pricing-table__input:disabled {
    background: #f8fafc;
}

.pricing-table__control {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.pricing-table__control .pricing-table__input {
    min-width: 0;
    flex: 1;
}

.pricing-table__apply {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.15rem;
    height: 2.15rem;
    flex-shrink: 0;
    padding: 0;
    border: 1px solid #cbd5e1 !important;
    border-radius: 0.45rem;
    background: #fff !important;
    color: #475569;
}

.pricing-table__apply:hover:not(:disabled) {
    border-color: var(--app-primary, #6366f1) !important;
    background: #eef2ff !important;
    color: #4f46e5;
}

.pricing-table__photo {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.15rem;
    height: 2.15rem;
    margin: 0;
    padding: 0;
    border: 1px dashed #cbd5e1;
    border-radius: 0.45rem;
    background: #f8fafc;
    color: #94a3b8;
    cursor: pointer;
    overflow: hidden;
}

.pricing-table__photo:disabled {
    cursor: not-allowed;
    opacity: 0.65;
}

.pricing-table__photo.has-file {
    border-style: solid;
    background: #fff;
}

.pricing-table__photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.pricing-table__remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.15rem;
    height: 2.15rem;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 0.45rem;
    background: transparent;
    color: #94a3b8;
}

.pricing-table__remove:hover:not(:disabled) {
    background: #fef2f2;
    color: #dc2626;
}

.pricing-table__remove:disabled {
    opacity: 0.35;
}
</style>
