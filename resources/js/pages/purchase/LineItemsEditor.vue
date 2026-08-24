<script setup lang="ts">
    import { Package, Trash } from '@boxicons/vue';
    import { computed, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';
    import type { PurchaseLineRow } from '@/composables/purchase';

    type CatalogOption = {
        id: number | string;
        text?: string;
        name?: string;
    };

    type ProductSuggestion = {
        id: number | string;
        product_id: number | string;
        itemtype_id?: number | string;
        name: string;
        product_name?: string;
        variation_name?: string;
        sku?: string;
        unit_id: number | string;
        default_purchase_price: number | string;
        default_sell_price: number | string;
        profit_percent?: number | string;
        units: PurchaseLineRow['units'];
    };

    const props = defineProps({
        lines: {
            type: Array as () => PurchaseLineRow[],
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
        allowPackingEdit: {
            type: Boolean,
            default: false,
        },
        currencySymbol: {
            type: String,
            default: 'Rs',
        },
        searchType: {
            type: String,
            default: 'searchbox',
        },
        scopeKey: {
            type: String,
            default: '',
        },
        categories: {
            type: Array as () => CatalogOption[],
            default: () => [],
        },
        subcategories: {
            type: Array as () => CatalogOption[],
            default: () => [],
        },
        itemTypes: {
            type: Array as () => CatalogOption[],
            default: () => [],
        },
    });

    const emit = defineEmits<{
        search: [term: string];
        cascade: [filters: {
            category_id: string;
            subcategory_id: string;
            itemtype_id: string;
            changed: 'category' | 'subcategory' | 'itemtype';
        }];
        add: [product: ProductSuggestion];
        update: [index: number, patch: Partial<PurchaseLineRow>];
        remove: [index: number];
    }>();

    const query = ref('');
    const open = ref(false);
    const selectedCategoryId = ref('');
    const selectedSubcategoryId = ref('');
    const selectedItemTypeId = ref('');
    const selectedProductId = ref('');
    const selectedVariationId = ref('');
    const page = usePage();

    function resolveSearchType(value: unknown): 'searchbox' | 'selectbox' {
        const normalized = String(value ?? '').toLowerCase().replace(/[\s_-]/g, '');

        return normalized === 'selectbox' ? 'selectbox' : 'searchbox';
    }

    const hasLines = computed(() => props.lines.length > 0);
    const isSearchBox = computed(() => {
        const fromProp = resolveSearchType(props.searchType);
        const fromAuth = resolveSearchType((page.props.auth as { user?: { search_type?: string } } | undefined)?.user?.search_type);

        if (fromProp === 'selectbox' || fromAuth === 'selectbox') {
            return false;
        }

        return true;
    });
    const availableVariations = computed(() => props.suggestions.filter((product) => (
        String(product.product_id) === String(selectedProductId.value)
        && ! props.lines.some((line) => (
            String(line.product_id) === String(product.product_id)
            && String(line.variation_id) === String(product.id)
        ))
    )));

    const availableProducts = computed(() => {
        const seen = new Set<string>();

        return props.suggestions.filter((product) => {
            const key = String(product.product_id);

            if (seen.has(key)) {
                return false;
            }

            seen.add(key);

            return true;
        });
    });

    const subcategoryRequired = computed(() => props.subcategories.length > 0);
    const itemTypeDisabled = computed(() => (
        props.disabled
        || ! selectedCategoryId.value
        || (subcategoryRequired.value && ! selectedSubcategoryId.value)
    ));
    const productDisabled = computed(() => itemTypeDisabled.value || ! selectedItemTypeId.value);
    const variationDisabled = computed(() => productDisabled.value || ! selectedProductId.value);

    function formatAmount(value: unknown): string {
        return Number(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function money(value: unknown): string {
        return `${props.currencySymbol} ${formatAmount(value)}`;
    }

    function packingLabel(line: PurchaseLineRow): string {
        return line.unit_name || line.units?.find((unit) => String(unit.id) === String(line.unit_id))?.short_name || '';
    }

    function onSearch(event: Event) {
        query.value = (event.target as HTMLInputElement).value;
        open.value = true;
        emit('search', query.value);
    }

    function choose(product: ProductSuggestion) {
        emit('add', product);
        query.value = '';
        selectedVariationId.value = '';
        open.value = false;
    }

    function optionLabel(item: CatalogOption): string {
        return item.text || item.name || String(item.id);
    }

    function productLabel(product: ProductSuggestion): string {
        return product.product_name || product.name;
    }

    function variationLabel(product: ProductSuggestion): string {
        const variationName = String(product.variation_name || '').trim();

        if (variationName && variationName.toLowerCase() !== 'dummy') {
            return variationName;
        }

        return product.name || product.sku || 'Default';
    }

    function emitCascade(changed: 'category' | 'subcategory' | 'itemtype') {
        emit('cascade', {
            category_id: selectedCategoryId.value,
            subcategory_id: selectedSubcategoryId.value,
            itemtype_id: selectedItemTypeId.value,
            changed,
        });
    }

    function onCategoryChange(event: Event) {
        selectedCategoryId.value = (event.target as HTMLSelectElement).value;
        selectedSubcategoryId.value = '';
        selectedItemTypeId.value = '';
        selectedProductId.value = '';
        selectedVariationId.value = '';
        emitCascade('category');
    }

    function onSubcategoryChange(event: Event) {
        selectedSubcategoryId.value = (event.target as HTMLSelectElement).value;
        selectedItemTypeId.value = '';
        selectedProductId.value = '';
        selectedVariationId.value = '';
        emitCascade('subcategory');
    }

    function onItemTypeChange(event: Event) {
        selectedItemTypeId.value = (event.target as HTMLSelectElement).value;
        selectedProductId.value = '';
        selectedVariationId.value = '';
        emitCascade('itemtype');
    }

    function onProductChange(event: Event) {
        selectedProductId.value = (event.target as HTMLSelectElement).value;
        selectedVariationId.value = '';
    }

    function onVariationChange(event: Event) {
        selectedVariationId.value = (event.target as HTMLSelectElement).value;
        const product = availableVariations.value.find((item) => String(item.id) === selectedVariationId.value);

        if (product) {
            choose(product);
        }
    }

    function resetCascade() {
        selectedCategoryId.value = '';
        selectedSubcategoryId.value = '';
        selectedItemTypeId.value = '';
        selectedProductId.value = '';
        selectedVariationId.value = '';
    }

    watch(() => props.scopeKey, resetCascade);

    function changeUnit(index: number, unitId: string) {
        const line = props.lines[index];
        const unit = line?.units?.find((item) => String(item.id) === String(unitId));

        emit('update', index, {
            unit_id: unitId,
            packing_qty: unit?.packing_qty ?? line?.packing_qty ?? 1,
            current_stock: unit?.unit_qty ?? line?.current_stock ?? 0,
            unit_name: unit?.short_name ?? unit?.text ?? line?.unit_name,
        });
    }
</script>

<template>
    <div class="purchase-lines">
        <div v-if="isSearchBox" class="purchase-lines__search" :class="{ 'is-disabled': disabled, 'is-open': open && !disabled }">
            <Package size="sm" class="purchase-lines__search-icon" />
            <input
                type="search"
                class="purchase-lines__search-input"
                :placeholder="disabled ? 'Select company and branch first' : 'Search name, SKU, or barcode to add a product'"
                :value="query"
                :disabled="disabled"
                autocomplete="off"
                @input="onSearch"
                @focus="open = true"
                @blur="setTimeout(() => open = false, 180)"
            >
            <span v-if="searching" class="purchase-lines__search-hint">Searching…</span>
        </div>

        <div v-else class="purchase-lines__cascade" :class="{ 'is-disabled': disabled }">
            <label class="purchase-lines__field">
                <span>Category</span>
                <select
                    class="purchase-lines__select-input"
                    :value="selectedCategoryId"
                    :disabled="disabled"
                    @change="onCategoryChange"
                >
                    <option value="">{{ disabled ? 'Select company and branch first' : 'Select category' }}</option>
                    <option v-for="category in categories" :key="category.id" :value="String(category.id)">
                        {{ optionLabel(category) }}
                    </option>
                </select>
            </label>

            <label class="purchase-lines__field">
                <span>Sub category</span>
                <select
                    class="purchase-lines__select-input"
                    :value="selectedSubcategoryId"
                    :disabled="disabled || !selectedCategoryId"
                    @change="onSubcategoryChange"
                >
                    <option value="">{{ selectedCategoryId ? (subcategories.length ? 'Select subcategory' : 'No subcategory') : 'Select category first' }}</option>
                    <option v-for="subcategory in subcategories" :key="subcategory.id" :value="String(subcategory.id)">
                        {{ optionLabel(subcategory) }}
                    </option>
                </select>
            </label>

            <label class="purchase-lines__field">
                <span>Item type</span>
                <select
                    class="purchase-lines__select-input"
                    :value="selectedItemTypeId"
                    :disabled="itemTypeDisabled"
                    @change="onItemTypeChange"
                >
                    <option value="">{{ itemTypeDisabled ? 'Select previous first' : 'Select item type' }}</option>
                    <option v-for="itemType in itemTypes" :key="itemType.id" :value="String(itemType.id)">
                        {{ optionLabel(itemType) }}
                    </option>
                </select>
            </label>

            <label class="purchase-lines__field">
                <span>Product</span>
                <select
                    class="purchase-lines__select-input"
                    :value="selectedProductId"
                    :disabled="productDisabled"
                    @change="onProductChange"
                >
                    <option value="">{{ productDisabled ? 'Select previous first' : searching ? 'Loading products…' : 'Select product' }}</option>
                    <option v-for="product in availableProducts" :key="product.product_id" :value="String(product.product_id)">
                        {{ productLabel(product) }}
                    </option>
                </select>
            </label>

            <label class="purchase-lines__field">
                <span>Variation</span>
                <select
                    class="purchase-lines__select-input"
                    :value="selectedVariationId"
                    :disabled="variationDisabled"
                    @change="onVariationChange"
                >
                    <option value="">{{ variationDisabled ? 'Select product first' : 'Select variation' }}</option>
                    <option v-for="variation in availableVariations" :key="variation.id" :value="String(variation.id)">
                        {{ variationLabel(variation) }}
                    </option>
                </select>
            </label>
        </div>

        <div v-if="isSearchBox && open && !disabled && (suggestions.length > 0 || query)" class="purchase-lines__results">
            <button
                v-for="product in suggestions"
                :key="`${product.product_id}-${product.id}`"
                type="button"
                class="purchase-lines__result"
                @mousedown.prevent="choose(product)"
            >
                <span class="purchase-lines__result-copy">
                    <span class="purchase-lines__result-name">{{ product.name }}</span>
                    <span class="purchase-lines__result-meta">{{ product.sku || 'No SKU' }}</span>
                </span>
                <span class="purchase-lines__result-price">{{ money(product.default_purchase_price) }}</span>
            </button>
            <div v-if="suggestions.length === 0 && query && !searching" class="purchase-lines__empty-search">
                No matching products
            </div>
        </div>

        <div v-if="!hasLines" class="purchase-lines__empty">
            <Package size="md" class="purchase-lines__empty-icon" />
            <strong>No line items yet</strong>
            <span>{{ disabled
                ? 'Choose a company and branch, then add products.'
                : isSearchBox
                    ? 'Search by name or SKU to add the first product.'
                    : 'Select category, subcategory, item type, product, then variation.' }}</span>
        </div>

        <div v-else class="pricing-table__wrap purchase-lines__table">
            <table class="pricing-table__grid">
                <thead>
                    <tr>
                        <th class="is-count">#</th>
                        <th class="is-name">Product</th>
                        <th class="is-qty">Qty / unit</th>
                        <th class="is-pack">Packing</th>
                        <th class="is-money">Unit cost</th>
                        <th class="is-money">Discount</th>
                        <th class="is-money">Net cost</th>
                        <th class="is-money">Line total</th>
                        <th class="is-money">Margin</th>
                        <th class="is-money">Sell price</th>
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
                                    <span class="purchase-lines__stock-pill">
                                        {{ line.current_stock ?? 0 }} {{ packingLabel(line) }} in stock
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="is-qty">
                            <div class="purchase-lines__control is-qty">
                                <input
                                    type="number"
                                    min="1"
                                    class="purchase-lines__qty-input"
                                    :value="line.quantity"
                                    :disabled="disabled"
                                    @input="emit('update', index, { quantity: ($event.target as HTMLInputElement).value })"
                                >
                                <select
                                    class="purchase-lines__unit"
                                    :value="line.unit_id"
                                    :disabled="disabled"
                                    @change="changeUnit(index, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option v-for="unit in line.units" :key="unit.id" :value="unit.id">
                                        {{ unit.short_name ?? unit.text }}
                                    </option>
                                </select>
                            </div>
                        </td>
                        <td class="is-pack">
                            <input
                                v-if="allowPackingEdit"
                                type="number"
                                min="0"
                                class="pricing-table__input"
                                :value="line.packing_qty"
                                :disabled="disabled"
                                @input="emit('update', index, { packing_qty: ($event.target as HTMLInputElement).value })"
                            >
                            <span v-else class="purchase-lines__packing">{{ line.packing_qty }}</span>
                        </td>
                        <td class="is-money">
                            <div class="purchase-lines__control is-money">
                                <span class="purchase-lines__prefix">{{ currencySymbol }}</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :value="line.pp_without_discount"
                                    :disabled="disabled"
                                    @input="emit('update', index, { pp_without_discount: ($event.target as HTMLInputElement).value })"
                                >
                            </div>
                        </td>
                        <td class="is-money">
                            <div class="purchase-lines__control is-money">
                                <span class="purchase-lines__prefix">{{ currencySymbol }}</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :value="line.discount_percent"
                                    :disabled="disabled"
                                    @input="emit('update', index, { discount_percent: ($event.target as HTMLInputElement).value })"
                                >
                            </div>
                        </td>
                        <td class="is-money">
                            <span class="purchase-lines__amount">
                                <small>{{ currencySymbol }}</small>
                                {{ formatAmount(line.purchase_price) }}
                            </span>
                        </td>
                        <td class="is-money">
                            <span class="purchase-lines__amount is-strong">
                                <small>{{ currencySymbol }}</small>
                                {{ formatAmount(line.row_subtotal) }}
                            </span>
                        </td>
                        <td class="is-money">
                            <div class="purchase-lines__control is-money">
                                <span class="purchase-lines__prefix">{{ currencySymbol }}</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :value="line.profit_percent"
                                    :disabled="disabled"
                                    @input="emit('update', index, { profit_percent: ($event.target as HTMLInputElement).value })"
                                >
                            </div>
                        </td>
                        <td class="is-money">
                            <div class="purchase-lines__control is-money">
                                <span class="purchase-lines__prefix">{{ currencySymbol }}</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :value="line.default_sell_price"
                                    :disabled="disabled"
                                    @input="emit('update', index, { default_sell_price: ($event.target as HTMLInputElement).value })"
                                >
                            </div>
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
    gap: 0.7rem;
}

.purchase-lines__search {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    min-height: 2.65rem;
    padding: 0 0.85rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.65rem;
    background: #fff;
    transition: border-color 150ms ease, box-shadow 150ms ease;
}

.purchase-lines__search.is-open,
.purchase-lines__search:focus-within {
    border-color: var(--app-primary, #6366f1);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}

.purchase-lines__search.is-disabled {
    background: #f8fafc;
}

.purchase-lines__cascade {
    display: grid;
    grid-template-columns: repeat(5, minmax(9.5rem, 1fr));
    gap: 0.65rem;
}

.purchase-lines__cascade.is-disabled {
    opacity: 0.72;
}

.purchase-lines__field {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 0.3rem;
}

.purchase-lines__field span {
    color: #475569;
    font-size: 0.75rem;
    font-weight: 600;
}

.purchase-lines__select-input {
    width: 100%;
    min-height: 2.45rem;
    padding: 0.35rem 0.65rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.55rem;
    background: #fff;
    color: #111827;
    font-size: 0.8125rem;
}

.purchase-lines__select-input:focus {
    outline: none;
    border-color: var(--app-primary, #6366f1);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}

@media (max-width: 1100px) {
    .purchase-lines__cascade {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
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
    background: #eef2ff;
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

.purchase-lines__result-price {
    flex-shrink: 0;
    font-size: 0.8125rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: #334155;
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

.pricing-table__grid th.is-qty,
.pricing-table__grid td.is-qty {
    width: 10.5rem;
}

.pricing-table__grid th.is-pack,
.pricing-table__grid td.is-pack {
    width: 5.25rem;
}

.pricing-table__grid th.is-money,
.pricing-table__grid td.is-money {
    width: 7.25rem;
    text-align: right;
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
    background: #eef2ff;
    color: #4338ca;
}

.purchase-lines__stock-pill {
    background: #f1f5f9;
    color: #64748b;
}

.purchase-lines__control {
    display: flex;
    align-items: stretch;
    overflow: hidden;
    min-height: 2.05rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.45rem;
    background: #fff;
}

.purchase-lines__control:focus-within {
    border-color: var(--app-primary, #6366f1);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}

.purchase-lines__control input,
.purchase-lines__control select {
    min-width: 0;
    border: 0;
    background: transparent;
    font-size: 0.8125rem;
}

.purchase-lines__control input:focus,
.purchase-lines__control select:focus {
    outline: none;
}

.purchase-lines__control.is-qty .purchase-lines__qty-input {
    width: 3.6rem;
    padding: 0.3rem 0.45rem;
}

.purchase-lines__control.is-qty .purchase-lines__unit {
    flex: 1;
    min-width: 4.5rem;
    padding: 0.3rem 0.4rem;
    border-left: 1px solid var(--app-border-subtle, #f1f5f9);
    color: #475569;
    background: #f8fafc;
}

.purchase-lines__control.is-money {
    justify-content: flex-end;
}

.purchase-lines__prefix {
    display: inline-flex;
    align-items: center;
    padding: 0 0.4rem;
    color: #94a3b8;
    font-size: 0.6875rem;
    font-weight: 700;
    background: #f8fafc;
    border-right: 1px solid var(--app-border-subtle, #f1f5f9);
}

.purchase-lines__control.is-money input {
    width: 100%;
    padding: 0.3rem 0.45rem;
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.pricing-table__input {
    width: 100%;
    min-height: 2.05rem;
    padding: 0.3rem 0.5rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.45rem;
    background: #fff;
    font-size: 0.8125rem;
}

.pricing-table__input:focus {
    outline: none;
    border-color: var(--app-primary, #6366f1);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}

.purchase-lines__packing,
.purchase-lines__amount {
    display: block;
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
    color: #334155;
}

.purchase-lines__amount {
    display: inline-flex;
    align-items: baseline;
    justify-content: flex-end;
    gap: 0.25rem;
    width: 100%;
}

.purchase-lines__amount small {
    color: #94a3b8;
    font-size: 0.6875rem;
    font-weight: 700;
}

.purchase-lines__amount.is-strong {
    font-weight: 700;
    color: #111827;
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
