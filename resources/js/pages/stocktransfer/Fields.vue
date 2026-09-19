<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { ArrowLeftRight, Boxes, CalendarDays } from '@lucide/vue';
    import { computed, onMounted, ref, watch } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import type { StockTransferLineRow } from '@/composables/stocktransfer';
    import LineItemsEditor from './LineItemsEditor.vue';

    const params = defineProps({
        type: String,
        recordId: {
            type: Number,
            default: null,
        },
        formData: {
            type: Object,
            default: () => ({}),
        },
        formRef: {
            type: Object,
            default: null,
        },
    });

    const page = usePage();
    const colQuarter = { container: 3, label: 12, wrapper: 12 };
    const colThird = { container: 4, label: 12, wrapper: 12 };
    const colHalf = { container: 6, label: 12, wrapper: 12 };
    const colFull = { container: 12, label: 12, wrapper: 12 };
    const cardClasses = {
        ElementLayout: {
            container: 'product-form-card',
        },
        GroupElement: {
            wrapper: 'product-form-card__body',
        },
    };
    const linesCardClasses = {
        ElementLayout: {
            container: 'product-form-card purchase-form-card--lines',
        },
        GroupElement: {
            wrapper: 'product-form-card__body journal-form-card__body--flush',
        },
    };

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
        search_type?: string | null;
    } | null);

    function resolveSearchType(value: unknown): 'searchbox' | 'selectbox' {
        const normalized = String(value ?? '').toLowerCase().replace(/[\s_-]/g, '');

        return normalized === 'selectbox' ? 'selectbox' : 'searchbox';
    }

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const canManageBranch = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showBranchField = computed(() => canManageBranch.value && branchesdata.value.length > 1);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const showHiddenBranchField = computed(() => ! showBranchField.value);

    const {
        fetchCompany,
        fetchBranch,
        fetchCategory,
        fetchSubCategory,
        fetchItemType,
        companiesdata,
        branchesdata,
        categoriesdata,
        subcategoriesdata,
        itemtypesdata,
    } = useCommons();

    const productSuggestions = ref<any[]>([]);
    const searchingProducts = ref(false);
    const lastFetchedCompanyId = ref('');
    const allowPackingEdit = ref(false);
    const searchType = ref<'searchbox' | 'selectbox'>(resolveSearchType(authUser.value?.search_type));
    const searchTimer = ref<ReturnType<typeof setTimeout> | null>(null);
    const isSearchBox = computed(() => searchType.value === 'searchbox');

    const statusItems = [
        { value: 'pending', label: 'Pending' },
        { value: 'in_transit', label: 'In transit' },
        { value: 'completed', label: 'Completed' },
    ];

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedBranchId = computed(() => params.formData?.branch_id ?? '');
    const scopeReady = computed(() => Boolean(normalizeId(selectedCompanyId.value) && normalizeId(selectedBranchId.value)));
    const branchDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);
    const toBranchOptions = computed(() => (
        branchesdata.value.filter((branch: { id: number | string }) => normalizeId(branch.id) !== normalizeId(selectedBranchId.value))
    ));
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const transferLines = computed<StockTransferLineRow[]>(() => (
        Array.isArray(params.formData?.purchaselines) ? params.formData.purchaselines : []
    ));
    const itemCountLabel = computed(() => {
        const count = transferLines.value.length;

        return count === 1 ? '1 item' : `${count} items`;
    });
    const totalQuantity = computed(() => (
        transferLines.value.reduce((sum, line) => sum + toNumber(line.quantity), 0)
    ));

    function toNumber(value: unknown, fallback = 0): number {
        const amount = Number(value);

        return Number.isFinite(amount) ? amount : fallback;
    }

    function persist(patch: Record<string, unknown>) {
        if (params.formData) {
            Object.assign(params.formData, patch);
        }

        params.formRef?.update?.(patch);
    }

    function persistLines(lines: StockTransferLineRow[]) {
        const next = lines.map((line) => ({ ...line }));
        persist({ purchaselines: next, total_item: next.length });
    }

    function applyScopedDefaults() {
        if (isSuperadmin.value) {
            return;
        }

        const updates: Record<string, string | number> = {};

        if (authUser.value?.company_id) {
            updates.company_id = authUser.value.company_id;
        }

        if (! isCompanyadmin.value && authUser.value?.branch_id) {
            updates.branch_id = authUser.value.branch_id;
        }

        if (Object.keys(updates).length > 0) {
            persist(updates);
        }
    }

    async function loadCompanySettings(companyId: string | number | null | undefined) {
        const normalizedCompanyId = normalizeId(companyId);

        if (! normalizedCompanyId) {
            allowPackingEdit.value = false;

            if (isSuperadmin.value) {
                searchType.value = 'searchbox';
            }

            return;
        }

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.companySettings}/${normalizedCompanyId}`);
            const setting = response.data?.companySetting ?? response.data;
            allowPackingEdit.value = Boolean(setting?.update_packing_qty);
            searchType.value = resolveSearchType(setting?.search_type ?? authUser.value?.search_type);
        } catch {
            allowPackingEdit.value = false;
            searchType.value = resolveSearchType(authUser.value?.search_type);
        }
    }

    async function loadBranchOptions(companyId: string | number | null | undefined) {
        const normalizedCompanyId = normalizeId(companyId);

        if (! canManageBranch.value) {
            return;
        }

        if (! normalizedCompanyId) {
            branchesdata.value = [];

            return;
        }

        if (normalizedCompanyId === lastFetchedCompanyId.value) {
            return;
        }

        lastFetchedCompanyId.value = normalizedCompanyId;
        await fetchBranch(normalizedCompanyId);

        if (branchesdata.value.length === 1) {
            persist({ branch_id: branchesdata.value[0].id });
        }
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        persist({
            branch_id: '',
            tobranch_id: '',
            purchaselines: [],
            total_item: 0,
        });
        lastFetchedCompanyId.value = '';
        productSuggestions.value = [];
        await loadBranchOptions(companyId);
    }

    async function handleBranchChange() {
        if (normalizeId(params.formData?.tobranch_id) === normalizeId(selectedBranchId.value)) {
            persist({ tobranch_id: '' });
        }

        await loadProductOptions();
    }

    async function fetchProductSuggestions(term = '', filters: {
        category_id?: string | number;
        subcategory_id?: string | number;
        itemtype_id?: string | number;
        product_id?: string | number;
    } = {}) {
        if (! scopeReady.value) {
            productSuggestions.value = [];

            return;
        }

        searchingProducts.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.stockTransferSearchProducts, {
                params: {
                    company_id: selectedCompanyId.value,
                    branch_id: selectedBranchId.value,
                    search: term,
                    ...(normalizeId(filters.category_id) ? { category_id: filters.category_id } : {}),
                    ...(normalizeId(filters.subcategory_id) ? { subcategory_id: filters.subcategory_id } : {}),
                    ...(normalizeId(filters.itemtype_id) ? { itemtype_id: filters.itemtype_id } : {}),
                    ...(normalizeId(filters.product_id) ? { product_id: filters.product_id } : {}),
                },
            });
            productSuggestions.value = response.data ?? [];
        } catch {
            productSuggestions.value = [];
        } finally {
            searchingProducts.value = false;
        }
    }

    async function loadCatalogFilters() {
        if (! scopeReady.value || isSearchBox.value) {
            return;
        }

        await Promise.all([
            fetchCategory(selectedCompanyId.value),
            fetchItemType(selectedCompanyId.value),
        ]);
    }

    async function loadProductOptions() {
        if (! scopeReady.value || isSearchBox.value) {
            if (! isSearchBox.value) {
                productSuggestions.value = [];
            }

            return;
        }

        await loadCatalogFilters();
    }

    async function handleCascade(filters: {
        category_id: string;
        subcategory_id: string;
        itemtype_id: string;
        changed: 'category' | 'subcategory' | 'itemtype';
    }) {
        if (filters.changed === 'category') {
            await fetchSubCategory(selectedCompanyId.value, filters.category_id || undefined);
            productSuggestions.value = [];

            return;
        }

        if (! filters.category_id || ! filters.itemtype_id) {
            productSuggestions.value = [];

            return;
        }

        if (subcategoriesdata.value.length > 0 && ! filters.subcategory_id) {
            productSuggestions.value = [];

            return;
        }

        await fetchProductSuggestions('', filters);
    }

    async function searchProducts(term: string) {
        if (searchTimer.value) {
            clearTimeout(searchTimer.value);
        }

        if (! scopeReady.value || term.trim() === '') {
            productSuggestions.value = [];

            return;
        }

        searchTimer.value = setTimeout(() => {
            void fetchProductSuggestions(term);
        }, 220);
    }

    function addProduct(product: {
        id: number | string;
        product_id: number | string;
        itemtype_id?: number | string;
        name: string;
        sku?: string;
        unit_id: number | string;
        units: StockTransferLineRow['units'];
    }) {
        const alreadyAdded = transferLines.value.some((line) => (
            String(line.product_id) === String(product.product_id)
            && String(line.variation_id) === String(product.id)
        ));

        if (alreadyAdded) {
            return;
        }

        const unit = product.units?.[0];
        const nextLine: StockTransferLineRow = {
            product_id: product.product_id,
            variation_id: product.id,
            itemtype_id: product.itemtype_id,
            product_name: product.name,
            sku: product.sku,
            unit_id: product.unit_id,
            quantity: 1,
            packing_qty: unit?.packing_qty ?? 1,
            units: product.units ?? [],
            current_stock: unit?.unit_qty ?? 0,
            unit_name: unit?.short_name ?? unit?.text,
        };

        persistLines([nextLine, ...transferLines.value]);
    }

    function updateLine(index: number, patch: Partial<StockTransferLineRow>) {
        persistLines(transferLines.value.map((line, lineIndex) => (
            lineIndex === index ? { ...line, ...patch } : line
        )));
    }

    function removeLine(index: number) {
        persistLines(transferLines.value.filter((_, lineIndex) => lineIndex !== index));
    }

    onMounted(async () => {
        applyScopedDefaults();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        const companyId = isSuperadmin.value ? selectedCompanyId.value : authUser.value?.company_id;

        if (companyId) {
            await loadBranchOptions(companyId);
        }
    });

    watch(
        () => normalizeId(params.formData?.company_id) || normalizeId(authUser.value?.company_id),
        async (companyId) => {
            await loadCompanySettings(companyId || undefined);
            await loadProductOptions();
        },
        { immediate: true },
    );

    watch(
        () => normalizeId(params.formData?.company_id),
        async (companyId, previousCompanyId) => {
            if (companyId === previousCompanyId) {
                return;
            }

            await handleCompanyChange(companyId || undefined);
        },
    );

    watch(
        () => `${normalizeId(params.formData?.company_id)}:${normalizeId(params.formData?.branch_id)}`,
        async (key, previousKey) => {
            if (key === previousKey) {
                return;
            }

            await handleBranchChange();
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />
    <TextElement v-if="showHiddenBranchField" name="branch_id" hidden="true" />
    <TextElement name="type" hidden="true" default="transfer" />
    <TextElement name="total_item" hidden="true" />

    <GroupElement name="group_details" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_details" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <ArrowLeftRight class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Transfer details</h2>
                    <p class="product-form-section-copy">Source branch, destination branch, and reference</p>
                </div>
            </div>
        </StaticElement>

        <SelectElement
            v-if="showCompanyField"
            name="company_id"
            :native="false"
            :items="companiesdata"
            id="CompanyId"
            field-name="CompanyId"
            placeholder="Select company"
            label="Company"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :rules="companyRules"
        />

        <SelectElement
            v-if="showBranchField"
            name="branch_id"
            :native="false"
            :items="branchesdata"
            id="BranchId"
            field-name="BranchId"
            placeholder="Select source branch"
            label="From branch"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :disabled="branchDisabled"
            rules="required"
            info="Stock is deducted from this branch."
        />

        <SelectElement
            name="tobranch_id"
            :native="false"
            :items="toBranchOptions"
            id="ToBranchId"
            field-name="ToBranchId"
            placeholder="Select destination branch"
            label="To branch"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :disabled="! selectedBranchId"
            rules="required"
            info="Stock is added to this branch."
        />

        <TextElement
            id="TransferRefNo"
            field-name="TransferRefNo"
            name="invoice_no"
            label="Reference no"
            placeholder="Auto-generated if blank"
            :columns="colThird"
            autocomplete="off"
            info="Leave empty to generate from company stock transfer prefix."
        />

        <DateElement
            id="TransferDate"
            field-name="TransferDate"
            name="transaction_date"
            label="Transfer date"
            placeholder="Select transfer date"
            :columns="colThird"
            :floating="false"
            rules="required"
        />

        <SelectElement
            name="status"
            :native="false"
            :items="statusItems"
            id="TransferStatus"
            field-name="TransferStatus"
            placeholder="Select status"
            label="Status"
            :columns="colThird"
            label-prop="label"
            value-prop="value"
            :search="false"
            :floating="false"
            :can-clear="false"
            info="Completed moves stock out of the source and into the destination branch."
        />
    </GroupElement>

    <GroupElement name="group_items" :columns="colFull" :add-classes="linesCardClasses">
        <StaticElement name="section_items" :columns="colFull">
            <div class="product-form-section-head journal-card-head">
                <div class="journal-card-head__lead">
                    <span class="product-form-section-icon">
                        <Boxes class="h-4 w-4" />
                    </span>
                    <div>
                        <h2 class="product-form-section-title">Line items</h2>
                        <p class="product-form-section-copy">
                            {{ isSearchBox
                                ? 'Search products, then set the quantity to transfer'
                                : 'Select category, subcategory, item type, product, then variation' }}
                        </p>
                    </div>
                </div>
                <span class="purchase-form__count">{{ itemCountLabel }}</span>
            </div>
        </StaticElement>

        <StaticElement name="lines_editor" :columns="colFull">
            <LineItemsEditor
                :lines="transferLines"
                :suggestions="productSuggestions"
                :searching="searchingProducts"
                :disabled="!scopeReady"
                :allow-packing-edit="allowPackingEdit"
                :search-type="searchType"
                :scope-key="`${normalizeId(selectedCompanyId)}:${normalizeId(selectedBranchId)}`"
                :categories="categoriesdata"
                :subcategories="subcategoriesdata"
                :item-types="itemtypesdata"
                @search="searchProducts"
                @cascade="handleCascade"
                @add="addProduct"
                @update="updateLine"
                @remove="removeLine"
            />
        </StaticElement>
    </GroupElement>

    <GroupElement name="group_notes" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_notes" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <CalendarDays class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Notes & summary</h2>
                    <p class="product-form-section-copy">Reason for transfer and a quick totals check</p>
                </div>
            </div>
        </StaticElement>

        <TextareaElement
            id="TransferAdditionalNote"
            field-name="TransferAdditionalNote"
            name="additional_note"
            label="Reason / note"
            placeholder="Why this stock is being moved, courier details, or other remarks"
            :columns="colHalf"
            :rows="4"
        />

        <StaticElement name="transfer_summary" :columns="colHalf">
            <div class="space-y-3.5 rounded-xl border border-slate-200/90 bg-slate-50/70 p-5">
                <div>
                    <span class="mb-1 block text-[10px] font-bold tracking-wider text-slate-400 uppercase">Summary</span>
                    <h3 class="text-sm font-bold text-slate-800">Transfer totals</h3>
                </div>
                <div class="space-y-2.5 text-xs text-slate-600">
                    <div class="flex items-center justify-between">
                        <span>Items</span>
                        <span class="font-semibold text-slate-900">{{ params.formData?.total_item || 0 }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>Total quantity</span>
                        <span class="font-semibold text-slate-900">{{ totalQuantity }}</span>
                    </div>
                </div>
            </div>
        </StaticElement>
    </GroupElement>
</template>
