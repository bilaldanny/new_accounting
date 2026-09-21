<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { Palette, Settings2 } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import LabelSheet from './LabelSheet.vue';
    import PrintLabelFormChrome from './PrintLabelFormChrome.vue';
    import ProductLabelPicker from './ProductLabelPicker.vue';
    import type { LabelProductRow, LabelSettings } from './types';

    defineOptions({
        layout: {
            title: 'Print Label',
            subtitle: 'Design and print barcode labels for your products',
            breadcrumbs: [
                {
                    title: 'Print Label',
                    href: 'NULL',
                },
            ],
        },
    });

    const page = usePage();
    const { formatedText, fetchCompany, fetchBranch, companiesdata, branchesdata } = useCommons();

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
        currency_symbol?: string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const canManageBranch = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showBranchField = computed(() => canManageBranch.value && branchesdata.value.length > 1);

    const companyId = ref<string | number>(authUser.value?.company_id ?? '');
    const branchId = ref<string | number>(authUser.value?.branch_id ?? '');
    const scopeReady = computed(() => Boolean(companyId.value));
    const businessName = computed(() => {
        const match = companiesdata.value.find((company: { id: number | string }) => String(company.id) === String(companyId.value));

        return match?.text || match?.name || '';
    });

    const settings = ref<LabelSettings>({
        business_name: true,
        product_name: true,
        product_variation: true,
        product_price: true,
        show_price: 'exclusive',
        barcode_setting: 1,
        barcode_type: 'CODE128',
    });

    const barcodeTypes = [
        { value: 'CODE128', label: 'CODE128' },
        { value: 'EAN13', label: 'EAN-13' },
        { value: 'UPC', label: 'UPC' },
        { value: 'CODE39', label: 'CODE39' },
    ];

    const products = ref<LabelProductRow[]>([]);
    const suggestions = ref<any[]>([]);
    const searching = ref(false);
    const searchTimer = ref<ReturnType<typeof setTimeout> | null>(null);
    const isPrinting = ref(false);
    const printDisabled = computed(() => products.value.length === 0);
    const labelSheetRef = ref<InstanceType<typeof LabelSheet> | null>(null);

    async function loadBranches(id: string | number | null | undefined) {
        if (! canManageBranch.value || ! id) {
            branchesdata.value = [];

            return;
        }

        await fetchBranch(id);

        if (branchesdata.value.length === 1) {
            branchId.value = branchesdata.value[0].id;
        }
    }

    /**
     * Starts the label options from the company's Barcode Settings (Company Settings > Barcode Settings).
     * Nothing changes when they cannot be read: the page keeps its own defaults.
     */
    async function applyBarcodeDefaults(id: string | number | null | undefined) {
        if (! id) {
            return;
        }

        try {
            const response = await window.axios.get(API_ENDPOINTS.documentSettings('barcode'), { params: { company_id: id } });
            const saved = response.data?.values ?? {};

            settings.value = {
                ...settings.value,
                barcode_type: saved.barcode_type ?? settings.value.barcode_type,
                barcode_setting: saved.layout === 'compact' ? 2 : 1,
                show_price: saved.show_price ?? settings.value.show_price,
                business_name: saved.show_business_name ?? settings.value.business_name,
                product_name: saved.show_product_name ?? settings.value.product_name,
                product_variation: saved.show_variation ?? settings.value.product_variation,
                product_price: saved.show_product_price ?? settings.value.product_price,
            };
        } catch {
            // keep the page defaults
        }
    }

    async function handleCompanyChange() {
        branchId.value = '';
        products.value = [];
        await Promise.all([loadBranches(companyId.value), applyBarcodeDefaults(companyId.value)]);
    }

    async function fetchProductSuggestions(term: string) {
        if (! scopeReady.value) {
            suggestions.value = [];

            return;
        }

        searching.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.printLabelSearchProducts, {
                params: {
                    company_id: companyId.value,
                    branch_id: branchId.value,
                    search: term,
                },
            });
            suggestions.value = response.data ?? [];
        } catch {
            suggestions.value = [];
        } finally {
            searching.value = false;
        }
    }

    function searchProducts(term: string) {
        if (searchTimer.value) {
            clearTimeout(searchTimer.value);
        }

        if (! scopeReady.value || term.trim() === '') {
            suggestions.value = [];

            return;
        }

        searchTimer.value = setTimeout(() => {
            void fetchProductSuggestions(term);
        }, 220);
    }

    function addProduct(product: {
        id: number | string;
        product_id: number | string;
        product_name?: string;
        name: string;
        variation_name?: string;
        sku?: string;
        default_sell_price?: number | string;
    }) {
        const alreadyAdded = products.value.some((item) => (
            String(item.product_id) === String(product.product_id)
            && String(item.variation_id) === String(product.id)
        ));

        if (alreadyAdded) {
            return;
        }

        const variationName = String(product.variation_name || '').trim();

        products.value = [
            {
                product_id: product.product_id,
                variation_id: product.id,
                pro_name: product.product_name || product.name,
                var_name: variationName && variationName.toLowerCase() !== 'dummy' ? 'Variation' : '',
                value: variationName && variationName.toLowerCase() !== 'dummy' ? variationName : '',
                sku: product.sku || '',
                default_sell_price: Number(product.default_sell_price || 0),
                sell_price_inc_tax: Number(product.default_sell_price || 0),
                label: 1,
            },
            ...products.value,
        ];
    }

    function updateProduct(index: number, patch: Partial<LabelProductRow>) {
        products.value = products.value.map((item, itemIndex) => (
            itemIndex === index ? { ...item, ...patch } : item
        ));
    }

    function removeProduct(index: number) {
        products.value = products.value.filter((_, itemIndex) => itemIndex !== index);
    }

    async function printLabels() {
        if (printDisabled.value) {
            return;
        }

        isPrinting.value = true;
        labelSheetRef.value?.renderBarcodes?.();

        await new Promise((resolve) => setTimeout(resolve, 150));

        const cleanup = () => {
            document.body.classList.remove('is-printing-labels');
            window.removeEventListener('afterprint', cleanup);
            isPrinting.value = false;
        };

        document.body.classList.add('is-printing-labels');
        window.addEventListener('afterprint', cleanup);
        window.print();
    }

    onMounted(async () => {
        if (showCompanyField.value) {
            await fetchCompany();
        }

        if (companyId.value) {
            await Promise.all([loadBranches(companyId.value), applyBarcodeDefaults(companyId.value)]);
        }
    });
</script>

<template>
    <Head :title="formatedText('printlabel')" />

    <div class="product-form-page purchase-form-page">
        <PrintLabelFormChrome
            :is-busy="isPrinting"
            :is-working="isPrinting"
            :print-disabled="printDisabled"
            @print="printLabels"
        >
            <div class="product-form product-form--sectioned purchase-form">
                <div class="product-form-card printlabel-page__settings">
                    <div class="product-form-card__body">
                        <div class="product-form-section-head">
                            <span class="product-form-section-icon">
                                <Settings2 class="h-4 w-4" />
                            </span>
                            <div>
                                <h2 class="product-form-section-title">Label options</h2>
                                <p class="product-form-section-copy">Choose scope, what appears on each label, and the sheet layout</p>
                            </div>
                        </div>

                        <div class="printlabel-options-grid">
                            <div v-if="showCompanyField" class="admin-filter-field">
                                <label class="form-label" for="printlabel-company">Company</label>
                                <select id="printlabel-company" class="form-select form-select-sm" v-model="companyId" @change="handleCompanyChange">
                                    <option value="">Select company</option>
                                    <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                                        {{ company.text ?? company.name }}
                                    </option>
                                </select>
                            </div>

                            <div v-if="showBranchField" class="admin-filter-field">
                                <label class="form-label" for="printlabel-branch">Branch</label>
                                <select id="printlabel-branch" class="form-select form-select-sm" v-model="branchId">
                                    <option value="">Select branch</option>
                                    <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                                        {{ branch.text ?? branch.name }}
                                    </option>
                                </select>
                            </div>

                            <div class="admin-filter-field">
                                <label class="form-label" for="printlabel-price-mode">Price display</label>
                                <select id="printlabel-price-mode" class="form-select form-select-sm" v-model="settings.show_price">
                                    <option value="exclusive">Exclusive of tax</option>
                                    <option value="inclusive">Inclusive of tax</option>
                                </select>
                            </div>

                            <div class="admin-filter-field">
                                <label class="form-label" for="printlabel-layout">Sheet layout</label>
                                <select id="printlabel-layout" class="form-select form-select-sm" v-model.number="settings.barcode_setting">
                                    <option :value="1">Wide stickers</option>
                                    <option :value="2">Compact stickers</option>
                                </select>
                            </div>

                            <div class="admin-filter-field">
                                <label class="form-label" for="printlabel-barcode-type">Barcode format</label>
                                <select id="printlabel-barcode-type" class="form-select form-select-sm" v-model="settings.barcode_type">
                                    <option v-for="type in barcodeTypes" :key="type.value" :value="type.value">
                                        {{ type.label }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="printlabel-toggles">
                            <label class="printlabel-toggle">
                                <input type="checkbox" v-model="settings.business_name">
                                <span>Show business name</span>
                            </label>
                            <label class="printlabel-toggle">
                                <input type="checkbox" v-model="settings.product_name">
                                <span>Show product name</span>
                            </label>
                            <label class="printlabel-toggle">
                                <input type="checkbox" v-model="settings.product_variation">
                                <span>Show variation</span>
                            </label>
                            <label class="printlabel-toggle">
                                <input type="checkbox" v-model="settings.product_price">
                                <span>Show price</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="product-form-card printlabel-page__picker">
                    <div class="product-form-card__body">
                        <div class="product-form-section-head">
                            <span class="product-form-section-icon">
                                <Palette class="h-4 w-4" />
                            </span>
                            <div>
                                <h2 class="product-form-section-title">Products</h2>
                                <p class="product-form-section-copy">Search products and set how many labels to print for each</p>
                            </div>
                        </div>

                        <ProductLabelPicker
                            :products="products"
                            :suggestions="suggestions"
                            :searching="searching"
                            :disabled="!scopeReady"
                            @search="searchProducts"
                            @add="addProduct"
                            @update="updateProduct"
                            @remove="removeProduct"
                        />
                    </div>
                </div>

                <div class="product-form-card">
                    <div class="product-form-card__body">
                        <div class="product-form-section-head">
                            <div>
                                <h2 class="product-form-section-title">Preview</h2>
                                <p class="product-form-section-copy">This is exactly what will print</p>
                            </div>
                        </div>

                        <LabelSheet
                            ref="labelSheetRef"
                            :products="products"
                            :settings="settings"
                            :business-name="businessName"
                            :currency-symbol="authUser?.currency_symbol || ''"
                            currency-placement="before"
                        />
                    </div>
                </div>
            </div>
        </PrintLabelFormChrome>
    </div>
</template>

<style scoped>
.printlabel-options-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
    gap: 0.75rem 1rem;
    margin-bottom: 1rem;
}

.printlabel-toggles {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem 1.5rem;
}

.printlabel-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #334155;
}

.printlabel-toggle input {
    width: 1rem;
    height: 1rem;
    accent-color: var(--app-primary, #0d9488);
}
</style>
