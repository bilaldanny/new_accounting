<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { ClipboardList, Tag } from '@lucide/vue';
    import { computed, onMounted, ref, watch } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import type { PriceListLineRow } from '@/composables/pricelist';
    import PriceListLinesEditor from './PriceListLinesEditor.vue';

    const params = defineProps({
        type: String,
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
    const colThird = { container: 4, label: 12, wrapper: 12 };
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

    const statusItems = [
        { value: 'pending', label: 'Pending' },
        { value: 'approved', label: 'Approved' },
    ];

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const canManageBranch = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showBranchField = computed(() => canManageBranch.value && branchesdata.value.length > 1);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const showHiddenBranchField = computed(() => ! showBranchField.value);
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const branchDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);

    const { fetchCompany, fetchBranch, fetchBrand, companiesdata, branchesdata, brandsdata } = useCommons();

    const productSuggestions = ref<any[]>([]);
    const searchingProducts = ref(false);
    const lastFetchedCompanyId = ref('');
    const searchTimer = ref<ReturnType<typeof setTimeout> | null>(null);

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedBranchId = computed(() => params.formData?.branch_id ?? '');
    const selectedBrandId = computed(() => params.formData?.brand_id ?? '');
    const scopeReady = computed(() => Boolean(normalizeId(selectedBrandId.value)));
    const priceLines = computed<PriceListLineRow[]>(() => (
        Array.isArray(params.formData?.pricelistdetails) ? params.formData.pricelistdetails : []
    ));
    const lineCountLabel = computed(() => {
        const count = priceLines.value.length;

        return count === 1 ? '1 product' : `${count} products`;
    });

    function persist(patch: Record<string, unknown>) {
        if (params.formData) {
            Object.assign(params.formData, patch);
        }

        params.formRef?.update?.(patch);
    }

    function persistLines(lines: PriceListLineRow[]) {
        persist({ pricelistdetails: lines.map((line) => ({ ...line })) });
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

    async function loadBranchOptions(companyId: string | number | null | undefined) {
        if (! canManageBranch.value) {
            return;
        }

        const normalizedCompanyId = normalizeId(companyId);

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

        persist({ branch_id: '' });
        lastFetchedCompanyId.value = '';
        await loadBranchOptions(companyId);
    }

    async function fetchProductSuggestions(term = '') {
        if (! scopeReady.value) {
            productSuggestions.value = [];

            return;
        }

        searchingProducts.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.priceListSearchProducts, {
                params: {
                    company_id: selectedCompanyId.value,
                    branch_id: selectedBranchId.value,
                    brand_id: selectedBrandId.value,
                    search: term,
                },
            });
            productSuggestions.value = response.data ?? [];
        } catch {
            productSuggestions.value = [];
        } finally {
            searchingProducts.value = false;
        }
    }

    function searchProducts(term: string) {
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
        name: string;
        product_name?: string;
        sku?: string;
        unit_id: number | string;
        units: PriceListLineRow['units'];
        default_purchase_price?: number | string;
        default_sell_price?: number | string;
        profit_percent?: number | string;
    }) {
        const alreadyAdded = priceLines.value.some((line) => (
            String(line.product_id) === String(product.product_id)
            && String(line.variation_id) === String(product.id)
        ));

        if (alreadyAdded) {
            return;
        }

        const unit = product.units?.[0];
        const nextLine: PriceListLineRow = {
            product_id: product.product_id,
            variation_id: product.id,
            product_name: product.product_name || product.name,
            sku: product.sku,
            unit_id: product.unit_id,
            unit_name: unit?.short_name ?? unit?.text,
            purchase_price: product.default_purchase_price ?? 0,
            sell_price: product.default_sell_price ?? 0,
            profit_margin: product.profit_percent ?? 0,
            discount: 0,
            units: product.units ?? [],
        };

        persistLines([nextLine, ...priceLines.value]);
    }

    function updateLine(index: number, patch: Partial<PriceListLineRow>) {
        persistLines(priceLines.value.map((line, lineIndex) => (
            lineIndex === index ? { ...line, ...patch } : line
        )));
    }

    function removeLine(index: number) {
        persistLines(priceLines.value.filter((_, lineIndex) => lineIndex !== index));
    }

    onMounted(async () => {
        applyScopedDefaults();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        const companyId = isSuperadmin.value ? selectedCompanyId.value : authUser.value?.company_id;

        if (companyId) {
            await loadBranchOptions(companyId);
            await fetchBrand(companyId);
        }
    });

    watch(
        () => normalizeId(params.formData?.company_id),
        async (companyId, previousCompanyId) => {
            if (companyId === previousCompanyId) {
                return;
            }

            await handleCompanyChange(companyId || undefined);
            await fetchBrand(companyId || undefined);
        },
    );

    watch(
        () => normalizeId(params.formData?.brand_id),
        () => {
            productSuggestions.value = [];
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />
    <TextElement v-if="showHiddenBranchField" name="branch_id" hidden="true" />

    <GroupElement name="group_details" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_details" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <ClipboardList class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Price list details</h2>
                    <p class="product-form-section-copy">Brand, effective date, and approval status</p>
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
            rules="required"
            info="Products are priced per brand."
        />

        <DateElement
            id="PriceListDate"
            field-name="PriceListDate"
            name="date"
            label="Effective date"
            placeholder="Select date"
            :columns="colThird"
            :floating="false"
            rules="required"
        />

        <TextElement
            id="HeaderDiscount"
            field-name="HeaderDiscount"
            name="discount"
            label="Overall Discount %"
            placeholder="0.00"
            input-type="number"
            :columns="colThird"
            autocomplete="off"
        />

        <SelectElement
            name="status"
            :native="false"
            :items="statusItems"
            id="PriceListStatus"
            field-name="PriceListStatus"
            placeholder="Select status"
            label="Status"
            :columns="colThird"
            label-prop="label"
            value-prop="value"
            :search="false"
            :floating="false"
            :can-clear="false"
            info="Approved price lists can be used for selling price lookups."
        />
    </GroupElement>

    <GroupElement name="group_items" :columns="colFull" :add-classes="linesCardClasses">
        <StaticElement name="section_items" :columns="colFull">
            <div class="product-form-section-head journal-card-head">
                <div class="journal-card-head__lead">
                    <span class="product-form-section-icon">
                        <Tag class="h-4 w-4" />
                    </span>
                    <div>
                        <h2 class="product-form-section-title">Product prices</h2>
                        <p class="product-form-section-copy">Search products from the selected brand and set their prices</p>
                    </div>
                </div>
                <span class="purchase-form__count">{{ lineCountLabel }}</span>
            </div>
        </StaticElement>

        <StaticElement name="lines_editor" :columns="colFull">
            <PriceListLinesEditor
                :lines="priceLines"
                :suggestions="productSuggestions"
                :searching="searchingProducts"
                :disabled="!scopeReady"
                @search="searchProducts"
                @add="addProduct"
                @update="updateLine"
                @remove="removeLine"
            />
        </StaticElement>
    </GroupElement>
</template>
