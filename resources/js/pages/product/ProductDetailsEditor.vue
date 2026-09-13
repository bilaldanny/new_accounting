<script setup lang="ts">
    import { Image as ImageIcon, Link2, RefreshCw, Search, Trash2 } from '@lucide/vue';
    import { computed, ref, watch } from 'vue';
    import useCommons from '@/composables/common';
    import { openLfmImagePickerCallback } from '@/utils/openLfmImagePicker';

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
    const isBulkBarOpen = ref(false);
    const bulkPurchase = ref('1500');
    const bulkMargin = ref('20');
    const tableSearch = ref('');
    const currentPage = ref(1);
    const itemsPerPage = 15;

    const isVariable = computed(() => props.productType === 'variable');

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

    const filteredIndexes = computed(() => {
        const query = tableSearch.value.toLowerCase().trim();

        return localDetails.value
            .map((row, index) => ({ row, index }))
            .filter(({ row }) => {
                if (! query) {
                    return true;
                }

                return variationLabel(row.variation_name).toLowerCase().includes(query)
                    || String(row.sku ?? '').toLowerCase().includes(query)
                    || String(row.default_purchase_price).includes(query)
                    || String(row.default_sell_price).includes(query);
            })
            .map(({ index }) => index);
    });

    const totalPages = computed(() => Math.ceil(filteredIndexes.value.length / itemsPerPage) || 1);

    const paginatedIndexes = computed(() => {
        const start = (currentPage.value - 1) * itemsPerPage;

        return filteredIndexes.value.slice(start, start + itemsPerPage);
    });

    const visibleSelected = computed(() => (
        paginatedIndexes.value.length > 0
        && paginatedIndexes.value.every((index) => selectedIndexes.value.includes(index))
    ));

    watch(filteredIndexes, () => {
        if (currentPage.value > totalPages.value) {
            currentPage.value = totalPages.value;
        }
    });

    function handleSelectAllVisible(checked: boolean) {
        if (checked) {
            selectedIndexes.value = [...new Set([...selectedIndexes.value, ...paginatedIndexes.value])];

            return;
        }

        selectedIndexes.value = selectedIndexes.value.filter((index) => ! paginatedIndexes.value.includes(index));
    }

    function toggleBulkBar() {
        isBulkBarOpen.value = ! isBulkBarOpen.value;
    }

    function applyBulkPricing() {
        const purchase = parseFloat(bulkPurchase.value) || 0;
        const margin = parseFloat(bulkMargin.value) || 0;
        const sell = (purchase + ((purchase * margin) / 100)).toFixed(2);

        updateDetails(localDetails.value.map((row, index) => {
            const isTarget = selectedIndexes.value.length === 0 || selectedIndexes.value.includes(index);

            if (! isTarget) {
                return row;
            }

            return {
                ...row,
                default_purchase_price: purchase,
                profit_percent: margin,
                default_sell_price: sell,
            };
        }));
    }

    const bulkSellPrice = computed(() => Math.round(
        (parseFloat(bulkPurchase.value) || 0) * (1 + ((parseFloat(bulkMargin.value) || 0) / 100)),
    ));

    function copyPurchase(row: ProductDetailRow) {
        bulkPurchase.value = String(row.default_purchase_price ?? '');
        isBulkBarOpen.value = true;
    }

    function copyMargin(row: ProductDetailRow) {
        bulkMargin.value = String(row.profit_percent ?? '');
        isBulkBarOpen.value = true;
    }

    function syncSellPrice(index: number) {
        updateRow(index, {}, true);
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

    defineExpose({
        toggleBulkBar,
        isBulkBarOpen,
    });
</script>

<template>
    <div class="pricing-table" :class="{ 'is-embedded': embedded }">
        <div
            v-if="isVariable && isBulkBarOpen"
            class="flex flex-wrap items-center justify-between gap-4 border-b border-teal-100/80 bg-teal-50/60 p-4 text-xs"
        >
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-slate-700">Set Purchase:</span>
                    <div class="relative">
                        <span class="absolute top-1/2 left-2.5 -translate-y-1/2 font-bold text-slate-400">₨</span>
                        <input
                            v-model="bulkPurchase"
                            type="number"
                            class="w-28 rounded-lg border border-slate-200 bg-white py-1 pr-2 pl-6 font-mono text-xs"
                            :disabled="disabled"
                        >
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-slate-700">Set Margin %:</span>
                    <input
                        v-model="bulkMargin"
                        type="number"
                        class="w-20 rounded-lg border border-slate-200 bg-white px-2 py-1 font-mono text-xs"
                        :disabled="disabled"
                    >
                </div>
                <button
                    type="button"
                    class="rounded-lg bg-teal-700 px-3 py-1 font-bold text-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-colors hover:bg-teal-800"
                    :disabled="disabled"
                    @click="applyBulkPricing"
                >
                    Apply to {{ selectedIndexes.length > 0 ? `${selectedIndexes.length} Selected` : 'All Variants' }}
                </button>
            </div>
            <div class="text-[11px] font-medium text-teal-900">
                Calculated Sell Price: ₨ {{ bulkSellPrice }}
            </div>
        </div>

        <div class="flex flex-col justify-between gap-2 border-b border-slate-100 bg-slate-50/70 px-4 py-2.5 text-xs sm:flex-row sm:items-center sm:px-5">
            <div class="text-slate-600">
                <span class="font-semibold text-slate-800">
                    {{ isVariable ? 'Generated variants:' : 'Product price:' }}
                </span>
                Sell price updates from purchase plus margin.
            </div>
            <div v-if="isVariable" class="relative w-full sm:w-64">
                <Search class="absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="tableSearch"
                    type="text"
                    placeholder="Search variant or SKU..."
                    class="w-full rounded-lg border border-slate-200 bg-white py-1 pr-2.5 pl-8 text-xs text-slate-800 focus:ring-1 focus:ring-teal-500 focus:outline-none"
                    @input="currentPage = 1"
                >
                <button
                    v-if="tableSearch"
                    type="button"
                    class="absolute top-1/2 right-2 -translate-y-1/2 text-[10px] text-slate-400 hover:text-slate-600"
                    @click="tableSearch = ''; currentPage = 1"
                >
                    ✕
                </button>
            </div>
        </div>

        <div v-if="localDetails.length === 0" class="px-4 py-10 text-center text-xs text-slate-400">
            No generated variants yet. Select variations and values, then click Generate Variations.
        </div>

        <div v-else-if="paginatedIndexes.length === 0" class="px-4 py-10 text-center text-xs text-slate-400">
            No variants found matching criteria.
        </div>

        <div v-else class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="select-none border-b border-slate-200 bg-slate-50 text-[11px] font-bold tracking-wider text-slate-600 uppercase">
                        <th v-if="isVariable" class="w-10 px-3 py-2.5 text-center">
                            <input
                                type="checkbox"
                                class="rounded text-teal-600 focus:ring-teal-500"
                                :checked="visibleSelected"
                                :disabled="disabled"
                                @change="handleSelectAllVisible(($event.target as HTMLInputElement).checked)"
                            >
                        </th>
                        <th v-if="isVariable" class="min-w-[200px] px-3 py-2.5">Variation</th>
                        <th v-if="isVariable" class="min-w-[170px] px-3 py-2.5">SKU</th>
                        <th class="w-28 px-3 py-2.5">Purchase</th>
                        <th class="w-20 px-3 py-2.5">Large</th>
                        <th class="w-20 px-3 py-2.5">Small</th>
                        <th class="w-24 px-3 py-2.5">Margin %</th>
                        <th class="w-28 px-3 py-2.5">Sell price</th>
                        <th class="w-16 px-3 py-2.5 text-center">Image</th>
                        <th v-if="isVariable" class="w-12 px-3 py-2.5 text-center"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-xs">
                    <tr
                        v-for="index in paginatedIndexes"
                        :key="localDetails[index]?.id ?? index"
                        class="transition-colors hover:bg-teal-50/20"
                        :class="selectedIndexes.includes(index) ? 'bg-teal-50/40' : 'bg-white'"
                    >
                        <td v-if="isVariable" class="px-3 py-2.5 text-center">
                            <input
                                type="checkbox"
                                class="rounded text-teal-600 focus:ring-teal-500"
                                :checked="selectedIndexes.includes(index)"
                                :disabled="disabled"
                                @change="toggleSelect(index, ($event.target as HTMLInputElement).checked)"
                            >
                        </td>
                        <td v-if="isVariable" class="px-3 py-2.5 whitespace-nowrap font-semibold text-slate-900">
                            {{ variationLabel(localDetails[index].variation_name) }}
                        </td>
                        <td v-if="isVariable" class="px-3 py-2.5 whitespace-nowrap">
                            <input
                                type="text"
                                class="w-full rounded border border-slate-200 bg-slate-50 px-2 py-1 font-mono text-xs focus:border-teal-600 focus:bg-white focus:outline-none"
                                placeholder="Auto on save"
                                :value="localDetails[index].sku"
                                :disabled="disabled"
                                @input="updateRow(index, { sku: ($event.target as HTMLInputElement).value })"
                            >
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap">
                            <div class="flex items-center gap-1">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-20 rounded border border-slate-200 bg-white px-2 py-1 text-right font-mono text-xs focus:border-teal-600 focus:outline-none"
                                    :value="localDetails[index].default_purchase_price"
                                    :disabled="disabled"
                                    @input="updateRow(index, { default_purchase_price: ($event.target as HTMLInputElement).value }, true)"
                                >
                                <button
                                    v-if="isVariable"
                                    type="button"
                                    class="p-1 text-slate-400 hover:text-teal-700"
                                    title="Copy to bulk applicator"
                                    :disabled="disabled"
                                    @click="copyPurchase(localDetails[index])"
                                >
                                    <Link2 class="h-3 w-3" />
                                </button>
                            </div>
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap">
                            <input
                                type="number"
                                min="0"
                                class="w-14 rounded border border-slate-200 bg-white px-2 py-1 text-center font-mono text-xs focus:border-teal-600 focus:outline-none"
                                :value="localDetails[index].largequantity"
                                :disabled="disabled"
                                @input="updateRow(index, { largequantity: ($event.target as HTMLInputElement).value })"
                            >
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap">
                            <input
                                type="number"
                                min="0"
                                class="w-14 rounded border border-slate-200 bg-white px-2 py-1 text-center font-mono text-xs focus:border-teal-600 focus:outline-none"
                                :value="localDetails[index].smallquantity"
                                :disabled="disabled"
                                @input="updateRow(index, { smallquantity: ($event.target as HTMLInputElement).value })"
                            >
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap">
                            <div class="flex items-center gap-1">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-16 rounded border border-slate-200 bg-white px-2 py-1 text-right font-mono text-xs focus:border-teal-600 focus:outline-none"
                                    :value="localDetails[index].profit_percent"
                                    :disabled="disabled"
                                    @input="updateRow(index, { profit_percent: ($event.target as HTMLInputElement).value }, true)"
                                >
                                <button
                                    v-if="isVariable"
                                    type="button"
                                    class="p-1 text-slate-400 hover:text-teal-700"
                                    title="Copy to bulk applicator"
                                    :disabled="disabled"
                                    @click="copyMargin(localDetails[index])"
                                >
                                    <Link2 class="h-3 w-3" />
                                </button>
                            </div>
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap">
                            <div class="flex items-center gap-1">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-20 rounded border border-teal-200/80 bg-teal-50/40 px-2 py-1 text-right font-mono text-xs font-bold text-teal-900 focus:border-teal-600 focus:outline-none"
                                    :value="localDetails[index].default_sell_price"
                                    :disabled="disabled"
                                    @input="updateRow(index, { default_sell_price: ($event.target as HTMLInputElement).value })"
                                >
                                <button
                                    type="button"
                                    class="p-1 text-teal-600 hover:text-teal-800"
                                    title="Recalculate sell price"
                                    :disabled="disabled"
                                    @click="syncSellPrice(index)"
                                >
                                    <RefreshCw class="h-3 w-3" />
                                </button>
                            </div>
                        </td>
                        <td class="px-3 py-2.5 text-center">
                            <button
                                type="button"
                                class="rounded p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
                                title="Assign image"
                                :disabled="disabled"
                                @click="chooseVariationImage($event, index)"
                            >
                                <img
                                    v-if="localDetails[index].variation_image"
                                    :src="resolveMediaUrl(localDetails[index].variation_image)"
                                    alt=""
                                    class="h-6 w-6 rounded object-cover"
                                >
                                <ImageIcon v-else class="h-3.5 w-3.5" />
                            </button>
                        </td>
                        <td v-if="isVariable" class="px-3 py-2.5 text-center">
                            <button
                                type="button"
                                class="cursor-pointer rounded p-1 text-slate-400 transition-colors hover:bg-rose-50 hover:text-rose-600"
                                title="Delete variant"
                                :disabled="disabled || localDetails.length <= 1"
                                @click="removeRow(index)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="isVariable && localDetails.length > 0"
            class="flex flex-col justify-between gap-3 border-t border-slate-100 p-4 text-xs text-slate-500 sm:flex-row sm:items-center"
        >
            <div>
                Showing
                <strong class="text-slate-800">
                    {{ filteredIndexes.length === 0 ? 0 : ((currentPage - 1) * itemsPerPage) + 1 }}
                </strong>
                to
                <strong class="text-slate-800">
                    {{ Math.min(currentPage * itemsPerPage, filteredIndexes.length) }}
                </strong>
                of <strong class="text-slate-800">{{ filteredIndexes.length }}</strong> variants
            </div>
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="rounded border border-slate-200 bg-white px-2.5 py-1 font-semibold text-slate-700 hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-40"
                    :disabled="currentPage === 1"
                    @click="currentPage = Math.max(currentPage - 1, 1)"
                >
                    Previous
                </button>
                <span class="px-2 font-mono font-medium text-slate-700">
                    Page {{ currentPage }} of {{ totalPages }}
                </span>
                <button
                    type="button"
                    class="rounded border border-slate-200 bg-white px-2.5 py-1 font-semibold text-slate-700 hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-40"
                    :disabled="currentPage === totalPages"
                    @click="currentPage = Math.min(currentPage + 1, totalPages)"
                >
                    Next
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.pricing-table.is-embedded {
    border: 0;
    border-radius: 0;
    background: transparent;
    overflow: visible;
}

.pricing-table:not(.is-embedded) {
    overflow: hidden;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    background: #fff;
}
</style>
