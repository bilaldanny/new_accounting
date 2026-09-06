<script setup lang="ts">
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useSells, { type SellLineRow } from '@/composables/sell';
    import { Head, Link, router, usePage } from '@inertiajs/vue3';
    import debounce from '@/utils/debounce';
    import { computed, onMounted, ref, watch } from 'vue';

    defineOptions({
        layout: {
            title: 'POS',
            subtitle: 'Create a walk-in sale from the product grid',
            breadcrumbs: [
                {
                    title: 'Sell Management',
                    href: '/sell',
                },
                {
                    title: 'POS',
                    href: 'NULL',
                },
            ],
        },
    });

    const page = usePage();
    const { Notify, handleError, fetchCompany, fetchBranch, fetchCategory, fetchBrand, fetchItemType, companiesdata, branchesdata, categoriesdata, brandsdata, itemtypesdata, appUrl } = useCommons();
    const { formData, emptyForm } = useSells();

    type ProductCard = {
        id: number | string;
        product_id: number | string;
        itemtype_id?: number | string;
        name: string;
        product_name?: string;
        sku?: string;
        unit_id: number | string;
        default_sell_price: number | string;
        product_image_url?: string | null;
        current_stock?: number | string;
        units: SellLineRow['units'];
    };

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
    } | null);

    const roleName = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, ''));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');

    const customersdata = ref<Array<{ id: number | string; text?: string; business_name?: string }>>([]);
    const products = ref<ProductCard[]>([]);
    const searching = ref(false);
    const saving = ref(false);
    const productSearch = ref('');
    const brandId = ref('');
    const categoryId = ref('');
    const itemtypeId = ref('');
    const nowLabel = ref('');

    function toNumber(value: unknown, fallback = 0): number {
        const parsed = Number(value);

        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function pricedLine(line: SellLineRow): SellLineRow {
        const unitPrice = Math.max(toNumber(line.unit_price), 0);
        const discount = Math.max(toNumber(line.discount_percent), 0);
        const quantity = Math.max(toNumber(line.quantity, 1), 1);
        const packingQty = Math.max(toNumber(line.packing_qty, 1), 1);
        const priceAfterDiscount = Math.max(unitPrice - discount, 0);
        const subtotal = Number((priceAfterDiscount * quantity * packingQty).toFixed(2));

        return {
            ...line,
            quantity,
            packing_qty: packingQty,
            unit_price: unitPrice,
            discount_percent: discount,
            unit_price_after_discount: priceAfterDiscount,
            row_subtotal: subtotal,
            subtotal,
        };
    }

    const lines = computed(() => (formData.value.selllines ?? []) as SellLineRow[]);
    const netTotal = computed(() => lines.value.reduce((sum, line) => sum + toNumber(line.row_subtotal), 0));

    function persistLines(next: SellLineRow[]) {
        const priced = next.map(pricedLine);
        formData.value = {
            ...formData.value,
            selllines: priced,
            total_item: priced.length,
            net_sub_total: Number(netTotalFrom(priced).toFixed(2)),
            final_amount: Number(netTotalFrom(priced).toFixed(2)),
        };
    }

    function netTotalFrom(next: SellLineRow[]): number {
        return next.reduce((sum, line) => sum + toNumber(line.row_subtotal), 0);
    }

    function addProduct(product: ProductCard) {
        const alreadyAdded = lines.value.some((line) => (
            String(line.product_id) === String(product.product_id)
            && String(line.variation_id) === String(product.id)
        ));

        if (alreadyAdded) {
            return;
        }

        const unit = product.units?.[0];
        persistLines([
            pricedLine({
                product_id: product.product_id,
                variation_id: product.id,
                itemtype_id: product.itemtype_id,
                product_name: product.name || product.product_name || '',
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
                current_stock: unit?.unit_qty ?? product.current_stock ?? 0,
                unit_name: unit?.short_name ?? unit?.text,
            }),
            ...lines.value,
        ]);
    }

    function updateLine(index: number, patch: Partial<SellLineRow>) {
        persistLines(lines.value.map((line, lineIndex) => (
            lineIndex === index ? { ...line, ...patch } : line
        )));
    }

    function removeLine(index: number) {
        persistLines(lines.value.filter((_, lineIndex) => lineIndex !== index));
    }

    async function loadCustomers() {
        if (! formData.value.company_id || ! formData.value.branch_id) {
            customersdata.value = [];

            return;
        }

        const response = await window.axios.get(API_ENDPOINTS.fetchCustomers, {
            params: { company_id: formData.value.company_id, branch_id: formData.value.branch_id },
        });
        customersdata.value = response.data ?? [];
    }

    async function loadDefaultCustomer() {
        if (! formData.value.company_id) {
            return;
        }

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.companySettings}/${formData.value.company_id}`);
            const setting = response.data?.companySetting ?? response.data;
            const defaultCustomer = setting?.default_customer;

            if (defaultCustomer && ! formData.value.contact_id) {
                formData.value.contact_id = defaultCustomer;
            }
        } catch {
            // Keep the POS usable when company settings are missing.
        }
    }

    async function loadCatalog() {
        if (! formData.value.company_id) {
            return;
        }

        await fetchCategory(formData.value.company_id);
        await fetchBrand(formData.value.company_id);
        await fetchItemType(formData.value.company_id);
    }

    const loadProducts = debounce(async () => {
        if (! formData.value.company_id) {
            products.value = [];

            return;
        }

        searching.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.sells + '/search-products', {
                params: {
                    company_id: formData.value.company_id,
                    branch_id: formData.value.branch_id,
                    search: productSearch.value,
                    brand_id: brandId.value || undefined,
                    category_id: categoryId.value || undefined,
                    itemtype_id: itemtypeId.value || undefined,
                },
            });
            products.value = response.data ?? [];
        } catch {
            products.value = [];
        } finally {
            searching.value = false;
        }
    }, 250);

    async function handleCompanyChange() {
        formData.value.branch_id = '';
        formData.value.contact_id = '';
        persistLines([]);
        await fetchBranch(formData.value.company_id);
        await loadCatalog();
        await loadProducts();
    }

    async function handleBranchChange() {
        formData.value.contact_id = '';
        persistLines([]);
        await loadCustomers();
        await loadDefaultCustomer();
        await loadProducts();
    }

    function productImage(product: ProductCard): string {
        if (product.product_image_url) {
            return product.product_image_url;
        }

        return `${String(appUrl ?? '').replace(/\/$/, '')}/assets/images/placeholder.png`;
    }

    function canAdd(product: ProductCard): boolean {
        return toNumber(product.current_stock ?? product.units?.[0]?.unit_qty) > 0;
    }

    async function saveSale() {
        if (! formData.value.contact_id || lines.value.length === 0) {
            Notify('Select a customer and add at least one product', 'alert');

            return;
        }

        saving.value = true;

        try {
            await window.axios.post(API_ENDPOINTS.sells, {
                ...formData.value,
                status: 'final',
                payment_status: 'due',
                selllines: lines.value,
                final_amount: Number(netTotal.value.toFixed(2)),
                total_item: lines.value.length,
            });
            Notify('Successfully Saved');
            router.visit('/sell');
        } catch (error) {
            handleError(error);
        } finally {
            saving.value = false;
        }
    }

    onMounted(async () => {
        nowLabel.value = new Date().toLocaleString();
        formData.value = {
            ...emptyForm(),
            status: 'final',
            ...(isSuperadmin.value
                ? {}
                : isCompanyadmin.value
                    ? { company_id: String(authUser.value?.company_id ?? '') }
                    : {
                        company_id: String(authUser.value?.company_id ?? ''),
                        branch_id: String(authUser.value?.branch_id ?? ''),
                    }),
        };

        if (isSuperadmin.value) {
            await fetchCompany();
        }

        if ((isSuperadmin.value || isCompanyadmin.value) && formData.value.company_id) {
            await fetchBranch(formData.value.company_id);
        }

        if (formData.value.company_id) {
            await loadCatalog();
            await loadCustomers();
            await loadDefaultCustomer();
            await loadProducts();
        }
    });

    watch([productSearch, brandId, categoryId, itemtypeId], () => {
        loadProducts();
    });
</script>

<template>
    <Head title="POS" />

    <div class="pos-page">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <p class="mb-0">
                <strong>Location:</strong>
                {{ branchesdata.find((branch) => String(branch.id) === String(formData.branch_id))?.text || branchesdata.find((branch) => String(branch.id) === String(formData.branch_id))?.name || '—' }}
                &nbsp; <span>{{ nowLabel }}</span>
            </p>
            <div class="d-flex gap-1 flex-wrap">
                <Link href="/sell/return/add" class="btn btn-sm btn-danger" title="Sell Return">Return</Link>
                <Link href="/sell" class="btn btn-sm btn-secondary">Go Back</Link>
                <button type="button" class="btn btn-sm btn-primary" :disabled="saving" @click="saveSale">
                    {{ saving ? 'Saving…' : 'Complete Sale' }}
                </button>
            </div>
        </div>

        <div class="row g-2">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-body">
                        <div class="row g-2">
                            <div v-if="isSuperadmin" class="col-md-6">
                                <select class="form-select form-select-sm" v-model="formData.company_id" @change="handleCompanyChange">
                                    <option value="">Select Company</option>
                                    <option v-for="company in companiesdata" :key="company.id" :value="company.id">{{ company.text ?? company.name }}</option>
                                </select>
                            </div>
                            <div v-if="isSuperadmin || isCompanyadmin" class="col-md-6">
                                <select class="form-select form-select-sm" v-model="formData.branch_id" :disabled="!formData.company_id" @change="handleBranchChange">
                                    <option value="">Select Branch</option>
                                    <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">{{ branch.text ?? branch.name }}</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <select class="form-select form-select-sm" v-model="formData.contact_id" :disabled="!formData.company_id || !formData.branch_id">
                                    <option value="">Select Customer</option>
                                    <option v-for="customer in customersdata" :key="customer.id" :value="customer.id">{{ customer.text ?? customer.business_name }}</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <input class="form-control form-control-sm" placeholder="Reference No (Leave empty to autogenerate.)" v-model="formData.invoice_no">
                            </div>
                            <div class="col-12">
                                <input class="form-control form-control-sm" placeholder="Enter Product name / SKU / Scan bar code" v-model="productSearch">
                            </div>
                        </div>

                        <div class="table-responsive mt-3">
                            <table class="table table-bordered mb-0">
                                <thead>
                                    <tr>
                                        <th>S.No</th>
                                        <th>Product</th>
                                        <th>Quantity</th>
                                        <th>Price</th>
                                        <th>Subtotal</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(line, index) in lines" :key="`${line.product_id}-${line.variation_id}-${index}`">
                                        <td>{{ index + 1 }}</td>
                                        <td>{{ line.product_name }}<br><small>{{ line.sku }}</small></td>
                                        <td>
                                            <input type="number" min="1" class="form-control form-control-sm" :value="line.quantity" @input="updateLine(index, { quantity: ($event.target as HTMLInputElement).value })">
                                            <small>{{ line.unit_name }}</small>
                                        </td>
                                        <td>
                                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" :value="line.unit_price_after_discount" @input="updateLine(index, { unit_price: ($event.target as HTMLInputElement).value, unit_price_after_discount: ($event.target as HTMLInputElement).value })">
                                        </td>
                                        <td class="text-end">{{ Number(line.row_subtotal).toFixed(2) }}</td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-link text-danger p-0" @click="removeLine(index)">Remove</button>
                                        </td>
                                    </tr>
                                    <tr v-if="! lines.length">
                                        <td colspan="6" class="text-center">Scan or select a product</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between mt-3">
                            <strong>Total Items: {{ lines.length }}</strong>
                            <strong>Total: {{ netTotal.toFixed(2) }}</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" v-model="brandId" :disabled="!formData.company_id">
                            <option value="">All Brand</option>
                            <option v-for="brand in brandsdata" :key="brand.id" :value="brand.id">{{ brand.text ?? brand.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" v-model="categoryId" :disabled="!formData.company_id">
                            <option value="">All Category</option>
                            <option v-for="category in categoriesdata" :key="category.id" :value="category.id">{{ category.text ?? category.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" v-model="itemtypeId" :disabled="!formData.company_id">
                            <option value="">All Item Type</option>
                            <option v-for="itemtype in itemtypesdata" :key="itemtype.id" :value="itemtype.id">{{ itemtype.text ?? itemtype.name }}</option>
                        </select>
                    </div>
                </div>

                <div class="pos-grid">
                    <p v-if="searching" class="text-muted">Loading products…</p>
                    <button
                        v-for="product in products"
                        :key="String(product.id)"
                        type="button"
                        class="pos-card"
                        :disabled="! canAdd(product)"
                        @click="addProduct(product)"
                    >
                        <img :src="productImage(product)" :alt="product.name">
                        <strong>{{ product.name || product.product_name }}</strong>
                        <small>{{ Number(product.default_sell_price || 0).toFixed(2) }}</small>
                        <small>Stock: {{ product.current_stock ?? product.units?.[0]?.unit_qty ?? 0 }}</small>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.pos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 0.6rem;
    max-height: 70vh;
    overflow: auto;
}

.pos-card {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    border: 1px solid #dee2e6;
    border-radius: 0.4rem;
    background: #fff;
    padding: 0.4rem;
    text-align: left;
}

.pos-card img {
    width: 100%;
    height: 70px;
    object-fit: cover;
    border-radius: 0.25rem;
}

.pos-card:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
