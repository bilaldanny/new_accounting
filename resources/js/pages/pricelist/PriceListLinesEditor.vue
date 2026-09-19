<script setup lang="ts">
    import { Package, Trash } from '@boxicons/vue';
    import { computed, ref } from 'vue';
    import type { PriceListLineRow } from '@/composables/pricelist';

    type ProductSuggestion = {
        id: number | string;
        product_id: number | string;
        brand_id?: number | string;
        product_name?: string;
        name: string;
        sku?: string;
        unit_id: number | string;
        units: PriceListLineRow['units'];
        default_purchase_price?: number | string;
        default_sell_price?: number | string;
        profit_percent?: number | string;
    };

    const props = defineProps({
        lines: {
            type: Array as () => PriceListLineRow[],
            default: () => [],
        },
        suggestions: {
            type: Array as () => ProductSuggestion[],
            default: () => [],
        },
        searching: {
            type: Boolean,
            default: false,
        },
        disabled: {
            type: Boolean,
            default: false,
        },
    });

    const emit = defineEmits<{
        search: [term: string];
        add: [product: ProductSuggestion];
        update: [index: number, patch: Partial<PriceListLineRow>];
        remove: [index: number];
    }>();

    const query = ref('');
    const open = ref(false);

    const hasLines = computed(() => props.lines.length > 0);

    function onSearch(event: Event) {
        query.value = (event.target as HTMLInputElement).value;
        open.value = true;
        emit('search', query.value);
    }

    function choose(product: ProductSuggestion) {
        emit('add', product);
        query.value = '';
        open.value = false;
    }

    function productLabel(product: ProductSuggestion): string {
        return product.product_name || product.name;
    }

    function money(value: number | string | undefined): string {
        return Number(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }
</script>

<template>
    <div class="purchase-lines">
        <div class="purchase-lines__search" :class="{ 'is-disabled': disabled, 'is-open': open && !disabled }">
            <Package size="sm" class="purchase-lines__search-icon" />
            <input
                type="search"
                class="purchase-lines__search-input"
                :placeholder="disabled ? 'Select a brand first' : 'Search name or SKU to add a product'"
                :value="query"
                :disabled="disabled"
                autocomplete="off"
                @input="onSearch"
                @focus="open = true"
                @blur="setTimeout(() => open = false, 180)"
            >
            <span v-if="searching" class="purchase-lines__search-hint">Searching…</span>
        </div>

        <div v-if="open && !disabled && (suggestions.length > 0 || query)" class="purchase-lines__results">
            <button
                v-for="product in suggestions"
                :key="`${product.product_id}-${product.id}`"
                type="button"
                class="purchase-lines__result"
                @mousedown.prevent="choose(product)"
            >
                <span class="purchase-lines__result-copy">
                    <span class="purchase-lines__result-name">{{ productLabel(product) }}</span>
                    <span class="purchase-lines__result-meta">{{ product.sku || 'No SKU' }}</span>
                </span>
                <span class="purchase-lines__result-meta">Sell: {{ money(product.default_sell_price) }}</span>
            </button>
            <div v-if="suggestions.length === 0 && query && !searching" class="purchase-lines__empty-search">
                No matching products
            </div>
        </div>

        <div v-if="!hasLines" class="purchase-lines__empty">
            <Package size="md" class="purchase-lines__empty-icon" />
            <strong>No products priced yet</strong>
            <span>{{ disabled
                ? 'Choose a brand, then add products to set their prices.'
                : 'Search by name or SKU to add the first product.' }}</span>
        </div>

        <div v-else class="pricing-table__wrap purchase-lines__table">
            <table class="pricing-table__grid">
                <thead>
                    <tr>
                        <th class="is-count">#</th>
                        <th class="is-name">Product</th>
                        <th class="is-price">Purchase Price</th>
                        <th class="is-price">Sell Price</th>
                        <th class="is-price">Profit %</th>
                        <th class="is-price">Discount</th>
                        <th class="is-action"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in lines" :key="line.id ?? `${line.variation_id}-${index}`">
                        <td class="is-count">{{ index + 1 }}</td>
                        <td class="is-name">
                            <div class="purchase-lines__product">
                                <span class="pricing-table__name" :title="line.product_name">{{ line.product_name }}</span>
                                <div class="purchase-lines__meta">
                                    <span v-if="line.sku" class="purchase-lines__sku">{{ line.sku }}</span>
                                    <span v-if="line.unit_name" class="purchase-lines__stock-pill">{{ line.unit_name }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="is-price">
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                class="pricing-table__input"
                                :value="line.purchase_price"
                                :disabled="disabled"
                                @input="emit('update', index, { purchase_price: ($event.target as HTMLInputElement).value })"
                            >
                        </td>
                        <td class="is-price">
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                class="pricing-table__input"
                                :value="line.sell_price"
                                :disabled="disabled"
                                @input="emit('update', index, { sell_price: ($event.target as HTMLInputElement).value })"
                            >
                        </td>
                        <td class="is-price">
                            <input
                                type="number"
                                step="0.01"
                                class="pricing-table__input"
                                :value="line.profit_margin"
                                :disabled="disabled"
                                @input="emit('update', index, { profit_margin: ($event.target as HTMLInputElement).value })"
                            >
                        </td>
                        <td class="is-price">
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                class="pricing-table__input"
                                :value="line.discount"
                                :disabled="disabled"
                                @input="emit('update', index, { discount: ($event.target as HTMLInputElement).value })"
                            >
                        </td>
                        <td class="is-action">
                            <button
                                type="button"
                                class="pricing-table__bulk-remove"
                                title="Remove line"
                                :disabled="disabled"
                                @click="emit('remove', index)"
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
.purchase-lines {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.purchase-lines__search {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    min-height: var(--form-control-height);
    padding: 0 0.85rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.65rem;
    background: #fff;
    transition: border-color 150ms ease, box-shadow 150ms ease;
}

.purchase-lines__search.is-open,
.purchase-lines__search:focus-within {
    border-color: var(--app-primary, #0d9488);
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
}

.purchase-lines__search.is-disabled {
    background: #f8fafc;
}

.purchase-lines__search-icon {
    color: #94a3b8;
    flex-shrink: 0;
}

.purchase-lines__search-input {
    flex: 1;
    min-width: 0;
    border: 0;
    background: transparent;
    font-size: 0.875rem;
}

.purchase-lines__search-input:focus {
    outline: none;
}

.purchase-lines__search-hint {
    font-size: 0.75rem;
    color: #64748b;
    white-space: nowrap;
}

.purchase-lines__results {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    max-height: 16rem;
    overflow-y: auto;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.65rem;
    background: #fff;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
}

.purchase-lines__result {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.65rem 0.85rem;
    border: 0;
    border-bottom: 1px solid var(--app-border-subtle, #f1f5f9);
    background: #fff;
    text-align: left;
}

.purchase-lines__result:last-child {
    border-bottom: 0;
}

.purchase-lines__result:hover {
    background: var(--app-primary-soft, #f0fdfa);
}

.purchase-lines__result-copy {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 0.1rem;
}

.purchase-lines__result-name {
    font-size: 0.875rem;
    font-weight: 600;
    color: #111827;
}

.purchase-lines__result-meta {
    font-size: 0.75rem;
    color: #64748b;
}

.purchase-lines__empty,
.purchase-lines__empty-search {
    padding: 1.35rem 1rem;
    text-align: center;
    color: #64748b;
    font-size: 0.8125rem;
    background: #f8fafc;
    border: 1px dashed var(--app-border, #e5e7eb);
    border-radius: 0.65rem;
}

.purchase-lines__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.2rem;
}

.purchase-lines__empty-icon {
    margin-bottom: 0.35rem;
    color: #94a3b8;
}

.purchase-lines__empty strong {
    color: #111827;
}

.purchase-lines__table {
    overflow-x: auto;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.7rem;
}

.pricing-table__grid {
    width: 100%;
    margin: 0;
    border-collapse: collapse;
}

.pricing-table__grid th,
.pricing-table__grid td {
    padding: 0.55rem 0.6rem;
    border-bottom: 1px solid var(--app-border-subtle, #f1f5f9);
    vertical-align: middle;
}

.pricing-table__grid tbody tr:last-child td {
    border-bottom: 0;
}

.pricing-table__grid tbody tr:hover {
    background: #f8fafc;
}

.pricing-table__grid thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: #f8fafc;
    color: #64748b;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
}

.pricing-table__grid th.is-count,
.pricing-table__grid td.is-count {
    width: 2.25rem;
    color: #94a3b8;
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
}

.pricing-table__grid th.is-name,
.pricing-table__grid td.is-name {
    min-width: 13rem;
}

.pricing-table__grid th.is-price,
.pricing-table__grid td.is-price {
    width: 8rem;
}

.pricing-table__grid th.is-action,
.pricing-table__grid td.is-action {
    width: 2.75rem;
    text-align: center;
}

.purchase-lines__product {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    min-width: 0;
}

.pricing-table__name {
    display: block;
    overflow: hidden;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #111827;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.purchase-lines__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
}

.purchase-lines__sku,
.purchase-lines__stock-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.4rem;
    border-radius: 999px;
    font-size: 0.6875rem;
    font-weight: 600;
    line-height: 1.3;
}

.purchase-lines__sku {
    border: 1px solid #e2e8f0;
    border-radius: 0.25rem;
    background: #f1f5f9;
    color: #475569;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 10px;
}

.purchase-lines__stock-pill {
    border: 1px solid rgb(186 230 253 / 0.8);
    border-radius: 0.25rem;
    background: #f0f9ff;
    color: #0369a1;
    font-size: 10px;
    font-weight: 500;
}

.pricing-table__input {
    width: 100%;
    min-height: var(--form-control-height);
    padding: 0.3rem 0.5rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.45rem;
    background: #fff;
    font-size: 0.8125rem;
}

.pricing-table__input:focus {
    outline: none;
    border-color: var(--app-primary, #0d9488);
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
}

.pricing-table__bulk-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border: 1px solid transparent;
    border-radius: 0.45rem;
    background: transparent;
    color: #94a3b8;
}

.pricing-table__bulk-remove:hover:not(:disabled) {
    border-color: #fecaca;
    background: #fef2f2;
    color: #dc2626;
}
</style>
