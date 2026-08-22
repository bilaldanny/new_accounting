<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { openLfmImagePicker } from '@/utils/openLfmImagePicker';
    import { computed, nextTick, onMounted, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';
    import { Barcode, Box, ImagePlus, Package, SliderAlt, Store } from '@boxicons/vue';
    import ProductDetailsEditor, { type ProductDetailRow } from './ProductDetailsEditor.vue';

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
    const pricingRows = ref<ProductDetailRow[]>([]);
    const detailsHydrated = ref(false);

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

    const typeOptions = [
        { id: 'single', text: 'Single' },
        { id: 'variable', text: 'Variable' },
    ];

    const imageInputId = computed(() => (params.type === 'edit' ? 'EditProductImage' : 'ProductImage'));

    function dummyDetail(): ProductDetailRow {
        return {
            variation_name: 'dummy',
            default_purchase_price: '',
            largequantity: '',
            smallquantity: '',
            profit_percent: '',
            default_sell_price: '',
            variation_image: '',
        };
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

    function detailsFromVariations(): ProductDetailRow[] {
        const values = Array.isArray(variationsdata.value?.[0]?.values)
            ? variationsdata.value[0].values
            : [];

        const activeValues = values.filter((value: { name?: string; active?: boolean }) => (
            value?.name && value.active !== false
        ));

        if (activeValues.length === 0) {
            return [];
        }

        return activeValues.map((value: { name: string }) => ({
            ...dummyDetail(),
            variation_name: value.name,
        }));
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

            return;
        }

        if (normalizedCompanyId === lastFetchedCompanyId.value) {
            return;
        }

        lastFetchedCompanyId.value = normalizedCompanyId;
        await Promise.all([
            fetchCategory(normalizedCompanyId),
            fetchItemType(normalizedCompanyId),
            fetchBrand(normalizedCompanyId),
            fetchUnit(normalizedCompanyId),
            fetchWarranty(normalizedCompanyId),
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

        params.formRef?.update?.({
            unit_id: '',
            brand_id: '',
            category_id: '',
            subcategory_id: '',
            itemtype_id: '',
            warranty_id: '',
        });

        lastFetchedCompanyId.value = '';
        lastFetchedCategoryKey.value = '';
        lastVariationKey.value = '';
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

    async function rebuildPricingRows(previousType?: string) {
        if (isEdit.value) {
            return;
        }

        if (selectedType.value !== 'variable') {
            if (previousType === 'variable' || pricingRows.value.length === 0) {
                persistDetails([dummyDetail()]);
            }

            return;
        }

        const companyId = normalizeId(selectedCompanyId.value);
        const categoryId = normalizeId(selectedCategoryId.value);
        const itemtypeId = normalizeId(selectedItemTypeId.value);
        const subcategoryId = normalizeId(params.formData?.subcategory_id);

        if (! companyId || ! categoryId || ! itemtypeId) {
            return;
        }

        const key = `${companyId}:${categoryId}:${subcategoryId}:${itemtypeId}`;

        if (key !== lastVariationKey.value) {
            lastVariationKey.value = key;
            await fetchVariation(companyId, categoryId, subcategoryId || undefined, itemtypeId);
        }

        const variationRows = detailsFromVariations();

        if (variationRows.length > 0) {
            persistDetails(variationRows);
        }
    }

    function chooseImage(event: MouseEvent) {
        openLfmImagePicker(event, appUrl);
    }

    function renderImagePreview() {
        const holder = document.getElementById('product-image-holder');

        if (! holder) {
            return;
        }

        const previewUrl = params.logoUrl || params.formData?.product_image_url || '';

        if (! previewUrl) {
            holder.innerHTML = '';

            return;
        }

        holder.innerHTML = '';
        const img = document.createElement('img');
        img.className = 'company-logo-preview-img d-block rounded object-fit-contain';
        img.style.height = '4.5rem';
        img.src = previewUrl;
        holder.appendChild(img);
    }

    onMounted(async () => {
        applyScopedDefaults();
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

        await nextTick();
        renderImagePreview();
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

    watch(() => params.logoUrl, renderImagePreview);
    watch(() => params.formData?.product_image, renderImagePreview);
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <TextElement
        v-if="showHiddenCompanyField"
        name="company_id"
        hidden="true"
    />

    <StaticElement v-if="showCompanyField" name="section_scope" :columns="colFull">
        <div class="company-section-header company-section-header-primary">
            <span class="company-section-icon company-section-icon-primary">
                <Store size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Company Scope</h6>
                <p class="company-section-subtitle mb-0">Assign this product to the correct company catalog</p>
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
        info="Required. Products, units, brands, and categories are scoped to a company."
    />

    <StaticElement name="section_identity" :columns="colFull">
        <div class="company-section-header company-section-header-indigo" :class="{ 'company-section-header-spaced': showCompanyField }">
            <span class="company-section-icon company-section-icon-indigo">
                <Package size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Product Identity</h6>
                <p class="company-section-subtitle mb-0">Name, SKU, and whether this is a single or variable product</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Product Name"
        placeholder="e.g. Premium Basmati Rice 5kg"
        :columns="colThird"
        autocomplete="off"
        :rules="nameRules"
        info="A clear catalog name that staff will recognize on invoices and stock screens."
    />

    <TextElement
        id="Sku"
        field-name="Sku"
        name="sku"
        label="SKU"
        placeholder="Auto-generated if left blank"
        :columns="colThird"
        autocomplete="off"
        info="Leave blank to generate from company product code settings."
    />

    <SelectElement
        name="type"
        :native="false"
        :items="typeOptions"
        id="Type"
        field-name="Type"
        placeholder="Select product type"
        label="Product Type"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        :can-clear="false"
        :default="'single'"
        :disabled="typeDisabled"
        rules="required|in:single,variable"
        info="Single products have one price row. Variable products load variation values after category and item type are selected."
    />

    <StaticElement name="section_classification" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <Box size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Classification</h6>
                <p class="company-section-subtitle mb-0">Unit, brand, category, and item type used for reporting and POS filters</p>
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
        info="Base selling and stocking unit."
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
        info="Required. Used on the product list and POS brand filter."
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
        info="Required. Subcategories and variation sets depend on this choice."
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
        info="Optional. Only categories nested under the selected parent appear here."
    />

    <SelectElement
        name="itemtype_id"
        :native="false"
        :items="itemtypesdata"
        id="ItemtypeId"
        field-name="ItemtypeId"
        placeholder="Select item type"
        label="Item Type"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="scopeDisabled"
        rules="required"
        info="Required. Variable products load variation values for this item type."
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
        info="Optional coverage plan attached to this product."
    />

    <StaticElement name="section_inventory" :columns="colFull">
        <div class="company-section-header company-section-header-primary company-section-header-spaced">
            <span class="company-section-icon company-section-icon-primary">
                <Barcode size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Inventory & Media</h6>
                <p class="company-section-subtitle mb-0">Stock alerts, description, and catalog image</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="AlertQty"
        field-name="AlertQty"
        name="alert_qty"
        label="Alert Quantity"
        input-type="number"
        placeholder="e.g. 10"
        :columns="colThird"
        autocomplete="off"
        rules="nullable|numeric|min:0"
        info="Low-stock warning threshold in the base unit."
    />

    <TextElement
        id="Weight"
        field-name="Weight"
        name="weight"
        label="Weight"
        input-type="number"
        placeholder="Optional"
        :columns="colThird"
        autocomplete="off"
        rules="nullable|numeric|min:0"
        info="Optional shipping or packing weight."
    />

    <TextElement
        :id="imageInputId"
        field-name="ProductImage"
        name="product_image"
        label="Product Image"
        placeholder="Select product image"
        :columns="colThird"
        :add-classes="{
            ElementAddon: {
                container: 'p-0',
            },
        }"
        info="Optional catalog photo. Choose from the file manager."
    >
        <template #addon-before>
            <button
                :data-input="imageInputId"
                data-field-name="product_image"
                data-preview="product-image-holder"
                type="button"
                class="company-logo-choose"
                @click="chooseImage"
            >
                <ImagePlus size="xs" />
                <span>Choose</span>
            </button>
        </template>
        <template #after>
            <div id="product-image-holder" class="company-logo-preview"></div>
        </template>
    </TextElement>

    <TextareaElement
        name="product_desc"
        id="ProductDesc"
        field-name="ProductDesc"
        label="Product Description"
        placeholder="Short selling notes, packing details, or catalog copy"
        :columns="colHalf"
        :rows="4"
        info="Shown on product documents. Keep it concise and useful for staff."
    />

    <StaticElement name="section_pricing" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Pricing & Variations</h6>
                <p class="company-section-subtitle mb-0">Set purchase cost, packing quantities, margin, and selling price for each row</p>
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

    <StaticElement name="section_status" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Status</h6>
                <p class="company-section-subtitle mb-0">Inactive products are hidden from POS and purchase screens</p>
            </div>
        </div>
    </StaticElement>

    <ToggleElement
        :labels="{ 1: 'Active', 0: 'Inactive' }"
        :columns="colThird"
        id="Active"
        field-name="Active"
        name="active"
        label="Product Status"
        :true-value="true"
        :false-value="false"
        :default="true"
        info="Turn off to keep the product in the catalog without selling it."
    />
</template>
