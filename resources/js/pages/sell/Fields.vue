<script setup lang="ts">
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import type { SellLineRow } from '@/composables/sell';
    import { openLfmImagePickerCallback } from '@/utils/openLfmImagePicker';
    import { usePage } from '@inertiajs/vue3';
    import { Box, Calendar, ImagePlus, Truck } from '@boxicons/vue';
    import { computed, onMounted, ref, watch } from 'vue';
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
    const colFull = { container: 12, label: 12, wrapper: 12 };

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
    const showBranchField = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const showHiddenBranchField = computed(() => ! isSuperadmin.value && ! isCompanyadmin.value);
    const isEdit = computed(() => params.type === 'edit');

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
        appUrl,
    } = useCommons();

    const customersdata = ref<Array<{ id: number | string; text?: string; business_name?: string; pay_term?: number | string; pay_type?: string; credit_limit?: number | string }>>([]);
    const suppliersdata = ref<Array<{ id: number | string; text?: string; business_name?: string }>>([]);
    const productSuggestions = ref<any[]>([]);
    const searchingProducts = ref(false);
    const lastFetchedCompanyId = ref('');
    const lastFetchedCustomerKey = ref('');
    const allowPackingEdit = ref(false);
    const searchType = ref<'searchbox' | 'selectbox'>(resolveSearchType(authUser.value?.search_type));
    const searchTimer = ref<ReturnType<typeof setTimeout> | null>(null);
    const defaultCustomerId = ref('');
    const isSearchBox = computed(() => searchType.value === 'searchbox');

    const payTypeItems = [
        { value: 'day', label: 'Day' },
        { value: 'month', label: 'Month' },
        { value: 'year', label: 'Year' },
    ];

    const statusItems = [
        { value: 'final', label: 'Final' },
        { value: 'draft', label: 'Draft' },
        { value: 'quotation', label: 'Quotation' },
    ];

    const discountTypeItems = [
        { value: 'none', label: 'None' },
        { value: 'fixed', label: 'Fixed' },
        { value: 'percentage', label: 'Percentage' },
    ];

    const shippingStatusItems = [
        { value: '', label: 'Not set' },
        { value: 'ordered', label: 'Ordered' },
        { value: 'packed', label: 'Packed' },
        { value: 'shipped', label: 'Shipped' },
        { value: 'delivered', label: 'Delivered' },
        { value: 'cancelled', label: 'Cancelled' },
    ];

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedBranchId = computed(() => params.formData?.branch_id ?? '');
    const scopeReady = computed(() => Boolean(normalizeId(selectedCompanyId.value) && normalizeId(selectedBranchId.value)));
    const branchDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);
    const customerDisabled = computed(() => ! scopeReady.value);
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const sellLines = computed<SellLineRow[]>(() => (
        Array.isArray(params.formData?.selllines) ? params.formData.selllines : []
    ));
    const itemCountLabel = computed(() => {
        const count = sellLines.value.length;

        return count === 1 ? '1 item' : `${count} items`;
    });
    const discountAmountDisabled = computed(() => String(params.formData?.discount_type || 'none') === 'none');

    function toNumber(value: unknown, fallback = 0): number {
        const amount = Number(value);

        return Number.isFinite(amount) ? amount : fallback;
    }

    function money(value: unknown): string {
        return toNumber(value).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function persist(patch: Record<string, unknown>) {
        if (params.formData) {
            Object.assign(params.formData, patch);
        }

        params.formRef?.update?.(patch);
    }

    function persistLines(lines: SellLineRow[]) {
        const next = lines.map((line) => ({ ...line }));
        persist({
            selllines: next,
            total_item: next.length,
            total_pack_qty: next.reduce((sum, line) => sum + toNumber(line.packing_qty), 0),
            net_sub_total: next.reduce((sum, line) => sum + toNumber(line.row_subtotal), 0),
        });
        recalculateTotals(next);
    }

    function pricedLine(line: SellLineRow): SellLineRow {
        const unitPrice = Math.max(toNumber(line.unit_price), 0);
        const discount = Math.max(toNumber(line.discount_percent), 0);
        const quantity = Math.max(toNumber(line.quantity, 1), 1);
        const packingQty = Math.max(toNumber(line.packing_qty, 1), 1);
        const priceAfterDiscount = Math.max(unitPrice - discount, 0);

        return {
            ...line,
            quantity,
            packing_qty: packingQty,
            unit_price: unitPrice,
            discount_percent: discount,
            unit_price_after_discount: priceAfterDiscount,
            row_subtotal: Number((priceAfterDiscount * quantity * packingQty).toFixed(2)),
            subtotal: Number((priceAfterDiscount * quantity * packingQty).toFixed(2)),
        };
    }

    function recalculateTotals(lines = sellLines.value) {
        const netSubTotal = lines.reduce((sum, line) => sum + toNumber(line.row_subtotal), 0);
        const discountType = String(params.formData?.discount_type || 'none');
        const discountAmount = toNumber(params.formData?.discount_amount);
        let discountVal = 0;

        if (discountType === 'percentage') {
            discountVal = (netSubTotal / 100) * discountAmount;
        } else if (discountType === 'fixed') {
            discountVal = discountAmount;
        }

        persist({
            net_sub_total: Number(netSubTotal.toFixed(2)),
            discount_val: Number(discountVal.toFixed(2)),
            final_amount: Number((netSubTotal + toNumber(params.formData?.shipping_charges) - discountVal).toFixed(2)),
            total_item: lines.length,
            total_pack_qty: lines.reduce((sum, line) => sum + toNumber(line.packing_qty), 0),
        });
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
            defaultCustomerId.value = '';

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
            defaultCustomerId.value = normalizeId(setting?.default_customer);

            if (! isEdit.value && ! normalizeId(params.formData?.contact_id) && defaultCustomerId.value) {
                persist({ contact_id: defaultCustomerId.value });
            }
        } catch {
            allowPackingEdit.value = false;
            searchType.value = resolveSearchType(authUser.value?.search_type);
        }
    }

    async function loadCustomers(companyId: string | number | null | undefined, branchId: string | number | null | undefined) {
        const key = `${normalizeId(companyId)}:${normalizeId(branchId)}`;

        if (! normalizeId(companyId) || ! normalizeId(branchId)) {
            customersdata.value = [];

            return;
        }

        if (key === lastFetchedCustomerKey.value) {
            return;
        }

        lastFetchedCustomerKey.value = key;

        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchCustomers, {
                params: { company_id: companyId, branch_id: branchId },
            });
            customersdata.value = response.data ?? [];
        } catch {
            customersdata.value = [];
        }
    }

    async function loadSuppliers(companyId: string | number | null | undefined, branchId: string | number | null | undefined) {
        if (! normalizeId(companyId) || ! normalizeId(branchId)) {
            suppliersdata.value = [];

            return;
        }

        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchSuppliers, {
                params: { company_id: companyId, branch_id: branchId },
            });
            suppliersdata.value = response.data ?? [];
        } catch {
            suppliersdata.value = [];
        }
    }

    async function loadBranchOptions(companyId: string | number | null | undefined) {
        const normalizedCompanyId = normalizeId(companyId);

        if (! showBranchField.value) {
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
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        persist({
            branch_id: '',
            contact_id: '',
            direct_contact_id: '',
            selllines: [],
            total_item: 0,
            total_pack_qty: 0,
            net_sub_total: 0,
            discount_val: 0,
            final_amount: 0,
            credit_limit: 0,
        });
        lastFetchedCompanyId.value = '';
        lastFetchedCustomerKey.value = '';
        productSuggestions.value = [];
        await loadBranchOptions(companyId);
    }

    async function handleBranchChange() {
        persist({
            contact_id: '',
            direct_contact_id: '',
            credit_limit: 0,
        });
        lastFetchedCustomerKey.value = '';
        await loadCustomers(selectedCompanyId.value, selectedBranchId.value);

        if (params.formData?.is_direct) {
            await loadSuppliers(selectedCompanyId.value, selectedBranchId.value);
        }

        await loadProductOptions();
    }

    function applyCustomerPayTerm(contactId: unknown) {
        const customer = customersdata.value.find((item) => String(item.id) === normalizeId(contactId));

        if (! customer) {
            return;
        }

        persist({
            pay_term: customer.pay_term ?? params.formData?.pay_term ?? '',
            pay_type: customer.pay_type || params.formData?.pay_type || 'day',
            credit_limit: customer.credit_limit ?? 0,
        });
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
            const response = await window.axios.get(API_ENDPOINTS.sellSearchProducts, {
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
        default_sell_price: number | string;
        units: SellLineRow['units'];
    }) {
        const alreadyAdded = sellLines.value.some((line) => (
            String(line.product_id) === String(product.product_id)
            && String(line.variation_id) === String(product.id)
        ));

        if (alreadyAdded) {
            return;
        }

        const unit = product.units?.[0];
        const nextLine = pricedLine({
            product_id: product.product_id,
            variation_id: product.id,
            itemtype_id: product.itemtype_id,
            product_name: product.name,
            sku: product.sku,
            unit_id: product.unit_id,
            quantity: 1,
            quantity_issue: 0,
            quantity_returned: 0,
            unit_price: product.default_sell_price,
            discount_percent: 0,
            unit_price_after_discount: product.default_sell_price,
            packing_qty: unit?.packing_qty ?? 1,
            row_subtotal: product.default_sell_price,
            units: product.units ?? [],
            current_stock: unit?.unit_qty ?? 0,
            unit_name: unit?.short_name ?? unit?.text,
        });

        persistLines([nextLine, ...sellLines.value]);
    }

    function updateLine(index: number, patch: Partial<SellLineRow>) {
        persistLines(sellLines.value.map((line, lineIndex) => (
            lineIndex === index ? pricedLine({ ...line, ...patch }) : line
        )));
    }

    function removeLine(index: number) {
        persistLines(sellLines.value.filter((_, lineIndex) => lineIndex !== index));
    }

    function resolveMediaUrl(path: unknown): string {
        const value = String(path ?? '').trim();

        if (! value) {
            return '';
        }

        if (value.startsWith('http://') || value.startsWith('https://') || value.startsWith('data:') || value.startsWith('blob:')) {
            return value;
        }

        const base = String(appUrl ?? '').replace(/\/$/, '');

        return `${base}/${value.replace(/^\//, '')}`;
    }

    const imagePreviewUrl = computed(() => (
        resolveMediaUrl(params.formData?.billty_image_url)
        || resolveMediaUrl(params.formData?.billty_image)
    ));

    function chooseImage(event: MouseEvent) {
        openLfmImagePickerCallback(event, appUrl, (path) => {
            persist({
                billty_image: path,
                billty_image_url: resolveMediaUrl(path),
            });
        });
    }

    function clearBilltyImage() {
        persist({ billty_image: '', billty_image_url: '' });
    }

    async function onDirectToggle(value: unknown) {
        const enabled = value === true || value === 1 || value === '1' || value === 'true';

        if (enabled) {
            await loadSuppliers(selectedCompanyId.value, selectedBranchId.value);
        } else {
            persist({ direct_contact_id: '' });
        }
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

        if (scopeReady.value) {
            await loadCustomers(selectedCompanyId.value, selectedBranchId.value);
        }

        if (params.formData?.is_direct && scopeReady.value) {
            await loadSuppliers(selectedCompanyId.value, selectedBranchId.value);
        }

        recalculateTotals();
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

    watch(
        () => params.formData?.contact_id,
        (contactId) => applyCustomerPayTerm(contactId),
    );

    watch(
        () => [params.formData?.discount_type, params.formData?.discount_amount, params.formData?.shipping_charges],
        () => recalculateTotals(),
    );

    watch(
        () => params.formData?.is_direct,
        async (isDirect) => {
            await onDirectToggle(isDirect);
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />
    <TextElement v-if="showHiddenBranchField" name="branch_id" hidden="true" />
    <TextElement name="type" hidden="true" default="sell" />
    <TextElement name="payment_status" hidden="true" default="due" />
    <TextElement name="total_item" hidden="true" />
    <TextElement name="total_pack_qty" hidden="true" />
    <TextElement name="net_sub_total" hidden="true" />
    <TextElement name="discount_val" hidden="true" />
    <TextElement name="final_amount" hidden="true" />
    <TextElement name="credit_limit" hidden="true" />

    <StaticElement name="section_invoice" :columns="colFull">
        <div class="company-section-header company-section-header-indigo">
            <span class="company-section-icon company-section-icon-indigo">
                <Calendar size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Invoice details</h6>
                <p class="company-section-subtitle mb-0">Customer, location, reference, and payment terms</p>
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
        placeholder="Select branch"
        label="Branch"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="branchDisabled"
        rules="required"
    />

    <SelectElement
        name="contact_id"
        :native="false"
        :items="customersdata"
        id="ContactId"
        field-name="ContactId"
        placeholder="Select customer"
        label="Customer"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="customerDisabled"
        rules="required"
        info="Required. Pay term and credit limit are filled from the customer when available."
    />

    <TextElement
        id="InvoiceNo"
        field-name="InvoiceNo"
        name="invoice_no"
        label="Reference no"
        placeholder="Auto-generated if blank"
        :columns="colQuarter"
        autocomplete="off"
        info="Leave empty to generate from company invoice prefix."
    />

    <DateElement
        id="TransactionDate"
        field-name="TransactionDate"
        name="transaction_date"
        label="Sell date"
        placeholder="Select sell date"
        :columns="colQuarter"
        :floating="false"
        rules="required"
    />

    <SelectElement
        name="status"
        :native="false"
        :items="statusItems"
        id="SellStatus"
        field-name="SellStatus"
        placeholder="Select status"
        label="Sell status"
        :columns="colQuarter"
        label-prop="label"
        value-prop="value"
        :search="false"
        :floating="false"
        :can-clear="false"
        rules="required"
    />

    <TextElement
        id="PayTerm"
        field-name="PayTerm"
        name="pay_term"
        label="Pay term"
        input-type="number"
        placeholder="e.g. 30"
        :columns="colQuarter"
        autocomplete="off"
        rules="nullable|numeric|min:0"
    />

    <SelectElement
        name="pay_type"
        :native="false"
        :items="payTypeItems"
        id="PayType"
        field-name="PayType"
        placeholder="Select period"
        label="Pay term period"
        :columns="colQuarter"
        label-prop="label"
        value-prop="value"
        :search="false"
        :floating="false"
        :can-clear="false"
    />

    <ToggleElement
        :labels="{ 1: 'Yes', 0: 'No' }"
        :columns="colThird"
        id="IsDirect"
        field-name="IsDirect"
        name="is_direct"
        label="Direct from supplier"
        :true-value="true"
        :false-value="false"
        :default="false"
        :disabled="customerDisabled"
        info="Sell directly from supplier to customer. Stock is not updated."
        @change="onDirectToggle"
    />

    <SelectElement
        v-if="params.formData?.is_direct"
        name="direct_contact_id"
        :native="false"
        :items="suppliersdata"
        id="DirectContactId"
        field-name="DirectContactId"
        placeholder="Select supplier"
        label="Supplier"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        rules="required"
    />

    <StaticElement name="section_items" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <Box size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Line items</h6>
                <p class="company-section-subtitle mb-0">
                    {{ isSearchBox
                        ? 'Search products, then set quantity, rate, and discount'
                        : 'Select category, subcategory, item type, product, then variation' }}
                </p>
            </div>
            <span class="purchase-form__count">{{ itemCountLabel }}</span>
        </div>
    </StaticElement>

    <StaticElement name="lines_editor" :columns="colFull">
        <LineItemsEditor
            :lines="sellLines"
            :suggestions="productSuggestions"
            :searching="searchingProducts"
            :disabled="!scopeReady"
            :allow-packing-edit="allowPackingEdit"
            :search-type="searchType"
            :status="String(params.formData?.status || 'final')"
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

    <StaticElement name="section_delivery" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <Truck size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Delivery & transport</h6>
                <p class="company-section-subtitle mb-0">Shipping, delivery status, and bilty details</p>
            </div>
        </div>
    </StaticElement>

    <TextElement name="discount_type" hidden="true" />
    <TextElement name="discount_amount" hidden="true" rules="nullable|numeric|min:0" />
    <TextElement name="shipping_details" hidden="true" />
    <TextElement name="shipping_address" hidden="true" />
    <TextElement name="shipping_charges" hidden="true" rules="nullable|numeric|min:0" />
    <TextElement name="shipping_status" hidden="true" />
    <TextElement name="delivered_to" hidden="true" />
    <TextElement name="billty_no" hidden="true" />
    <TextElement name="billty_date" hidden="true" />
    <TextElement name="packing" hidden="true" />
    <TextElement name="billty_image" hidden="true" />
    <TextareaElement name="additional_note" hidden="true" />

    <StaticElement name="sell_settlement" :columns="colFull">
        <div class="purchase-settlement">
            <div class="purchase-settlement__panel">
                <div class="purchase-settlement__panel-head">
                    <p class="purchase-settlement__eyebrow">Adjustments</p>
                    <h6 class="purchase-settlement__heading">Discount, shipping & notes</h6>
                </div>

                <div class="purchase-settlement__grid">
                    <label class="purchase-settlement__field">
                        <span>Discount type</span>
                        <select
                            :value="params.formData?.discount_type || 'none'"
                            @change="persist({ discount_type: ($event.target as HTMLSelectElement).value })"
                        >
                            <option v-for="item in discountTypeItems" :key="item.value" :value="item.value">
                                {{ item.label }}
                            </option>
                        </select>
                    </label>

                    <label class="purchase-settlement__field">
                        <span>Discount amount</span>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            :value="params.formData?.discount_amount ?? 0"
                            :disabled="discountAmountDisabled"
                            @input="persist({ discount_amount: ($event.target as HTMLInputElement).value })"
                        >
                    </label>

                    <label class="purchase-settlement__field">
                        <span>Shipping detail</span>
                        <input
                            type="text"
                            placeholder="Carrier, tracking, or delivery notes"
                            :value="params.formData?.shipping_details || ''"
                            autocomplete="off"
                            @input="persist({ shipping_details: ($event.target as HTMLInputElement).value })"
                        >
                    </label>

                    <label class="purchase-settlement__field">
                        <span>Shipping address</span>
                        <input
                            type="text"
                            placeholder="Delivery address"
                            :value="params.formData?.shipping_address || ''"
                            autocomplete="off"
                            @input="persist({ shipping_address: ($event.target as HTMLInputElement).value })"
                        >
                    </label>

                    <label class="purchase-settlement__field">
                        <span>Shipping charges</span>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            :value="params.formData?.shipping_charges ?? 0"
                            @input="persist({ shipping_charges: ($event.target as HTMLInputElement).value })"
                        >
                    </label>

                    <label class="purchase-settlement__field">
                        <span>Delivery status</span>
                        <select
                            :value="params.formData?.shipping_status || ''"
                            @change="persist({ shipping_status: ($event.target as HTMLSelectElement).value })"
                        >
                            <option v-for="item in shippingStatusItems" :key="item.value" :value="item.value">
                                {{ item.label }}
                            </option>
                        </select>
                    </label>

                    <label class="purchase-settlement__field">
                        <span>Delivered to</span>
                        <input
                            type="text"
                            placeholder="Recipient name"
                            :value="params.formData?.delivered_to || ''"
                            autocomplete="off"
                            @input="persist({ delivered_to: ($event.target as HTMLInputElement).value })"
                        >
                    </label>

                    <label class="purchase-settlement__field">
                        <span>Bilty no</span>
                        <input
                            type="text"
                            placeholder="Bilty / consignment number"
                            :value="params.formData?.billty_no || ''"
                            autocomplete="off"
                            @input="persist({ billty_no: ($event.target as HTMLInputElement).value })"
                        >
                    </label>

                    <label class="purchase-settlement__field">
                        <span>Bilty date</span>
                        <input
                            type="date"
                            :value="params.formData?.billty_date || ''"
                            @input="persist({ billty_date: ($event.target as HTMLInputElement).value })"
                        >
                    </label>

                    <label class="purchase-settlement__field">
                        <span>Packing</span>
                        <input
                            type="text"
                            placeholder="Packing notes"
                            :value="params.formData?.packing || ''"
                            autocomplete="off"
                            @input="persist({ packing: ($event.target as HTMLInputElement).value })"
                        >
                    </label>
                </div>

                <div class="purchase-settlement__grid" style="margin-top: 0.85rem">
                    <label class="purchase-settlement__field">
                        <span>Bilty image</span>
                        <div class="d-flex align-items-center gap-2">
                            <button
                                type="button"
                                class="company-logo-choose"
                                @click="chooseImage"
                            >
                                <ImagePlus size="xs" />
                                <span>Choose</span>
                            </button>
                            <img
                                v-if="imagePreviewUrl"
                                :src="imagePreviewUrl"
                                alt="Bilty image"
                                class="company-logo-preview-img d-block rounded object-fit-contain"
                                style="height: 2.75rem"
                            >
                            <button
                                v-if="imagePreviewUrl"
                                type="button"
                                class="btn btn-sm btn-link text-danger px-0"
                                @click="clearBilltyImage"
                            >
                                Remove
                            </button>
                        </div>
                    </label>
                </div>

                <label class="purchase-settlement__field is-note">
                    <span>Additional note</span>
                    <textarea
                        rows="4"
                        placeholder="Delivery instructions, customer remarks, or other notes"
                        :value="params.formData?.additional_note || ''"
                        @input="persist({ additional_note: ($event.target as HTMLTextAreaElement).value })"
                    ></textarea>
                </label>
            </div>

            <aside class="purchase-summary">
                <div class="purchase-summary__head">
                    <p class="purchase-settlement__eyebrow">Summary</p>
                    <h6 class="purchase-settlement__heading">Sell total</h6>
                </div>

                <div class="purchase-summary__rows">
                    <div class="purchase-summary__row">
                        <span>Items</span>
                        <strong>{{ params.formData?.total_item || 0 }}</strong>
                    </div>
                    <div class="purchase-summary__row">
                        <span>Packing qty</span>
                        <strong>{{ params.formData?.total_pack_qty || 0 }}</strong>
                    </div>
                    <div class="purchase-summary__row">
                        <span>Net total</span>
                        <strong>{{ money(params.formData?.net_sub_total) }}</strong>
                    </div>
                    <div class="purchase-summary__row">
                        <span>Discount</span>
                        <strong class="is-muted">− {{ money(params.formData?.discount_val) }}</strong>
                    </div>
                    <div class="purchase-summary__row">
                        <span>Shipping</span>
                        <strong>{{ money(params.formData?.shipping_charges) }}</strong>
                    </div>
                    <div class="purchase-summary__row">
                        <span>Credit limit</span>
                        <strong>{{ money(params.formData?.credit_limit) }}</strong>
                    </div>
                </div>

                <div class="purchase-summary__due">
                    <span>Amount due</span>
                    <strong>{{ money(params.formData?.final_amount) }}</strong>
                </div>
            </aside>
        </div>
    </StaticElement>
</template>
