<script setup lang="ts">
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
    });

    const emit = defineEmits<{
        'update:details': [ProductDetailRow[]];
    }>();

    const localDetails = ref<ProductDetailRow[]>([]);
    const selectedIndexes = ref<number[]>([]);

    const isVariable = computed(() => props.productType === 'variable');

    watch(
        () => props.details,
        (details) => {
            if (Array.isArray(details) && details.length > 0) {
                localDetails.value = details.map((item) => ({ ...item }));
                selectedIndexes.value = selectedIndexes.value.filter((index) => index < localDetails.value.length);

                return;
            }

            localDetails.value = [];
            selectedIndexes.value = [];
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

    function updateDetails(nextDetails: ProductDetailRow[]) {
        localDetails.value = nextDetails;
        emit('update:details', nextDetails.map((item) => ({ ...item })));
    }

    function updateRow(index: number, patch: Partial<ProductDetailRow>, recalculate = false) {
        const nextDetails = localDetails.value.map((item, itemIndex) => {
            if (itemIndex !== index) {
                return item;
            }

            const nextRow = { ...item, ...patch };

            if (recalculate) {
                nextRow.default_sell_price = calculatedSellPrice(nextRow);
            }

            return nextRow;
        });

        updateDetails(nextDetails);
    }

    function applyToAll(index: number, field: 'default_purchase_price' | 'profit_percent' | 'default_sell_price') {
        const source = localDetails.value[index];

        if (! source) {
            return;
        }

        const value = source[field];
        const nextDetails = localDetails.value.map((item) => {
            const nextRow = { ...item, [field]: value };

            if (field !== 'default_sell_price') {
                nextRow.default_sell_price = calculatedSellPrice(nextRow);
            }

            return nextRow;
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
        if (selectedIndexes.value.length === 0) {
            return;
        }

        const remaining = localDetails.value.filter((_, index) => ! selectedIndexes.value.includes(index));
        updateDetails(remaining.length > 0 ? remaining : localDetails.value);
        selectedIndexes.value = [];
    }

    function onImageChange(event: Event, index: number) {
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0];

        if (! file) {
            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            updateRow(index, { variation_image: String(reader.result ?? '') });
        };
        reader.readAsDataURL(file);
    }

    const allSelected = computed(() => (
        isVariable.value
        && localDetails.value.length > 0
        && selectedIndexes.value.length === localDetails.value.length
    ));
</script>

<template>
    <div class="product-details-editor">
        <div class="product-details-editor__header">
            <div>
                <span class="product-details-editor__title">Pricing matrix</span>
                <span class="product-details-editor__hint">
                    Sell price is calculated as purchase plus margin percent. Use apply-all to copy a value across rows.
                </span>
            </div>
            <button
                v-if="isVariable && selectedIndexes.length > 0"
                type="button"
                class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1"
                :disabled="disabled"
                @click="bulkRemove"
            >
                <Trash size="xs" />
                Remove selected
            </button>
        </div>

        <div v-if="localDetails.length === 0" class="product-details-editor__empty">
            No pricing rows yet. Single products show one row automatically. Variable products load after you choose category, item type, and product type.
        </div>

        <div v-else class="table-responsive">
            <table class="table table-sm align-middle mb-0 product-details-editor__table">
                <thead>
                    <tr>
                        <th v-if="isVariable" class="product-details-editor__check">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                :checked="allSelected"
                                :disabled="disabled"
                                @change="toggleSelectAll(($event.target as HTMLInputElement).checked)"
                            >
                        </th>
                        <th>Variation</th>
                        <th>Purchase price</th>
                        <th class="text-center">
                            Packing
                            <div class="product-details-editor__pack-labels">
                                <span>Large</span>
                                <span>Small</span>
                            </div>
                        </th>
                        <th>Margin %</th>
                        <th>Sell price</th>
                        <th>Image</th>
                        <th v-if="isVariable"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(item, index) in localDetails" :key="item.id ?? index">
                        <td v-if="isVariable" class="product-details-editor__check">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                :checked="selectedIndexes.includes(index)"
                                :disabled="disabled"
                                @change="toggleSelect(index, ($event.target as HTMLInputElement).checked)"
                            >
                        </td>
                        <td>
                            <div class="product-details-editor__variation">{{ item.variation_name }}</div>
                        </td>
                        <td>
                            <div class="input-group input-group-sm">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="form-control"
                                    placeholder="0.00"
                                    :value="item.default_purchase_price"
                                    :disabled="disabled"
                                    @input="updateRow(index, { default_purchase_price: ($event.target as HTMLInputElement).value }, true)"
                                >
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    title="Apply purchase price to all rows"
                                    :disabled="disabled"
                                    @click="applyToAll(index, 'default_purchase_price')"
                                >
                                    <Checks size="xs" />
                                </button>
                            </div>
                        </td>
                        <td>
                            <div class="product-details-editor__packing">
                                <input
                                    type="number"
                                    min="0"
                                    class="form-control form-control-sm"
                                    placeholder="Large"
                                    :value="item.largequantity"
                                    :disabled="disabled"
                                    @input="updateRow(index, { largequantity: ($event.target as HTMLInputElement).value })"
                                >
                                <input
                                    type="number"
                                    min="0"
                                    class="form-control form-control-sm"
                                    placeholder="Small"
                                    :value="item.smallquantity"
                                    :disabled="disabled"
                                    @input="updateRow(index, { smallquantity: ($event.target as HTMLInputElement).value })"
                                >
                            </div>
                        </td>
                        <td>
                            <div class="input-group input-group-sm">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="form-control"
                                    placeholder="0"
                                    :value="item.profit_percent"
                                    :disabled="disabled"
                                    @input="updateRow(index, { profit_percent: ($event.target as HTMLInputElement).value }, true)"
                                >
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    title="Apply margin to all rows"
                                    :disabled="disabled"
                                    @click="applyToAll(index, 'profit_percent')"
                                >
                                    <Checks size="xs" />
                                </button>
                            </div>
                        </td>
                        <td>
                            <div class="input-group input-group-sm">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="form-control"
                                    placeholder="0.00"
                                    :value="item.default_sell_price"
                                    :disabled="disabled"
                                    @input="updateRow(index, { default_sell_price: ($event.target as HTMLInputElement).value })"
                                >
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    title="Apply sell price to all rows"
                                    :disabled="disabled"
                                    @click="applyToAll(index, 'default_sell_price')"
                                >
                                    <Checks size="xs" />
                                </button>
                            </div>
                        </td>
                        <td>
                            <label class="product-details-editor__image">
                                <ImagePlus size="xs" />
                                <span>{{ item.variation_image ? 'Change' : 'Upload' }}</span>
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="d-none"
                                    :disabled="disabled"
                                    @change="onImageChange($event, index)"
                                >
                            </label>
                        </td>
                        <td v-if="isVariable" class="text-end">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                title="Remove row"
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
.product-details-editor {
    border: 1px solid var(--bs-border-color, #e9ecef);
    border-radius: 0.75rem;
    background: var(--bs-body-bg, #fff);
    overflow: hidden;
}

.product-details-editor__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--bs-border-color, #e9ecef);
    background: rgb(13 110 253 / 4%);
}

.product-details-editor__title {
    display: block;
    font-weight: 600;
    font-size: 0.875rem;
}

.product-details-editor__hint {
    display: block;
    color: var(--bs-secondary-color, #6c757d);
    font-size: 0.75rem;
    margin-top: 0.125rem;
}

.product-details-editor__empty {
    padding: 1.25rem 1rem;
    color: var(--bs-secondary-color, #6c757d);
    font-size: 0.8125rem;
}

.product-details-editor__table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: #6c757d;
    font-weight: 600;
    white-space: nowrap;
    background: #f8f9fa;
}

.product-details-editor__check {
    width: 2.25rem;
}

.product-details-editor__variation {
    font-weight: 600;
    font-size: 0.8125rem;
}

.product-details-editor__pack-labels {
    display: flex;
    justify-content: space-between;
    font-size: 0.65rem;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 500;
    margin-top: 0.125rem;
}

.product-details-editor__packing {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.375rem;
    min-width: 10rem;
}

.product-details-editor__image {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.5rem;
    border: 1px dashed #0d6efd;
    border-radius: 0.375rem;
    color: #0d6efd;
    font-size: 0.75rem;
    cursor: pointer;
    margin-bottom: 0;
}

.product-details-editor__image:hover {
    background: rgb(13 110 253 / 8%);
}
</style>
