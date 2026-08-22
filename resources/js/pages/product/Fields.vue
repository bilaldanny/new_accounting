<script setup lang="ts">
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import { openLfmImagePicker } from '@/utils/openLfmImagePicker';
    import { computed, onMounted, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';
    import { Box, ImagePlus, Layers, Package, SliderAlt, Store } from '@boxicons/vue';
    import {
        inferSelections,
        normalizeVariantLabel,
        type ApplicableVariation,
        type VariationSelection,
    } from '@/utils/variantCombiner';
    import ProductDetailsEditor, { type ProductDetailRow } from './ProductDetailsEditor.vue';
    import VariationPicker from './VariationPicker.vue';

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
        logoUrl: {
            type: String,
            default: '',
        },
    });

    const page = usePage();

    const colQuarter = { container: 3, label: 12, wrapper: 12 };
    const colThird = { container: 4, label: 12, wrapper: 12 };
    const colHalf = { container: 6, label: 12, wrapper: 12 };
    const colFull = { container: 12, label: 12, wrapper: 12 };

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
    } | null);

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const isEdit = computed(() => params.type === 'edit');

    const {
        fetchCompany,
        fetchCategory,
        fetchSubCategory,
        fetchItemType,
        fetchBrand,
        fetchUnit,
        fetchWarranty,
        fetchVariation,
        companiesdata,
        categoriesdata,
        subcategoriesdata,
        itemtypesdata,
        brandsdata,
        unitsdata,
        warrantiesdata,
        variationsdata,
        appUrl,
    } = useCommons();

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedCategoryId = computed(() => params.formData?.category_id ?? '');
    const selectedItemTypeId = computed(() => params.formData?.itemtype_id ?? '');
    const selectedType = computed(() => params.formData?.type || 'single');

    const lastFetchedCompanyId = ref('');
    const lastFetchedCategoryKey = ref('');
    const lastVariationKey = ref('');
    const lastSelectedItemTypeId = ref('');
    const lastFetchedMarginCompanyId = ref('');
    const defaultMargin = ref<number | string>('');
    const pricingRows = ref<ProductDetailRow[]>([]);
    const detailsHydrated = ref(false);
    const applicableVariations = ref<ApplicableVariation[]>([]);
    const variationSelections = ref<VariationSelection[]>([]);
    const generatingVariants = ref(false);
    const generateError = ref('');
    const scopeWarning = ref('');

    const scopeReady = computed(() => (
        Boolean(normalizeId(selectedCompanyId.value) || normalizeId(authUser.value?.company_id))
        && Boolean(normalizeId(selectedCategoryId.value))
        && Boolean(normalizeId(selectedItemTypeId.value))
    ));

    const hasGeneratedRows = computed(() => pricingRows.value.some((row) => (
        row.variation_name && row.variation_name !== 'dummy'
    )));

    const nameRules = computed(() => {
        if (params.recordId) {
            return `required|min:2|max:200|product_name_unique:${params.recordId}`;
        }

        return 'required|min:2|max:200|product_name_unique';
    });

    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const scopeDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);
    const subcategoryDisabled = computed(() => scopeDisabled.value || ! selectedCategoryId.value);
    const typeDisabled = computed(() => isEdit.value || scopeDisabled.value);

    const imageInputId = computed(() => (params.type === 'edit' ? 'EditProductImage' : 'ProductImage'));

    function setProductType(type: 'single' | 'variable') {
        if (typeDisabled.value) {
            return;
        }

        if (params.formData) {
            params.formData.type = type;
        }

        params.formRef?.update?.({ type });
    }

    function isBlankMargin(value: unknown): boolean {
        return value === null || value === undefined || value === '';
    }

    function dummyDetail(): ProductDetailRow {
        return {
            variation_name: 'dummy',
            default_purchase_price: '',
            largequantity: '',
            smallquantity: '',
            profit_percent: isBlankMargin(defaultMargin.value) ? '' : defaultMargin.value,
            default_sell_price: '',
            variation_image: '',
            sku: '',
        };
    }

    function applyDefaultMarginToEmptyRows() {
        if (isEdit.value || ! detailsHydrated.value || isBlankMargin(defaultMargin.value)) {
            return;
        }

        const nextDetails = pricingRows.value.map((row) => (
            isBlankMargin(row.profit_percent)
                ? { ...row, profit_percent: defaultMargin.value }
                : row
        ));

        const hasChanges = nextDetails.some((row, index) => (
            row.profit_percent !== pricingRows.value[index]?.profit_percent
        ));

        if (hasChanges) {
            persistDetails(nextDetails);
        }
    }

    async function loadDefaultMargin(companyId: string | number | null | undefined) {
        const normalizedCompanyId = normalizeId(companyId);

        if (! normalizedCompanyId) {
            lastFetchedMarginCompanyId.value = '';
            defaultMargin.value = '';

            return;
        }

        if (normalizedCompanyId === lastFetchedMarginCompanyId.value) {
            applyDefaultMarginToEmptyRows();

            return;
        }

        lastFetchedMarginCompanyId.value = normalizedCompanyId;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.companySettings}/${normalizedCompanyId}`);
            const value = response.data?.companySetting?.profit_percent;
            defaultMargin.value = isBlankMargin(value) ? '' : value;
        } catch {
            defaultMargin.value = '';
        }

        applyDefaultMarginToEmptyRows();
    }

    function persistDetails(details: ProductDetailRow[]) {
        const normalized = details.map((item) => ({ ...item }));

        pricingRows.value = normalized;

        if (params.formData) {
            params.formData.productdetail = normalized;
        }

        params.formRef?.update?.({ productdetail: normalized });
    }

    function applyScopedDefaults() {
        if (isSuperadmin.value) {
            return;
        }

        if (authUser.value?.company_id) {
            params.formRef?.update?.({ company_id: authUser.value.company_id });
        }
    }

    function ensureDefaultDetails() {
        const existing = Array.isArray(params.formData?.productdetail)
            ? params.formData.productdetail
            : [];

        if (existing.length > 0) {
            persistDetails(existing);
            detailsHydrated.value = true;

            return;
        }

        persistDetails([dummyDetail()]);
        detailsHydrated.value = true;
    }

    function syncDetails(details: ProductDetailRow[]) {
        persistDetails(details);
    }

    function firstApiError(error: unknown): string {
        const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[] | string> } } })?.response;
        const errors = response?.data?.errors;

        if (errors) {
            const first = Object.values(errors)[0];

            if (Array.isArray(first) && first[0]) {
                return String(first[0]);
            }

            if (typeof first === 'string' && first !== '') {
                return first;
            }
        }

        return response?.data?.message || 'Unable to generate variations.';
    }

    function compatibleSelection(
        variation: ApplicableVariation,
        previous: VariationSelection | undefined,
    ): VariationSelection {
        const available = (variation.values ?? [])
            .filter((value) => value?.name && value.active !== false && value.active !== 0 && value.active !== '0')
            .map((value) => String(value.name).trim());

        if (previous) {
            return {
                variationId: variation.id,
                enabled: previous.enabled,
                selectedValues: previous.selectedValues.filter((value) => available.includes(value)),
            };
        }

        return inferSelections([variation], pricingRows.value)[0];
    }

    async function loadScopeOptions(companyId: string | number | null | undefined) {
        const normalizedCompanyId = normalizeId(companyId);

        if (! normalizedCompanyId) {
            categoriesdata.value = [];
            subcategoriesdata.value = [];
            itemtypesdata.value = [];
            brandsdata.value = [];
            unitsdata.value = [];
            warrantiesdata.value = [];
            variationsdata.value = [];
            await loadDefaultMargin('');

            return;
        }

        if (normalizedCompanyId === lastFetchedCompanyId.value) {
            await loadDefaultMargin(normalizedCompanyId);

            return;
        }

        lastFetchedCompanyId.value = normalizedCompanyId;
        lastFetchedMarginCompanyId.value = '';
        await Promise.all([
            fetchCategory(normalizedCompanyId),
            fetchItemType(normalizedCompanyId),
            fetchBrand(normalizedCompanyId),
            fetchUnit(normalizedCompanyId),
            fetchWarranty(normalizedCompanyId),
            loadDefaultMargin(normalizedCompanyId),
        ]);
    }

    async function loadSubcategoryOptions(
        companyId: string | number | null | undefined,
        categoryId: string | number | null | undefined,
    ) {
        const key = `${normalizeId(companyId)}:${normalizeId(categoryId)}`;

        if (! normalizeId(companyId) || ! normalizeId(categoryId)) {
            subcategoriesdata.value = [];

            return;
        }

        if (key === lastFetchedCategoryKey.value) {
            return;
        }

        lastFetchedCategoryKey.value = key;
        await fetchSubCategory(companyId, categoryId);
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        const clearedScope = {
            unit_id: '',
            brand_id: '',
            category_id: '',
            subcategory_id: '',
            itemtype_id: '',
            warranty_id: '',
        };

        if (params.formData) {
            Object.assign(params.formData, clearedScope);
        }

        params.formRef?.update?.(clearedScope);

        lastFetchedCompanyId.value = '';
        lastFetchedCategoryKey.value = '';
        lastVariationKey.value = '';
        lastFetchedMarginCompanyId.value = '';
        lastSelectedItemTypeId.value = '';
        applicableVariations.value = [];
        variationSelections.value = [];
        generateError.value = '';
        await loadScopeOptions(companyId);
    }

    async function handleCategoryChange(
        companyId: string | number | null | undefined,
        categoryId: string | number | null | undefined,
    ) {
        if (! isEdit.value) {
            params.formRef?.update?.({ subcategory_id: '' });
        }

        lastFetchedCategoryKey.value = '';
        await loadSubcategoryOptions(companyId, categoryId);
    }

    function selectedOptionId(value: unknown): string {
        if (value && typeof value === 'object' && 'id' in value) {
            return normalizeId((value as { id: unknown }).id);
        }

        return normalizeId(value);
    }

    async function loadApplicableVariations(options: { inferFromDetails?: boolean } = {}) {
        if (selectedType.value !== 'variable') {
            applicableVariations.value = [];
            variationSelections.value = [];
            generateError.value = '';

            return;
        }

        const companyId = normalizeId(selectedCompanyId.value) || normalizeId(authUser.value?.company_id);
        const categoryId = normalizeId(selectedCategoryId.value);
        const itemtypeId = normalizeId(selectedItemTypeId.value);
        const subcategoryId = normalizeId(params.formData?.subcategory_id);

        if (! companyId || ! categoryId || ! itemtypeId) {
            applicableVariations.value = [];
            variationSelections.value = [];

            return;
        }

        const key = `${companyId}:${categoryId}:${subcategoryId}:${itemtypeId}`;
        const scopeChanged = lastVariationKey.value !== '' && lastVariationKey.value !== key;
        lastVariationKey.value = key;

        await fetchVariation(companyId, categoryId, subcategoryId || undefined, itemtypeId);

        const variations = (Array.isArray(variationsdata.value) ? variationsdata.value : []) as ApplicableVariation[];
        const previousSelections = variationSelections.value;
        const previousIds = new Set(previousSelections.map((selection) => String(selection.variationId)));
        const nextIds = new Set(variations.map((variation) => String(variation.id)));
        const lostSelections = [...previousIds].some((id) => ! nextIds.has(id));

        applicableVariations.value = variations;
        variationSelections.value = options.inferFromDetails || previousSelections.length === 0
            ? inferSelections(variations, pricingRows.value)
            : variations.map((variation) => compatibleSelection(
                variation,
                previousSelections.find((selection) => String(selection.variationId) === String(variation.id)),
            ));

        if (scopeChanged && hasGeneratedRows.value) {
            scopeWarning.value = lostSelections
                ? 'Category, subcategory, or item type changed. Invalid variation selections were removed. Existing generated variants were kept so pricing is not lost. Review and generate again if needed.'
                : 'Category, subcategory, or item type changed. Existing generated variants were kept so pricing is not lost. Review selections and generate again if needed.';
        }
    }

    async function rebuildPricingRows(previousType?: string) {
        if (selectedType.value !== 'variable') {
            applicableVariations.value = [];
            variationSelections.value = [];
            generateError.value = '';
            scopeWarning.value = '';

            if (previousType === 'variable' || pricingRows.value.length === 0) {
                persistDetails([dummyDetail()]);
            }

            return;
        }

        if (previousType === 'single' && ! hasGeneratedRows.value) {
            persistDetails([]);
        }

        await loadApplicableVariations({ inferFromDetails: isEdit.value || hasGeneratedRows.value });
    }

    async function generateVariations() {
        const companyId = normalizeId(selectedCompanyId.value) || normalizeId(authUser.value?.company_id);
        const enabled = variationSelections.value.filter((selection) => selection.enabled);

        generateError.value = '';

        if (! companyId || enabled.length === 0) {
            generateError.value = 'Select at least one variation.';

            return;
        }

        generatingVariants.value = true;

        try {
            const response = await window.axios.post(API_ENDPOINTS.productGenerateVariants, {
                company_id: companyId,
                category_id: normalizeId(selectedCategoryId.value),
                subcategory_id: normalizeId(params.formData?.subcategory_id) || null,
                itemtype_id: normalizeId(selectedItemTypeId.value),
                product_sku: String(params.formData?.sku ?? '').trim(),
                selections: enabled.map((selection) => ({
                    variation_id: selection.variationId,
                    values: selection.selectedValues,
                })),
            });

            const combinations = Array.isArray(response.data?.combinations) ? response.data.combinations : [];
            const existingByName = new Map(
                pricingRows.value
                    .filter((row) => row.variation_name && row.variation_name !== 'dummy')
                    .map((row) => [normalizeVariantLabel(row.variation_name), row]),
            );

            persistDetails(combinations.map((combination: { variation_name: string; sku?: string }) => {
                const existing = existingByName.get(normalizeVariantLabel(combination.variation_name));

                if (existing) {
                    return {
                        ...existing,
                        variation_name: combination.variation_name,
                        sku: combination.sku ?? existing.sku,
                    };
                }

                return {
                    ...dummyDetail(),
                    variation_name: combination.variation_name,
                    sku: combination.sku ?? '',
                };
            }));
            scopeWarning.value = '';
        } catch (error) {
            generateError.value = firstApiError(error);
        } finally {
            generatingVariants.value = false;
        }
    }

    async function onItemTypeChange(value: unknown) {
        const itemtypeId = selectedOptionId(value);

        if (itemtypeId === lastSelectedItemTypeId.value) {
            return;
        }

        lastSelectedItemTypeId.value = itemtypeId;

        if (params.formData) {
            params.formData.itemtype_id = itemtypeId;
        }

        params.formRef?.update?.({ itemtype_id: itemtypeId });

        if (! detailsHydrated.value) {
            return;
        }

        lastVariationKey.value = '';
        await rebuildPricingRows();
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

    const imagePreviewUrl = computed(() => (
        resolveMediaUrl(params.logoUrl)
        || resolveMediaUrl(params.formData?.product_image_url)
        || resolveMediaUrl(params.formData?.product_image)
    ));

    function chooseImage(event: MouseEvent) {
        openLfmImagePicker(event, appUrl);
    }

    function clearProductImage() {
        params.formRef?.update?.({ product_image: '', product_image_url: '' });

        if (params.formData) {
            params.formData.product_image = '';
            params.formData.product_image_url = '';
        }
    }

    onMounted(async () => {
        applyScopedDefaults();
        lastSelectedItemTypeId.value = normalizeId(params.formData?.itemtype_id);
        ensureDefaultDetails();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        const companyId = isSuperadmin.value
            ? selectedCompanyId.value
            : authUser.value?.company_id;

        if (companyId) {
            await loadScopeOptions(companyId);
            await loadSubcategoryOptions(companyId, selectedCategoryId.value);
        }

        if (selectedType.value === 'variable') {
            await loadApplicableVariations({ inferFromDetails: true });
        }
    });

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
        () => `${normalizeId(params.formData?.company_id)}:${normalizeId(params.formData?.category_id)}`,
        async (key, previousKey) => {
            if (key === previousKey) {
                return;
            }

            const [companyId, categoryId] = key.split(':');
            await handleCategoryChange(companyId || undefined, categoryId || undefined);
        },
    );

    watch(
        () => [
            selectedType.value,
            normalizeId(params.formData?.company_id),
            normalizeId(params.formData?.category_id),
            normalizeId(params.formData?.subcategory_id),
            normalizeId(params.formData?.itemtype_id),
        ].join(':'),
        async (key, previousKey) => {
            if (! detailsHydrated.value || key === previousKey) {
                return;
            }

            await rebuildPricingRows(previousKey?.split(':')[0]);
        },
    );

    watch(
        () => params.formData?.productdetail,
        (details) => {
            if (! detailsHydrated.value) {
                return;
            }

            if (! Array.isArray(details) || details.length === 0) {
                if (params.formData && pricingRows.value.length > 0) {
                    params.formData.productdetail = pricingRows.value;
                }

                return;
            }

            if (isEdit.value && details.length !== pricingRows.value.length) {
                persistDetails(details);
            }
        },
        { deep: true },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <TextElement
        v-if="showHiddenCompanyField"
        name="company_id"
        hidden="true"
    />

    <TextElement
        name="type"
        hidden="true"
        default="single"
        rules="required|in:single,variable"
    />

    <StaticElement v-if="showCompanyField" name="section_scope" :columns="colFull">
        <div class="company-section-header company-section-header-primary">
            <span class="company-section-icon company-section-icon-primary">
                <Store size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Company</h6>
                <p class="company-section-subtitle mb-0">Assign this product to a company catalog</p>
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

    <StaticElement name="section_identity" :columns="colFull">
        <div
            class="company-section-header company-section-header-indigo"
            :class="{ 'company-section-header-spaced': showCompanyField }"
        >
            <span class="company-section-icon company-section-icon-indigo">
                <Package size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Product</h6>
                <p class="company-section-subtitle mb-0">Name, SKU, and how this item is sold</p>
            </div>
            <div class="product-type-toggle" role="group" aria-label="Product type">
                <button
                    type="button"
                    class="product-type-toggle__btn"
                    :class="{ 'is-active': selectedType === 'single' }"
                    :disabled="typeDisabled"
                    @click="setProductType('single')"
                >
                    <Package size="xs" />
                    Single
                </button>
                <button
                    type="button"
                    class="product-type-toggle__btn"
                    :class="{ 'is-active': selectedType === 'variable' }"
                    :disabled="typeDisabled"
                    @click="setProductType('variable')"
                >
                    <Layers size="xs" />
                    Variable
                </button>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Name"
        placeholder="e.g. Premium Basmati Rice 5kg"
        :columns="colHalf"
        autocomplete="off"
        :rules="nameRules"
    />

    <TextElement
        id="Sku"
        field-name="Sku"
        name="sku"
        label="SKU"
        placeholder="Auto-generated if blank"
        :columns="colQuarter"
        autocomplete="off"
    />

    <TextElement
        id="AlertQty"
        field-name="AlertQty"
        name="alert_qty"
        label="Alert qty"
        input-type="number"
        placeholder="10"
        :columns="colQuarter"
        autocomplete="off"
        rules="nullable|numeric|min:0"
    />

    <TextareaElement
        name="product_desc"
        id="ProductDesc"
        field-name="ProductDesc"
        label="Description"
        placeholder="Short selling notes or packing details"
        :columns="{ container: 9, label: 12, wrapper: 12 }"
        :rows="3"
    />

    <TextElement
        id="Weight"
        field-name="Weight"
        name="weight"
        label="Weight"
        input-type="number"
        placeholder="Optional"
        :columns="colQuarter"
        autocomplete="off"
        rules="nullable|numeric|min:0"
    />

    <StaticElement name="section_classification" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <Box size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Organization</h6>
                <p class="company-section-subtitle mb-0">Unit, brand, category, and item type</p>
            </div>
        </div>
    </StaticElement>

    <SelectElement
        name="unit_id"
        :native="false"
        :items="unitsdata"
        id="UnitId"
        field-name="UnitId"
        placeholder="Select unit"
        label="Unit"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="scopeDisabled"
        rules="required"
    />

    <SelectElement
        name="brand_id"
        :native="false"
        :items="brandsdata"
        id="BrandId"
        field-name="BrandId"
        placeholder="Select brand"
        label="Brand"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="scopeDisabled"
        rules="required"
    />

    <SelectElement
        name="category_id"
        :native="false"
        :items="categoriesdata"
        id="CategoryId"
        field-name="CategoryId"
        placeholder="Select category"
        label="Category"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="scopeDisabled"
        rules="required"
    />

    <SelectElement
        name="subcategory_id"
        :native="false"
        :items="subcategoriesdata"
        id="SubcategoryId"
        field-name="SubcategoryId"
        placeholder="Select subcategory"
        label="Subcategory"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="subcategoryDisabled"
    />

    <SelectElement
        name="itemtype_id"
        :native="false"
        :items="itemtypesdata"
        id="ItemtypeId"
        field-name="ItemtypeId"
        placeholder="Select item type"
        label="Item type"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="scopeDisabled"
        rules="required"
        @change="onItemTypeChange"
    />

    <SelectElement
        name="warranty_id"
        :native="false"
        :items="warrantiesdata"
        id="WarrantyId"
        field-name="WarrantyId"
        placeholder="Select warranty"
        label="Warranty"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="scopeDisabled"
    />

    <StaticElement name="section_media" :columns="colFull">
        <div class="company-section-header company-section-header-primary company-section-header-spaced">
            <span class="company-section-icon company-section-icon-primary">
                <ImagePlus size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Media & status</h6>
                <p class="company-section-subtitle mb-0">Catalog image and whether this product is sellable</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        :id="imageInputId"
        field-name="ProductImage"
        name="product_image"
        label="Product image"
        placeholder="Select product image"
        :columns="colThird"
        :add-classes="{
            ElementAddon: {
                container: 'p-0',
            },
        }"
    >
        <template #addon-before>
            <button
                :data-input="imageInputId"
                data-field-name="product_image"
                type="button"
                class="company-logo-choose"
                @click="chooseImage"
            >
                <ImagePlus size="xs" />
                <span>Choose</span>
            </button>
        </template>
        <template #after>
            <div class="company-logo-preview">
                <img
                    v-if="imagePreviewUrl"
                    :src="imagePreviewUrl"
                    alt="Product preview"
                    class="company-logo-preview-img d-block rounded object-fit-contain"
                    style="height: 4.5rem"
                >
                <button
                    v-if="imagePreviewUrl"
                    type="button"
                    class="btn btn-sm btn-link text-danger px-0"
                    @click="clearProductImage"
                >
                    Remove image
                </button>
            </div>
        </template>
    </TextElement>

    <ToggleElement
        :labels="{ 1: 'Active', 0: 'Inactive' }"
        :columns="colThird"
        id="Active"
        field-name="Active"
        name="active"
        label="Product status"
        :true-value="true"
        :false-value="false"
        :default="true"
    />

    <StaticElement v-if="selectedType === 'variable'" name="section_variations" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <Layers size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Variations</h6>
                <p class="company-section-subtitle mb-0">
                    Include the attributes and values that should be combined
                </p>
            </div>
        </div>
    </StaticElement>

    <StaticElement v-if="selectedType === 'variable'" name="variation_picker" :columns="colFull">
        <VariationPicker
            :variations="applicableVariations"
            :selections="variationSelections"
            :disabled="scopeDisabled"
            :generating="generatingVariants"
            :scope-ready="scopeReady"
            :warning="scopeWarning"
            :error="generateError"
            @update:selections="variationSelections = $event"
            @generate="generateVariations"
        />
    </StaticElement>

    <StaticElement name="section_pricing" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Pricing</h6>
                <p class="company-section-subtitle mb-0">
                    {{ selectedType === 'variable'
                        ? 'Each generated variant gets its own SKU, purchase, margin, and sell price'
                        : 'Purchase cost, packing, margin, and sell price' }}
                </p>
            </div>
        </div>
    </StaticElement>

    <StaticElement name="details_editor" :columns="colFull">
        <ProductDetailsEditor
            :details="pricingRows"
            :product-type="selectedType"
            :disabled="scopeDisabled"
            @update:details="syncDetails"
        />
    </StaticElement>
</template>
