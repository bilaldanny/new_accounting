<script setup lang="ts">
    import { Head, Link, router, usePage } from '@inertiajs/vue3';
    import {
        ArrowLeft,
        Banknote,
        Briefcase,
        Calculator,
        ClipboardList,
        CreditCard,
        FileText,
        History,
        LayoutGrid,
        Maximize2,
        Minus,
        Monitor,
        Package,
        PauseCircle,
        Plus,
        PlusCircle,
        RefreshCw,
        Search,
        ShoppingCart,
        Split,
        Star,
        Tag,
        Trash2,
        Undo2,
        UserPlus,
        X,
        XSquare,
    } from '@lucide/vue';
    import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useSells from '@/composables/sell';
    import type { SellLineRow } from '@/composables/sell';
    import useSellPayments from '@/composables/sellPayment';
    import debounce from '@/utils/debounce';
    import PaymentModal from './payment/add.vue';
    import SaleIncentivesPanel from './SaleIncentivesPanel.vue';

    // This page renders without AppLayout (see resources/js/app.ts) so the POS
    // screen can use the full viewport like a kiosk, with no app header or sidebar.

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

    type SuspendedSale = {
        id: number;
        savedAt: string;
        label: string;
        itemCount: number;
        total: number;
        snapshot: Record<string, unknown>;
    };

    type DisplaySnapshot = {
        businessName: string;
        customerName: string;
        lines: Array<{ name: string; quantity: number; unit_price: number; row_subtotal: number }>;
        totals: { itemCount: number; netTotal: number; discount: number; shipping: number; finalAmount: number };
    };

    const page = usePage();
    const swal = inject<any>('$swal', null);
    const {
        Notify,
        handleError,
        fetchCompany,
        fetchBranch,
        fetchCategory,
        fetchBrand,
        companiesdata,
        branchesdata,
        categoriesdata,
        brandsdata,
        appUrl,
    } = useCommons();
    const { formData, emptyForm, getSells, state: recentState } = useSells();
    const {
        formData: paymentFormData,
        emptyForm: emptyPaymentForm,
    } = useSellPayments();

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        fullname?: string;
        company_id?: number | string | null;
        company_name?: string | null;
        branch_id?: number | string | null;
    } | null);

    const roleName = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, ''));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const canManageBranch = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showBranchField = computed(() => canManageBranch.value && branchesdata.value.length > 1);
    const scopeReady = computed(() => Boolean(formData.value.company_id && formData.value.branch_id));
    const branchDisabled = computed(() => isSuperadmin.value && ! formData.value.company_id);

    const pageReady = ref(false);
    const saving = ref(false);
    const customersdata = ref<Array<{ id: number | string; text?: string; business_name?: string }>>([]);
    const products = ref<ProductCard[]>([]);
    const searchingGrid = ref(false);
    const productSearch = ref('');
    const brandId = ref('');
    const categoryId = ref('');
    const activeTab = ref<'all' | 'category' | 'brand' | 'featured'>('all');
    const showCategoryPanel = ref(false);
    const showBrandPanel = ref(false);
    const nowLabel = ref('');
    let clockTimer: ReturnType<typeof setInterval> | null = null;
    const allowPackingEdit = ref(false);
    const askUnitBeforeAdd = ref(false);

    const unitPrompt = reactive<{ open: boolean; product: ProductCard | null }>({
        open: false,
        product: null,
    });

    const showCalculator = ref(false);
    const showRegisterInfo = ref(false);
    const showSuspendedPanel = ref(false);
    const showRecentPanel = ref(false);
    const recentLoading = ref(false);
    const isFullscreen = ref(false);
    const suspendedSales = ref<SuspendedSale[]>([]);
    const paymentModalMode = ref<'card' | 'split'>('card');
    const paymentModalLoading = ref(true);

    const branchLabel = computed(() => (
        branchesdata.value.find((branch: any) => String(branch.id) === String(formData.value.branch_id))?.text
        || branchesdata.value.find((branch: any) => String(branch.id) === String(formData.value.branch_id))?.name
        || '—'
    ));

    const companyLabel = computed(() => (
        authUser.value?.company_name
        || companiesdata.value.find((company: any) => String(company.id) === String(formData.value.company_id))?.text
        || '—'
    ));

    function toNumber(value: unknown, fallback = 0): number {
        const parsed = Number(value);

        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function money(value: unknown): string {
        return toNumber(value).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    async function confirmAction(title: string, text: string): Promise<boolean> {
        if (swal) {
            const result = await swal.fire({
                title,
                text,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                customClass: { confirmButton: 'btn btn-success ms-3', cancelButton: 'btn btn-danger' },
                buttonsStyling: false,
            });

            return Boolean(result.isConfirmed);
        }

        return window.confirm(`${title}\n${text}`);
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
    const itemCountLabel = computed(() => {
        const count = lines.value.length;

        return count === 1 ? '1 item' : `${count} items`;
    });
    const discountAmountDisabled = computed(() => String(formData.value.discount_type || 'none') === 'none');

    function recalculateTotals(next: SellLineRow[] = lines.value) {
        const netSubTotal = next.reduce((sum, line) => sum + toNumber(line.row_subtotal), 0);
        const discountType = String(formData.value.discount_type || 'none');
        const discountAmount = toNumber(formData.value.discount_amount);
        let discountVal = 0;

        if (discountType === 'percentage') {
            discountVal = (netSubTotal / 100) * discountAmount;
        } else if (discountType === 'fixed') {
            discountVal = discountAmount;
        }

        Object.assign(formData.value, {
            net_sub_total: Number(netSubTotal.toFixed(2)),
            discount_val: Number(discountVal.toFixed(2)),
            final_amount: Number(Math.max(netSubTotal + toNumber(formData.value.shipping_charges) - discountVal - toNumber(formData.value.coupon_discount_amount), 0).toFixed(2)),
        });
    }

    function persistLines(next: SellLineRow[]) {
        const priced = next.map(pricedLine);
        Object.assign(formData.value, {
            selllines: priced,
            total_item: priced.length,
            total_pack_qty: priced.reduce((sum, line) => sum + toNumber(line.packing_qty), 0),
        });
        recalculateTotals(priced);
    }

    function buildLine(product: ProductCard, unit: SellLineRow['units'][number] | undefined): SellLineRow {
        return pricedLine({
            product_id: product.product_id,
            variation_id: product.id,
            itemtype_id: product.itemtype_id,
            product_name: product.name || product.product_name || '',
            sku: product.sku,
            unit_id: unit?.id ?? product.unit_id,
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
        });
    }

    function isAlreadyAdded(product: ProductCard, unitId: number | string): boolean {
        return lines.value.some((line) => (
            String(line.product_id) === String(product.product_id)
            && String(line.variation_id) === String(product.id)
            && String(line.unit_id) === String(unitId)
        ));
    }

    function addProductWithUnit(product: ProductCard, unit: SellLineRow['units'][number] | undefined) {
        if (isAlreadyAdded(product, unit?.id ?? product.unit_id)) {
            Notify('This product & unit is already in the cart', 'info');

            return;
        }

        persistLines([buildLine(product, unit), ...lines.value]);
    }

    function addProduct(product: ProductCard) {
        if (askUnitBeforeAdd.value && (product.units?.length ?? 0) > 1) {
            unitPrompt.product = product;
            unitPrompt.open = true;

            return;
        }

        addProductWithUnit(product, product.units?.[0]);
    }

    function chooseUnit(unit: SellLineRow['units'][number]) {
        if (unitPrompt.product) {
            addProductWithUnit(unitPrompt.product, unit);
        }

        unitPrompt.open = false;
        unitPrompt.product = null;
    }

    function closeUnitPrompt() {
        unitPrompt.open = false;
        unitPrompt.product = null;
    }

    function updateLine(index: number, patch: Partial<SellLineRow>) {
        persistLines(lines.value.map((line, lineIndex) => (
            lineIndex === index ? pricedLine({ ...line, ...patch }) : line
        )));
    }

    function stepQuantity(index: number, delta: number) {
        const line = lines.value[index];

        if (! line) {
            return;
        }

        const next = Math.max(1, toNumber(line.quantity, 1) + delta);
        const stock = toNumber(line.current_stock, 0);

        // The POS never sells more than is in stock; the server enforces it too.
        if (delta > 0 && stock > 0 && next > stock) {
            Notify(`Only ${stock} in stock`, 'alert');

            return;
        }

        updateLine(index, { quantity: next });
    }

    function removeLine(index: number) {
        persistLines(lines.value.filter((_, lineIndex) => lineIndex !== index));
    }

    function unitLabel(line: SellLineRow): string {
        return line.unit_name || line.units?.find((unit) => String(unit.id) === String(line.unit_id))?.short_name || '';
    }

    async function loadCustomers() {
        if (! scopeReady.value) {
            customersdata.value = [];

            return;
        }

        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchCustomers, {
                params: { company_id: formData.value.company_id, branch_id: formData.value.branch_id },
            });
            customersdata.value = response.data ?? [];
        } catch {
            customersdata.value = [];
        }
    }

    async function loadCompanySettings() {
        if (! formData.value.company_id) {
            return;
        }

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.companySettings}/${formData.value.company_id}`);
            const setting = response.data?.companySetting ?? response.data;
            allowPackingEdit.value = Boolean(setting?.update_packing_qty);
            askUnitBeforeAdd.value = String(setting?.default_pos_unit ?? '0') === '1';

            if (setting?.default_customer && ! formData.value.contact_id) {
                formData.value.contact_id = setting.default_customer;
            }
        } catch {
            // Keep the POS usable when company settings are missing.
        }
    }

    async function loadCatalogFilters() {
        if (! scopeReady.value) {
            return;
        }

        await Promise.all([
            fetchCategory(formData.value.company_id),
            fetchBrand(formData.value.company_id),
        ]);
    }

    const loadProducts = debounce(async () => {
        if (! scopeReady.value) {
            products.value = [];

            return;
        }

        searchingGrid.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.sellSearchProducts, {
                params: {
                    company_id: formData.value.company_id,
                    branch_id: formData.value.branch_id,
                    search: productSearch.value,
                    brand_id: brandId.value || undefined,
                    category_id: categoryId.value || undefined,
                },
            });
            products.value = response.data ?? [];
        } catch {
            products.value = [];
        } finally {
            searchingGrid.value = false;
        }
    }, 250);

    function selectCategory(id: number | string) {
        categoryId.value = String(id);
        brandId.value = '';
        activeTab.value = 'category';
        showCategoryPanel.value = false;
    }

    function selectBrand(id: number | string) {
        brandId.value = String(id);
        categoryId.value = '';
        activeTab.value = 'brand';
        showBrandPanel.value = false;
    }

    function showAllProducts() {
        categoryId.value = '';
        brandId.value = '';
        activeTab.value = 'all';
        showCategoryPanel.value = false;
        showBrandPanel.value = false;
    }

    function showFeaturedProducts() {
        categoryId.value = '';
        brandId.value = '';
        activeTab.value = 'featured';
        showCategoryPanel.value = false;
        showBrandPanel.value = false;
    }

    function toggleCategoryPanel() {
        showCategoryPanel.value = ! showCategoryPanel.value;
        showBrandPanel.value = false;
    }

    function toggleBrandPanel() {
        showBrandPanel.value = ! showBrandPanel.value;
        showCategoryPanel.value = false;
    }

    function onSearchEnter() {
        const term = productSearch.value.trim().toLowerCase();

        if (! term) {
            return;
        }

        const exact = products.value.find((product) => (
            String(product.sku ?? '').toLowerCase() === term
        ));

        if (exact && canAdd(exact)) {
            addProduct(exact);
            productSearch.value = '';
        }
    }

    async function handleCompanyChange() {
        formData.value.branch_id = '';
        formData.value.contact_id = '';
        persistLines([]);
        showAllProducts();

        if (canManageBranch.value) {
            await fetchBranch(formData.value.company_id);

            if (branchesdata.value.length === 1) {
                formData.value.branch_id = branchesdata.value[0].id;
            }
        }

        await loadCompanySettings();
    }

    async function handleBranchChange() {
        formData.value.contact_id = '';
        persistLines([]);
        loadSuspendedSales();
        await loadCustomers();
        await loadCatalogFilters();
        await loadProducts();
    }

    function productImage(product: ProductCard): string {
        if (product.product_image_url) {
            return product.product_image_url;
        }

        return `${String(appUrl ?? '').replace(/\/$/, '')}/assets/images/no_image.png`;
    }

    function productStock(product: ProductCard): number {
        return toNumber(product.current_stock ?? product.units?.[0]?.unit_qty);
    }

    function canAdd(product: ProductCard): boolean {
        return productStock(product) > 0;
    }

    function persistPatch(patch: Record<string, unknown>) {
        Object.assign(formData.value, patch);
    }

    function resetSale() {
        formData.value = {
            ...emptyForm(),
            status: 'final',
            company_id: formData.value.company_id,
            branch_id: formData.value.branch_id,
            contact_id: '',
        };
    }

    async function cancelSale() {
        if (lines.value.length > 0 || formData.value.contact_id) {
            const confirmed = await confirmAction('Cancel this sale?', 'The current cart will be cleared.');

            if (! confirmed) {
                return;
            }
        }

        resetSale();
    }

    async function createSellRecord(status: 'final' | 'draft' | 'quotation'): Promise<{ id: number; invoice_no: string; final_amount: number; remaining_amount: number } | null> {
        if (! formData.value.company_id || ! formData.value.branch_id) {
            Notify('Select a company and branch', 'alert');

            return null;
        }

        if (! formData.value.contact_id) {
            Notify('Select a customer', 'alert');

            return null;
        }

        if (lines.value.length === 0) {
            Notify('Add at least one product to the cart', 'alert');

            return null;
        }

        saving.value = true;

        try {
            const response = await window.axios.post(API_ENDPOINTS.sells, {
                ...formData.value,
                ...(status === 'final' ? {} : { gift_card_code: '', gift_card_amount: 0 }),
                status,
                payment_status: 'due',
                is_pos: true,
            });

            if (response.data?.errormessage) {
                handleError({ response }, { type: 'submit' });

                return null;
            }

            return {
                id: Number(response.data?.id),
                invoice_no: String(response.data?.invoice_no ?? ''),
                final_amount: Number(response.data?.final_amount ?? formData.value.final_amount ?? 0),
                remaining_amount: Number(response.data?.remaining_amount ?? response.data?.final_amount ?? formData.value.final_amount ?? 0),
            };
        } catch (error) {
            // `type: 'submit'` is what shows the server's validation message (credit limit,
            // insufficient stock) instead of a generic "couldn't submit" toast.
            handleError(error, { type: 'submit' });

            return null;
        } finally {
            saving.value = false;
        }
    }

    /**
     * Prints the receipt of a finished sale when the company's Receipt Printer Settings ask for it
     * ("Print automatically after a sale"). The receipt page opens in a hidden frame and prints itself, so no
     * pop-up is needed and the cashier stays on the POS. A failure here never affects the sale.
     */
    async function printReceiptIfEnabled(saleId: number) {
        const companyId = formData.value.company_id;

        if (! saleId) {
            return;
        }

        try {
            const response = await window.axios.get(API_ENDPOINTS.documentSettings('receipt'), { params: { company_id: companyId } });

            if (! response.data?.values?.auto_print) {
                return;
            }
        } catch {
            return;
        }

        const frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
        frame.src = `/sell/${saleId}/receipt?autoprint=1`;
        document.body.appendChild(frame);
        window.setTimeout(() => frame.remove(), 120000);
    }

    async function checkoutDraft() {
        const sell = await createSellRecord('draft');

        if (! sell) {
            return;
        }

        Notify(`Saved as draft (${sell.invoice_no})`, 'success');
        resetSale();
    }

    async function checkoutQuotation() {
        const sell = await createSellRecord('quotation');

        if (! sell) {
            return;
        }

        Notify(`Saved as quotation (${sell.invoice_no})`, 'success');
        resetSale();
    }

    async function checkoutCreditSale() {
        const sell = await createSellRecord('final');

        if (! sell) {
            return;
        }

        Notify(`Sale completed on credit (${sell.invoice_no})`, 'success');
        broadcastDisplay('completed', { totalPaying: sell.final_amount - sell.remaining_amount, changeReturn: 0, balance: sell.remaining_amount });
        void printReceiptIfEnabled(sell.id);
        resetSale();
    }

    async function checkoutCash() {
        const sell = await createSellRecord('final');

        if (! sell) {
            return;
        }

        if (sell.remaining_amount <= 0) {
            Notify(`Sale completed (${sell.invoice_no}) — paid in full by gift card`, 'success');
            broadcastDisplay('completed', { totalPaying: sell.final_amount, changeReturn: 0, balance: 0 });
            void printReceiptIfEnabled(sell.id);
            resetSale();

            return;
        }

        try {
            const accountsResponse = await window.axios.get(API_ENDPOINTS.sellPaymentAccounts, {
                params: { company_id: formData.value.company_id, branch_id: formData.value.branch_id },
            });
            const accounts = accountsResponse.data ?? [];
            const cashAccount = accounts.find((account: any) => /cash/i.test(String(account.text ?? account.name ?? ''))) ?? accounts[0];

            if (! cashAccount) {
                Notify(`Sale ${sell.invoice_no} saved as due — no cash/bank account is configured to record the payment`, 'alert');
                broadcastDisplay('completed', { totalPaying: 0, changeReturn: 0, balance: sell.final_amount });
                resetSale();

                return;
            }

            await window.axios.post(API_ENDPOINTS.sellPayments, {
                company_id: formData.value.company_id,
                branch_id: formData.value.branch_id,
                transaction_id: sell.id,
                amount: sell.remaining_amount,
                paid_on: new Date().toISOString().slice(0, 10),
                method: 'cash',
                payment_account: cashAccount.id,
            });

            Notify(`Cash sale completed (${sell.invoice_no})`, 'success');
            broadcastDisplay('completed', { totalPaying: sell.final_amount, changeReturn: 0, balance: 0 });
            void printReceiptIfEnabled(sell.id);
        } catch (error) {
            handleError(error);
            Notify(`Sale ${sell.invoice_no} was saved but the cash payment could not be recorded — record it from Sell Payments`, 'alert');
        } finally {
            resetSale();
        }
    }

    async function openPaymentModal(mode: 'card' | 'split') {
        const sell = await createSellRecord('final');

        if (! sell) {
            return;
        }

        if (sell.remaining_amount <= 0) {
            Notify(`Sale completed (${sell.invoice_no}) — paid in full by gift card`, 'success');
            void printReceiptIfEnabled(sell.id);
            resetSale();

            return;
        }

        pendingPaymentSnapshot.value = buildDisplaySnapshot();
        paymentModalMode.value = mode;
        paymentModalLoading.value = true;
        paymentFormData.value = {
            ...emptyPaymentForm(),
            company_id: formData.value.company_id,
            branch_id: formData.value.branch_id,
            contact_id: formData.value.contact_id,
            transaction_id: sell.id,
            invoice_no: sell.invoice_no,
            final_amount: sell.final_amount,
            remaining_amount: sell.remaining_amount,
            amount: sell.remaining_amount,
            method: mode === 'card' ? 'card' : 'cash',
        };

        resetSale();
        await nextTick();
        paymentModalLoading.value = false;
        (window as any).bootstrap?.Modal.getOrCreateInstance(document.getElementById('PosPaymentModal'))?.show();
    }

    function handlePaymentModalClose() {
        paymentModalLoading.value = true;
    }

    function handlePaymentSuccess(response: unknown) {
        const message = (response as any)?.data?.message || 'Payment recorded';
        Notify(message, 'success');

        if (pendingPaymentSnapshot.value) {
            const totalPaying = toNumber(paymentFormData.value.amount);
            const finalAmount = toNumber(paymentFormData.value.final_amount);
            broadcastDisplay('completed', {
                totalPaying,
                changeReturn: 0,
                balance: Math.max(finalAmount - totalPaying, 0),
            }, pendingPaymentSnapshot.value);
            pendingPaymentSnapshot.value = null;

            if (totalPaying >= finalAmount) {
                void printReceiptIfEnabled(Number(paymentFormData.value.transaction_id));
            }
        }
    }

    function handlePaymentError(error: unknown, details?: unknown) {
        handleError(error, details);
    }

    /* Fullscreen */
        function toggleFullscreen() {
            if (! document.fullscreenElement) {
                document.documentElement.requestFullscreen?.().catch(() => {});
            } else {
                document.exitFullscreen?.().catch(() => {});
            }
        }

        function onFullscreenChange() {
            isFullscreen.value = Boolean(document.fullscreenElement);
        }
    /* Fullscreen */

    /* Customer display */
        let displayChannel: BroadcastChannel | null = null;
        const pendingPaymentSnapshot = ref<DisplaySnapshot | null>(null);

        function openCustomerDisplay() {
            window.open('/sell/pos/display', 'posCustomerDisplay', 'width=1100,height=750');
        }

        function buildDisplaySnapshot(): DisplaySnapshot {
            const customer = customersdata.value.find((item) => String(item.id) === String(formData.value.contact_id));

            return {
                businessName: companyLabel.value !== '—' ? companyLabel.value : '',
                customerName: customer?.text || customer?.business_name || 'Walk-In Customer',
                lines: lines.value.map((line) => ({
                    name: line.product_name,
                    quantity: toNumber(line.quantity),
                    unit_price: toNumber(line.unit_price_after_discount),
                    row_subtotal: toNumber(line.row_subtotal),
                })),
                totals: {
                    itemCount: lines.value.length,
                    netTotal: toNumber(formData.value.net_sub_total),
                    discount: toNumber(formData.value.discount_val),
                    shipping: toNumber(formData.value.shipping_charges),
                    finalAmount: toNumber(formData.value.final_amount),
                },
            };
        }

        function broadcastDisplay(
            type: 'update' | 'completed' | 'idle',
            paymentInfo?: { totalPaying: number; changeReturn: number; balance: number },
            snapshot?: DisplaySnapshot,
        ) {
            if (! displayChannel) {
                return;
            }

            displayChannel.postMessage({
                type,
                ...(snapshot ?? buildDisplaySnapshot()),
                payment: paymentInfo,
            });
        }
    /* Customer display */

    /* Calculator */
        const calcDisplay = ref('0');
        const calcStored = ref<number | null>(null);
        const calcOperator = ref<'+' | '−' | '×' | '÷' | null>(null);
        const calcResetNext = ref(false);
        const calcOperatorMap: Record<string, number> = { '+': 0, '−': 0, '×': 0, '÷': 0 };

        function calcDigit(digit: string) {
            if (calcResetNext.value || calcDisplay.value === '0') {
                calcDisplay.value = digit;
                calcResetNext.value = false;
            } else {
                calcDisplay.value += digit;
            }
        }

        function calcDot() {
            if (calcResetNext.value) {
                calcDisplay.value = '0.';
                calcResetNext.value = false;

                return;
            }

            if (! calcDisplay.value.includes('.')) {
                calcDisplay.value += '.';
            }
        }

        function calcEquals() {
            if (calcOperator.value === null || calcStored.value === null) {
                return;
            }

            const current = Number(calcDisplay.value);
            let result = current;

            if (calcOperator.value === '+') {
                result = calcStored.value + current;
            } else if (calcOperator.value === '−') {
                result = calcStored.value - current;
            } else if (calcOperator.value === '×') {
                result = calcStored.value * current;
            } else if (calcOperator.value === '÷') {
                result = current === 0 ? 0 : calcStored.value / current;
            }

            calcDisplay.value = String(Number(result.toFixed(6)));
            calcOperator.value = null;
            calcStored.value = null;
            calcResetNext.value = true;
        }

        function calcChooseOperator(op: '+' | '−' | '×' | '÷') {
            if (! (op in calcOperatorMap)) {
                return;
            }

            if (calcOperator.value && ! calcResetNext.value) {
                calcEquals();
            }

            calcStored.value = Number(calcDisplay.value);
            calcOperator.value = op;
            calcResetNext.value = true;
        }

        function calcClear() {
            calcDisplay.value = '0';
            calcStored.value = null;
            calcOperator.value = null;
            calcResetNext.value = false;
        }

        function calcBackspace() {
            if (calcDisplay.value.length <= 1 || (calcDisplay.value.length === 2 && calcDisplay.value.startsWith('-'))) {
                calcDisplay.value = '0';
            } else {
                calcDisplay.value = calcDisplay.value.slice(0, -1);
            }
        }
    /* Calculator */

    /* Suspended sales */
        function suspendedStorageKey(): string {
            return `pos-suspended-sales:${formData.value.company_id || 'x'}:${formData.value.branch_id || 'x'}`;
        }

        function loadSuspendedSales() {
            try {
                const raw = window.localStorage.getItem(suspendedStorageKey());
                suspendedSales.value = raw ? JSON.parse(raw) : [];
            } catch {
                suspendedSales.value = [];
            }
        }

        function persistSuspendedSales() {
            try {
                window.localStorage.setItem(suspendedStorageKey(), JSON.stringify(suspendedSales.value));
            } catch {
                // Suspending is best-effort when storage is unavailable (e.g. private browsing).
            }
        }

        function suspendSale() {
            if (lines.value.length === 0) {
                Notify('Cart is empty — nothing to suspend', 'alert');

                return;
            }

            const customer = customersdata.value.find((item) => String(item.id) === String(formData.value.contact_id));

            suspendedSales.value = [
                {
                    id: Date.now(),
                    savedAt: new Date().toLocaleString(),
                    label: customer?.text || customer?.business_name || 'Walk-in customer',
                    itemCount: lines.value.length,
                    total: toNumber(formData.value.final_amount),
                    snapshot: JSON.parse(JSON.stringify(formData.value)),
                },
                ...suspendedSales.value,
            ];
            persistSuspendedSales();
            Notify('Sale suspended', 'success');
            resetSale();
        }

        function resumeSuspended(entry: SuspendedSale) {
            formData.value = entry.snapshot as typeof formData.value;
            suspendedSales.value = suspendedSales.value.filter((item) => item.id !== entry.id);
            persistSuspendedSales();
            showSuspendedPanel.value = false;
            recalculateTotals();
        }

        async function discardSuspended(entry: SuspendedSale) {
            const confirmed = await confirmAction('Discard suspended sale?', 'This cannot be undone.');

            if (! confirmed) {
                return;
            }

            suspendedSales.value = suspendedSales.value.filter((item) => item.id !== entry.id);
            persistSuspendedSales();
        }
    /* Suspended sales */

    /* Recent transactions */
        async function openRecentTransactions() {
            showRecentPanel.value = true;

            if (! scopeReady.value) {
                return;
            }

            recentLoading.value = true;

            try {
                await getSells({
                    sort_by: 'created_at',
                    sort_type: 'desc',
                    show_record: 10,
                    page: 1,
                    search: '',
                    company_id: formData.value.company_id,
                    branch_id: formData.value.branch_id,
                } as any);
            } finally {
                recentLoading.value = false;
            }
        }
    /* Recent transactions */

    onMounted(async () => {
        nowLabel.value = new Date().toLocaleString();
        clockTimer = setInterval(() => {
            nowLabel.value = new Date().toLocaleString();
        }, 30000);
        document.addEventListener('fullscreenchange', onFullscreenChange);

        if (typeof BroadcastChannel !== 'undefined') {
            displayChannel = new BroadcastChannel('pos-customer-display');
        }

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

        if (canManageBranch.value && formData.value.company_id) {
            await fetchBranch(formData.value.company_id);

            if (branchesdata.value.length === 1) {
                formData.value.branch_id = branchesdata.value[0].id;
            }
        }

        if (formData.value.company_id) {
            await loadCompanySettings();
        }

        if (scopeReady.value) {
            await loadCustomers();
            await loadCatalogFilters();
            await loadProducts();
            loadSuspendedSales();
        }

        pageReady.value = true;
    });

    onBeforeUnmount(() => {
        if (clockTimer) {
            clearInterval(clockTimer);
        }

        document.removeEventListener('fullscreenchange', onFullscreenChange);
        displayChannel?.close();
    });

    watch([productSearch, brandId, categoryId], () => {
        loadProducts();
    });

    watch(
        () => [formData.value.discount_type, formData.value.discount_amount, formData.value.shipping_charges, formData.value.coupon_discount_amount],
        () => recalculateTotals(),
    );

    watch(
        () => [lines.value, formData.value.contact_id, formData.value.final_amount],
        () => broadcastDisplay('update'),
        { deep: true },
    );
</script>

<template>
    <Head title="POS" />

    <div class="pos-screen">
        <!-- Top bar -->
        <div class="pos-topbar">
            <div class="pos-topbar__location">
                <span class="pos-topbar__label">Location:</span>
                <strong>{{ branchLabel }}</strong>
                <span class="pos-topbar__clock">{{ nowLabel }}</span>
            </div>

            <div class="pos-topbar__actions">
                <button type="button" class="pos-icon-btn" title="Go back" @click="router.visit('/sell')">
                    <ArrowLeft class="h-4 w-4" />
                </button>
                <Link href="/sell/return/add" class="pos-icon-btn" title="Sell return">
                    <Undo2 class="h-4 w-4" />
                </Link>
                <button type="button" class="pos-icon-btn" title="Suspended sales" @click="showSuspendedPanel = true">
                    <PauseCircle class="h-4 w-4" />
                    <span v-if="suspendedSales.length" class="pos-icon-btn__badge">{{ suspendedSales.length }}</span>
                </button>
                <button type="button" class="pos-icon-btn" title="Register details" @click="showRegisterInfo = true">
                    <Briefcase class="h-4 w-4" />
                </button>
                <button type="button" class="pos-icon-btn is-disabled" title="Close register — coming soon" disabled>
                    <XSquare class="h-4 w-4" />
                </button>
                <button type="button" class="pos-icon-btn" title="Calculator" @click="showCalculator = true">
                    <Calculator class="h-4 w-4" />
                </button>
                <button type="button" class="pos-icon-btn" :class="{ 'is-active': isFullscreen }" title="Toggle full screen" @click="toggleFullscreen">
                    <Maximize2 class="h-4 w-4" />
                </button>
                <button type="button" class="pos-icon-btn" title="Open the customer-facing display in a new window" @click="openCustomerDisplay">
                    <Monitor class="h-4 w-4" />
                </button>
                <button type="button" class="pos-pill-btn is-disabled" title="Coming soon" disabled>
                    <PlusCircle class="h-4 w-4" />
                    <span>Add Expense</span>
                </button>
            </div>
        </div>

        <div v-if="!pageReady" class="pos-loading">Preparing POS…</div>

        <template v-else>
            <div class="pos-body">
                <!-- Left: cart -->
                <div class="pos-cart-panel">
                    <div v-if="showCompanyField || showBranchField" class="pos-cart-panel__scope">
                        <select v-if="showCompanyField" class="pos-select" title="Select the company for this sale" v-model="formData.company_id" @change="handleCompanyChange">
                            <option value="">Select company</option>
                            <option v-for="company in companiesdata" :key="company.id" :value="company.id">{{ company.text ?? company.name }}</option>
                        </select>
                        <select v-if="showBranchField" class="pos-select" title="Select the branch for this sale" v-model="formData.branch_id" :disabled="branchDisabled" @change="handleBranchChange">
                            <option value="">Select branch</option>
                            <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">{{ branch.text ?? branch.name }}</option>
                        </select>
                    </div>

                    <div class="pos-cart-panel__customer">
                        <select class="pos-select" title="Select the customer for this sale" v-model="formData.contact_id" :disabled="!scopeReady">
                            <option value="">Walk-In Customer</option>
                            <option v-for="customer in customersdata" :key="customer.id" :value="customer.id">
                                {{ customer.text ?? customer.business_name }}
                            </option>
                        </select>
                        <button type="button" class="pos-icon-btn-sm" title="Refresh customers" :disabled="!scopeReady" @click="loadCustomers">
                            <RefreshCw class="h-4 w-4" />
                        </button>
                        <a href="/contact/customer/add" target="_blank" rel="noopener" class="pos-icon-btn-sm" title="Add customer">
                            <UserPlus class="h-4 w-4" />
                        </a>
                    </div>

                    <div class="pos-cart-panel__search">
                        <Search class="h-4 w-4 pos-cart-panel__search-icon" />
                        <input
                            type="search"
                            class="pos-cart-panel__search-input"
                            placeholder="Enter Product name / SKU / Scan bar code"
                            title="Search products, or scan a barcode and press Enter to add it instantly"
                            v-model="productSearch"
                            :disabled="!scopeReady"
                            @keyup.enter="onSearchEnter"
                        >
                    </div>

                    <div class="pos-cart-panel__list">
                        <div v-if="!lines.length" class="pos-empty-cart">
                            <span class="pos-empty-cart__icon"><ShoppingCart class="h-7 w-7" /></span>
                            <strong>Your cart is empty</strong>
                            <span>Scan a barcode, tap a product tile, or type to search.</span>
                        </div>

                        <div v-else class="pos-cart-lines">
                            <div v-for="(line, index) in lines" :key="`${line.product_id}-${line.variation_id}-${index}`" class="pos-cart-line">
                                <span class="pos-cart-line__icon"><Package class="h-4 w-4" /></span>

                                <div class="pos-cart-line__info">
                                    <strong :title="line.product_name">{{ line.product_name }}</strong>
                                    <div class="pos-cart-line__meta">
                                        <span v-if="line.sku" class="pos-cart-line__sku">{{ line.sku }}</span>
                                        <select
                                            v-if="(line.units?.length ?? 0) > 1"
                                            class="pos-cart-line__unit-select"
                                            title="Change unit for this line"
                                            :value="line.unit_id"
                                            @change="updateLine(index, { unit_id: ($event.target as HTMLSelectElement).value })"
                                        >
                                            <option v-for="unit in line.units" :key="unit.id" :value="unit.id">{{ unit.short_name ?? unit.text }}</option>
                                        </select>
                                        <span v-else class="pos-cart-line__unit">{{ unitLabel(line) }}</span>
                                    </div>
                                </div>

                                <div class="pos-qty-stepper">
                                    <button type="button" title="Decrease quantity" @click="stepQuantity(index, -1)"><Minus class="h-3.5 w-3.5" /></button>
                                    <input
                                        type="number"
                                        min="1"
                                        title="Quantity"
                                        :value="line.quantity"
                                        @input="updateLine(index, { quantity: ($event.target as HTMLInputElement).value })"
                                    >
                                    <button type="button" title="Increase quantity" @click="stepQuantity(index, 1)"><Plus class="h-3.5 w-3.5" /></button>
                                </div>

                                <div class="pos-cart-line__price">
                                    <label>Price</label>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        title="Unit price"
                                        :value="line.unit_price"
                                        @input="updateLine(index, { unit_price: ($event.target as HTMLInputElement).value })"
                                    >
                                </div>

                                <div class="pos-cart-line__price">
                                    <label>Disc.</label>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        title="Discount for this line"
                                        :value="line.discount_percent"
                                        @input="updateLine(index, { discount_percent: ($event.target as HTMLInputElement).value })"
                                    >
                                </div>

                                <div class="pos-cart-line__subtotal">{{ money(line.row_subtotal) }}</div>

                                <button type="button" class="pos-cart-line__remove" title="Remove" @click="removeLine(index)">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="pos-summary">
                        <div class="pos-summary__row">
                            <span>Items</span>
                            <strong>{{ itemCountLabel }}</strong>
                        </div>
                        <div class="pos-summary__row">
                            <span>Net total</span>
                            <strong>{{ money(formData.net_sub_total) }}</strong>
                        </div>
                        <div class="pos-summary__row pos-summary__discount">
                            <span>Discount</span>
                            <div class="pos-summary__discount-controls">
                                <select class="pos-select pos-select--sm" title="Discount type" v-model="formData.discount_type">
                                    <option value="none">None</option>
                                    <option value="fixed">Fixed</option>
                                    <option value="percentage">Percent</option>
                                </select>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="pos-select pos-select--sm"
                                    title="Discount amount"
                                    v-model="formData.discount_amount"
                                    :disabled="discountAmountDisabled"
                                >
                            </div>
                            <strong>− {{ money(formData.discount_val) }}</strong>
                        </div>
                        <div class="pos-summary__row">
                            <span>Shipping</span>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                class="pos-select pos-select--sm pos-summary__shipping"
                                title="Shipping charges"
                                v-model="formData.shipping_charges"
                            >
                            <strong>{{ money(formData.shipping_charges) }}</strong>
                        </div>
                        <SaleIncentivesPanel
                            variant="pos"
                            :form-data="formData"
                            :net-sub-total="Number(formData.net_sub_total ?? 0)"
                            :persist="persistPatch"
                        />
                        <div class="pos-summary__total">
                            <span>Total due</span>
                            <strong>{{ money(formData.final_amount) }}</strong>
                        </div>
                    </div>
                </div>

                <!-- Right: catalog -->
                <div class="pos-catalog-panel">
                    <div class="pos-catalog-tabs">
                        <button type="button" class="pos-catalog-tab" title="Browse products by category" :class="{ 'is-active': showCategoryPanel || activeTab === 'category' }" @click="toggleCategoryPanel">
                            <LayoutGrid class="h-4 w-4" />
                            <span>Category</span>
                            <span class="pos-catalog-tab__badge">{{ categoriesdata.length }}</span>
                        </button>
                        <button type="button" class="pos-catalog-tab" title="Browse products by brand" :class="{ 'is-active': showBrandPanel || activeTab === 'brand' }" @click="toggleBrandPanel">
                            <Tag class="h-4 w-4" />
                            <span>Brands</span>
                            <span class="pos-catalog-tab__badge">{{ brandsdata.length }}</span>
                        </button>
                        <button type="button" class="pos-catalog-tab pos-catalog-tab--featured" title="Show products marked as featured for this branch" :class="{ 'is-active': activeTab === 'featured' }" @click="showFeaturedProducts">
                            <Star class="h-4 w-4" />
                            <span>Featured Products</span>
                        </button>
                    </div>

                    <div v-if="showCategoryPanel" class="pos-flyout">
                        <button type="button" class="pos-flyout__chip" title="Clear the category filter" @click="showAllProducts">All categories</button>
                        <button v-for="category in categoriesdata" :key="category.id" type="button" class="pos-flyout__chip" :title="`Filter by ${category.text ?? category.name}`" :class="{ 'is-active': String(categoryId) === String(category.id) }" @click="selectCategory(category.id)">
                            {{ category.text ?? category.name }}
                        </button>
                    </div>

                    <div v-if="showBrandPanel" class="pos-flyout">
                        <button type="button" class="pos-flyout__chip" title="Clear the brand filter" @click="showAllProducts">All brands</button>
                        <button v-for="brand in brandsdata" :key="brand.id" type="button" class="pos-flyout__chip" :title="`Filter by ${brand.text ?? brand.name}`" :class="{ 'is-active': String(brandId) === String(brand.id) }" @click="selectBrand(brand.id)">
                            {{ brand.text ?? brand.name }}
                        </button>
                    </div>

                    <div v-if="activeTab === 'featured'" class="pos-featured-banner">
                        <Star class="h-4 w-4" />
                        <span>No featured products are configured yet for this branch.</span>
                    </div>

                    <div class="pos-grid">
                        <p v-if="!scopeReady" class="pos-grid__hint">Select a company and branch to browse products.</p>
                        <p v-else-if="searchingGrid" class="pos-grid__hint">Loading products…</p>
                        <p v-else-if="!products.length" class="pos-grid__hint">No products match this filter.</p>
                        <button
                            v-for="product in products"
                            :key="`${product.product_id}-${product.id}`"
                            type="button"
                            class="pos-card"
                            :disabled="!canAdd(product)"
                            :title="canAdd(product) ? `Add ${product.name || product.product_name} to the cart` : `${product.name || product.product_name} is out of stock`"
                            @click="addProduct(product)"
                        >
                            <img :src="productImage(product)" :alt="product.name" loading="lazy">
                            <strong>{{ product.name || product.product_name }}</strong>
                            <small v-if="product.sku">({{ product.sku }})</small>
                            <div class="pos-card__foot">
                                <span class="pos-card__stock">{{ productStock(product) }} Pc(s) in stock</span>
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Bottom action bar -->
            <div class="pos-bottombar">
                <button type="button" class="pos-btn pos-btn--outline-danger" title="Clear the current cart and start over" @click="cancelSale">
                    <X class="h-4 w-4" />
                    <span>Cancel</span>
                </button>

                <div class="pos-bottombar__group">
                    <button type="button" class="pos-btn pos-btn--outline" title="Save this sale as a draft to finish later" :disabled="saving" @click="checkoutDraft">
                        <FileText class="h-4 w-4" />
                        <span>Draft</span>
                    </button>
                    <button type="button" class="pos-btn pos-btn--outline" title="Save this sale as a quotation" :disabled="saving" @click="checkoutQuotation">
                        <ClipboardList class="h-4 w-4" />
                        <span>Quotation</span>
                    </button>
                    <button type="button" class="pos-btn pos-btn--outline" title="Park this cart so you can resume it later" :disabled="saving" @click="suspendSale">
                        <PauseCircle class="h-4 w-4" />
                        <span>Suspend</span>
                    </button>
                    <button type="button" class="pos-btn pos-btn--outline-warning" title="Complete the sale now and bill the customer later (due)" :disabled="saving" @click="checkoutCreditSale">
                        <History class="h-4 w-4" />
                        <span>Credit Sale</span>
                    </button>
                    <button type="button" class="pos-btn pos-btn--outline-info" title="Complete the sale and take a card payment" :disabled="saving" @click="openPaymentModal('card')">
                        <CreditCard class="h-4 w-4" />
                        <span>Card</span>
                    </button>
                </div>

                <button type="button" class="pos-btn pos-btn--primary" title="Complete the sale and record a payment (partial or split)" :disabled="saving" @click="openPaymentModal('split')">
                    <Split class="h-4 w-4" />
                    <span>Multiple Pay</span>
                </button>
                <button type="button" class="pos-btn pos-btn--success" title="Complete the sale as fully paid in cash" :disabled="saving" @click="checkoutCash">
                    <Banknote class="h-4 w-4" />
                    <span>{{ saving ? 'Saving…' : 'Cash' }}</span>
                </button>
                <button type="button" class="pos-btn pos-btn--teal" title="View the last 10 sales for this branch" @click="openRecentTransactions">
                    <History class="h-4 w-4" />
                    <span>Recent Transactions</span>
                </button>
            </div>
        </template>

        <!-- Unit picker -->
        <div v-if="unitPrompt.open" class="pos-overlay" @click.self="closeUnitPrompt">
            <div class="pos-overlay__panel pos-overlay__panel--sm">
                <h3>Select unit</h3>
                <p>{{ unitPrompt.product?.name || unitPrompt.product?.product_name }}</p>
                <div class="pos-unit-options">
                    <button v-for="unit in unitPrompt.product?.units ?? []" :key="unit.id" type="button" class="pos-unit-option" :title="`Add to cart in ${unit.short_name ?? unit.text}`" @click="chooseUnit(unit)">
                        <span>{{ unit.short_name ?? unit.text }}</span>
                        <small>{{ unit.unit_qty ?? 0 }} available</small>
                    </button>
                </div>
                <button type="button" class="pos-overlay__cancel" title="Close without adding this product" @click="closeUnitPrompt">Cancel</button>
            </div>
        </div>

        <!-- Calculator -->
        <div v-if="showCalculator" class="pos-overlay" @click.self="showCalculator = false">
            <div class="pos-overlay__panel pos-overlay__panel--sm">
                <div class="pos-overlay__head">
                    <h3>Calculator</h3>
                    <button type="button" class="pos-overlay__close" title="Close calculator" @click="showCalculator = false"><X class="h-4 w-4" /></button>
                </div>
                <div class="pos-calc-display">{{ calcDisplay }}</div>
                <div class="pos-calc-grid">
                    <button type="button" class="pos-calc-btn pos-calc-btn--fn" title="Clear" @click="calcClear">C</button>
                    <button type="button" class="pos-calc-btn pos-calc-btn--fn" title="Backspace" @click="calcBackspace">⌫</button>
                    <button type="button" class="pos-calc-btn pos-calc-btn--op" title="Divide" @click="calcChooseOperator('÷')">÷</button>
                    <button type="button" class="pos-calc-btn pos-calc-btn--op" title="Multiply" @click="calcChooseOperator('×')">×</button>

                    <button type="button" class="pos-calc-btn" title="Digit 7" @click="calcDigit('7')">7</button>
                    <button type="button" class="pos-calc-btn" title="Digit 8" @click="calcDigit('8')">8</button>
                    <button type="button" class="pos-calc-btn" title="Digit 9" @click="calcDigit('9')">9</button>
                    <button type="button" class="pos-calc-btn pos-calc-btn--op" title="Subtract" @click="calcChooseOperator('−')">−</button>

                    <button type="button" class="pos-calc-btn" title="Digit 4" @click="calcDigit('4')">4</button>
                    <button type="button" class="pos-calc-btn" title="Digit 5" @click="calcDigit('5')">5</button>
                    <button type="button" class="pos-calc-btn" title="Digit 6" @click="calcDigit('6')">6</button>
                    <button type="button" class="pos-calc-btn pos-calc-btn--op" title="Add" @click="calcChooseOperator('+')">+</button>

                    <button type="button" class="pos-calc-btn" title="Digit 1" @click="calcDigit('1')">1</button>
                    <button type="button" class="pos-calc-btn" title="Digit 2" @click="calcDigit('2')">2</button>
                    <button type="button" class="pos-calc-btn" title="Digit 3" @click="calcDigit('3')">3</button>
                    <button type="button" class="pos-calc-btn pos-calc-btn--eq" style="grid-row: span 2;" title="Calculate result" @click="calcEquals">=</button>

                    <button type="button" class="pos-calc-btn" style="grid-column: span 2;" title="Digit 0" @click="calcDigit('0')">0</button>
                    <button type="button" class="pos-calc-btn" title="Decimal point" @click="calcDot">.</button>
                </div>
            </div>
        </div>

        <!-- Register info -->
        <div v-if="showRegisterInfo" class="pos-overlay" @click.self="showRegisterInfo = false">
            <div class="pos-overlay__panel pos-overlay__panel--sm">
                <div class="pos-overlay__head">
                    <h3>Register details</h3>
                    <button type="button" class="pos-overlay__close" title="Close" @click="showRegisterInfo = false"><X class="h-4 w-4" /></button>
                </div>
                <div class="pos-info-list">
                    <div class="pos-info-list__row"><span>Company</span><strong>{{ companyLabel }}</strong></div>
                    <div class="pos-info-list__row"><span>Branch</span><strong>{{ branchLabel }}</strong></div>
                    <div class="pos-info-list__row"><span>Cashier</span><strong>{{ authUser?.fullname || '—' }}</strong></div>
                    <div class="pos-info-list__row"><span>Session started</span><strong>{{ nowLabel }}</strong></div>
                </div>
            </div>
        </div>

        <!-- Suspended sales -->
        <div v-if="showSuspendedPanel" class="pos-overlay" @click.self="showSuspendedPanel = false">
            <div class="pos-overlay__panel">
                <div class="pos-overlay__head">
                    <h3>Suspended sales</h3>
                    <button type="button" class="pos-overlay__close" title="Close" @click="showSuspendedPanel = false"><X class="h-4 w-4" /></button>
                </div>
                <p v-if="!suspendedSales.length" class="pos-overlay__empty">No suspended sales for this branch.</p>
                <div v-else class="pos-list">
                    <div v-for="entry in suspendedSales" :key="entry.id" class="pos-list__row">
                        <div class="pos-list__info">
                            <strong>{{ entry.label }}</strong>
                            <span>{{ entry.itemCount }} item(s) · {{ money(entry.total) }} · {{ entry.savedAt }}</span>
                        </div>
                        <div class="pos-list__actions">
                            <button type="button" class="pos-btn pos-btn--outline pos-btn--sm" title="Load this sale back into the cart" @click="resumeSuspended(entry)">Resume</button>
                            <button type="button" class="pos-btn pos-btn--outline-danger pos-btn--sm" title="Permanently delete this suspended sale" @click="discardSuspended(entry)">Discard</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent transactions -->
        <div v-if="showRecentPanel" class="pos-overlay" @click.self="showRecentPanel = false">
            <div class="pos-overlay__panel">
                <div class="pos-overlay__head">
                    <h3>Recent transactions</h3>
                    <button type="button" class="pos-overlay__close" title="Close" @click="showRecentPanel = false"><X class="h-4 w-4" /></button>
                </div>
                <p v-if="recentLoading" class="pos-overlay__empty">Loading…</p>
                <p v-else-if="!recentState.records.data.length" class="pos-overlay__empty">No recent transactions.</p>
                <div v-else class="pos-list">
                    <div v-for="row in recentState.records.data" :key="(row as any).id" class="pos-list__row">
                        <div class="pos-list__info">
                            <strong>{{ (row as any).invoice_no }}</strong>
                            <span>{{ (row as any).customer_name || 'Walk-in customer' }} · {{ (row as any).status_label }} · {{ (row as any).payment_status_label }}</span>
                        </div>
                        <div class="pos-list__amount">{{ (row as any).formatted_amount }}</div>
                    </div>
                </div>
            </div>
        </div>

        <PaymentModal
            :showLoader="paymentModalLoading"
            :formData="paymentFormData"
            :endpoint="API_ENDPOINTS.sellPayments"
            :title="paymentModalMode === 'card' ? 'Card Payment' : 'Record Payment'"
            modalId="PosPaymentModal"
            :onClose="handlePaymentModalClose"
            :success="handlePaymentSuccess"
            :error="handlePaymentError"
        />
    </div>
</template>

<style scoped>
.pos-screen {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    box-sizing: border-box;
    min-height: 100vh;
    padding: 0.6rem;
    background: var(--app-background, #f8fafc);
    font-family: var(--app-font, Inter, ui-sans-serif, system-ui, sans-serif);
}

.pos-screen *,
.pos-screen *::before,
.pos-screen *::after {
    box-sizing: border-box;
}

.pos-loading {
    padding: 3rem 0;
    text-align: center;
    color: #64748b;
    font-size: 0.875rem;
}

/* Top bar */
.pos-topbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.65rem 1rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-lg, 14px);
    background: #fff;
    box-shadow: var(--app-shadow-sm, 0 1px 2px rgba(15, 23, 42, 0.05));
}

.pos-topbar__location {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.8125rem;
    color: #334155;
}

.pos-topbar__label {
    color: #64748b;
    font-weight: 600;
}

.pos-topbar__clock {
    display: inline-flex;
    align-items: center;
    border-radius: var(--app-radius-sm, 7px);
    background: var(--app-primary, #0d9488);
    color: #fff;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 0.3rem 0.65rem;
    font-variant-numeric: tabular-nums;
}

.pos-topbar__actions {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.pos-icon-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.15rem;
    height: 2.15rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-sm, 7px);
    background: #fff;
    color: #334155;
    transition: background-color 150ms ease, border-color 150ms ease, color 150ms ease;
}

.pos-icon-btn:hover:not(:disabled) {
    border-color: var(--app-primary, #0d9488);
    color: var(--app-primary, #0d9488);
    background: #f0fdfa;
}

.pos-icon-btn.is-active {
    border-color: var(--app-primary, #0d9488);
    color: var(--app-primary, #0d9488);
    background: #f0fdfa;
}

.pos-icon-btn.is-disabled,
.pos-icon-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.pos-icon-btn__badge {
    position: absolute;
    top: -0.3rem;
    right: -0.3rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.1rem;
    height: 1.1rem;
    padding: 0 0.25rem;
    border-radius: 999px;
    background: var(--app-danger, #e11d48);
    color: #fff;
    font-size: 0.625rem;
    font-weight: 700;
}

.pos-pill-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    height: 2.15rem;
    padding: 0 0.85rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font-size: 0.75rem;
    font-weight: 700;
    white-space: nowrap;
}

.pos-pill-btn.is-disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Layout */
.pos-body {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
    gap: 0.75rem;
    flex: 1;
    min-height: 0;
}

@media (max-width: 1100px) {
    .pos-body {
        grid-template-columns: 1fr;
    }
}

.pos-cart-panel,
.pos-catalog-panel {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    padding: 0.85rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-lg, 14px);
    background: #fff;
    box-shadow: var(--app-shadow-sm, 0 1px 2px rgba(15, 23, 42, 0.05));
    min-height: 0;
}

.pos-cart-panel__scope {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem;
}

.pos-cart-panel__customer {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.pos-cart-panel__customer .pos-select {
    flex: 1;
}

.pos-select {
    width: 100%;
    min-height: var(--form-control-height, 2.375rem);
    padding: 0.35rem 0.65rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-sm, 7px);
    background: #fff;
    color: #111827;
    font-size: 0.8125rem;
}

.pos-select:focus {
    outline: none;
    border-color: var(--app-primary, #0d9488);
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
}

.pos-select:disabled {
    background: #f8fafc;
    color: #94a3b8;
}

.pos-select--sm {
    min-height: 2rem;
    padding: 0.2rem 0.5rem;
    font-size: 0.75rem;
}

.pos-icon-btn-sm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.15rem;
    height: 2.15rem;
    flex-shrink: 0;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-sm, 7px);
    background: #fff;
    color: var(--app-primary, #0d9488);
}

.pos-icon-btn-sm:hover:not(:disabled) {
    background: #f0fdfa;
}

.pos-icon-btn-sm:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pos-cart-panel__search {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    min-height: var(--form-control-height, 2.375rem);
    padding: 0 0.85rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-md, 9px);
    background: #fff;
}

.pos-cart-panel__search:focus-within {
    border-color: var(--app-primary, #0d9488);
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
}

.pos-cart-panel__search-icon {
    color: #94a3b8;
    flex-shrink: 0;
}

.pos-cart-panel__search-input {
    flex: 1;
    min-width: 0;
    border: 0;
    background: transparent;
    font-size: 0.875rem;
}

.pos-cart-panel__search-input:focus {
    outline: none;
}

.pos-cart-panel__list {
    flex: 1;
    min-height: 14rem;
    max-height: 42vh;
    overflow-y: auto;
    border: 1px dashed var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-md, 9px);
}

.pos-empty-cart {
    display: flex;
    height: 100%;
    min-height: 14rem;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    color: #94a3b8;
    text-align: center;
    padding: 1rem;
}

.pos-empty-cart__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 3rem;
    border-radius: 999px;
    background: #f1f5f9;
    color: #94a3b8;
    margin-bottom: 0.3rem;
}

.pos-empty-cart strong {
    color: #334155;
    font-size: 0.9375rem;
}

.pos-empty-cart span {
    font-size: 0.8125rem;
}

.pos-cart-lines {
    display: flex;
    flex-direction: column;
}

.pos-cart-line {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.55rem 0.75rem;
    border-bottom: 1px solid var(--app-border-subtle, #f1f5f9);
}

.pos-cart-line:last-child {
    border-bottom: 0;
}

.pos-cart-line__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    flex-shrink: 0;
    border-radius: var(--app-radius-sm, 7px);
    background: #f1f5f9;
    color: #64748b;
}

.pos-cart-line__info {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
    gap: 0.15rem;
}

.pos-cart-line__info strong {
    overflow: hidden;
    font-size: 0.8125rem;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #111827;
}

.pos-cart-line__meta {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.pos-cart-line__sku {
    font-size: 0.6875rem;
    color: #64748b;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.pos-cart-line__unit {
    font-size: 0.6875rem;
    color: #94a3b8;
}

.pos-cart-line__unit-select {
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-sm, 7px);
    font-size: 0.6875rem;
    padding: 0.05rem 0.3rem;
    background: #f8fafc;
    color: #475569;
}

.pos-qty-stepper {
    display: flex;
    align-items: stretch;
    overflow: hidden;
    flex-shrink: 0;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-sm, 7px);
}

.pos-qty-stepper button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    background: #f8fafc;
    color: #475569;
}

.pos-qty-stepper button:hover {
    background: #f0fdfa;
    color: var(--app-primary, #0d9488);
}

.pos-qty-stepper input {
    width: 2.25rem;
    border: 0;
    border-left: 1px solid var(--app-border, #e5e7eb);
    border-right: 1px solid var(--app-border, #e5e7eb);
    text-align: center;
    font-size: 0.8125rem;
}

.pos-qty-stepper input:focus {
    outline: none;
}

.pos-cart-line__price {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    flex-shrink: 0;
    gap: 0.1rem;
}

.pos-cart-line__price label {
    font-size: 0.6875rem;
    color: #94a3b8;
}

.pos-cart-line__price input {
    width: 4rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-sm, 7px);
    padding: 0.2rem 0.35rem;
    font-size: 0.75rem;
    text-align: right;
}

.pos-cart-line__price input:focus {
    outline: none;
    border-color: var(--app-primary, #0d9488);
}

.pos-cart-line__subtotal {
    flex-shrink: 0;
    width: 4.5rem;
    text-align: right;
    font-size: 0.8125rem;
    font-weight: 700;
    color: #0f172a;
}

.pos-cart-line__remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.85rem;
    height: 1.85rem;
    flex-shrink: 0;
    border-radius: var(--app-radius-sm, 7px);
    color: #94a3b8;
}

.pos-cart-line__remove:hover {
    background: var(--app-danger-soft, #fff1f2);
    color: var(--app-danger, #e11d48);
}

/* Summary */
.pos-summary {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    border-top: 1px solid var(--app-border-subtle, #f1f5f9);
    padding-top: 0.7rem;
    font-size: 0.8125rem;
    color: #334155;
}

.pos-summary__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
}

.pos-summary__discount-controls {
    display: flex;
    flex: 1;
    justify-content: flex-end;
    gap: 0.4rem;
    margin: 0 0.5rem;
}

.pos-summary__discount-controls .pos-select--sm:first-child {
    max-width: 6.5rem;
}

.pos-summary__discount-controls .pos-select--sm:last-child,
.pos-summary__shipping {
    max-width: 6.5rem;
    text-align: right;
}

.pos-summary__total {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0.3rem;
    border-radius: var(--app-radius-md, 9px);
    border: 1px solid rgb(153 246 228 / 0.7);
    background: #f0fdfa;
    padding: 0.6rem 0.9rem;
    font-size: 0.9rem;
    font-weight: 800;
    color: #0f766e;
}

/* Catalog */
.pos-catalog-tabs {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.pos-catalog-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.9rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font-size: 0.8125rem;
    font-weight: 700;
}

.pos-catalog-tab.is-active {
    border-color: var(--app-primary, #0d9488);
    background: #f0fdfa;
    color: var(--app-primary, #0d9488);
}

.pos-catalog-tab__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.35rem;
    height: 1.35rem;
    padding: 0 0.3rem;
    border-radius: 999px;
    background: var(--app-primary-soft, #f0fdfa);
    color: var(--app-primary-hover, #0f766e);
    font-size: 0.6875rem;
}

.pos-flyout {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    padding: 0.65rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-md, 9px);
    background: #f8fafc;
    max-height: 8rem;
    overflow-y: auto;
}

.pos-flyout__chip {
    padding: 0.3rem 0.7rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 999px;
    background: #fff;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 600;
}

.pos-flyout__chip.is-active {
    border-color: var(--app-primary, #0d9488);
    background: var(--app-primary, #0d9488);
    color: #fff;
}

.pos-catalog-tab--featured.is-active {
    border-color: var(--app-warning-border, #fde68a);
    background: var(--app-warning-soft, #fffbeb);
    color: var(--app-warning, #d97706);
}

.pos-featured-banner {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 0.85rem;
    border: 1px solid var(--app-warning-border, #fde68a);
    border-radius: var(--app-radius-md, 9px);
    background: var(--app-warning-soft, #fffbeb);
    color: var(--app-warning, #d97706);
    font-size: 0.8125rem;
    font-weight: 600;
}

.pos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 0.65rem;
    flex: 1;
    max-height: 62vh;
    overflow-y: auto;
    padding-top: 0.15rem;
}

.pos-grid__hint {
    grid-column: 1 / -1;
    padding: 1.5rem 0;
    text-align: center;
    font-size: 0.8125rem;
    color: #64748b;
}

.pos-card {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-md, 9px);
    background: #fff;
    padding: 0.5rem;
    text-align: left;
    transition: border-color 150ms ease, box-shadow 150ms ease;
}

.pos-card:hover:not(:disabled) {
    border-color: var(--app-primary, #0d9488);
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
}

.pos-card img {
    width: 100%;
    height: 72px;
    object-fit: cover;
    border-radius: var(--app-radius-sm, 7px);
    background: #f8fafc;
}

.pos-card strong {
    overflow: hidden;
    font-size: 0.75rem;
    font-weight: 600;
    color: #111827;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-card small {
    font-size: 0.6875rem;
    color: #64748b;
}

.pos-card__foot {
    margin-top: 0.15rem;
}

.pos-card__stock {
    font-size: 0.625rem;
    color: #0f766e;
    font-weight: 600;
}

.pos-card:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Bottom bar */
.pos-bottombar {
    position: sticky;
    bottom: 0;
    z-index: 5;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 1rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-lg, 14px);
    background: #fff;
    box-shadow: 0 -4px 16px rgba(15, 23, 42, 0.08);
}

.pos-bottombar__group {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    flex: 1;
}

.pos-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.55rem 0.95rem;
    border: 1px solid transparent;
    border-radius: var(--app-radius-md, 9px);
    font-size: 0.8125rem;
    font-weight: 700;
    white-space: nowrap;
}

.pos-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.pos-btn--sm {
    padding: 0.35rem 0.65rem;
    font-size: 0.75rem;
}

.pos-btn--outline {
    border-color: var(--app-border, #e5e7eb);
    background: #fff;
    color: #334155;
}

.pos-btn--outline:hover:not(:disabled) {
    border-color: var(--app-primary, #0d9488);
    color: var(--app-primary, #0d9488);
    background: #f0fdfa;
}

.pos-btn--outline-danger {
    border-color: var(--app-danger-border, #fecdd3);
    background: #fff;
    color: var(--app-danger, #e11d48);
}

.pos-btn--outline-danger:hover:not(:disabled) {
    background: var(--app-danger-soft, #fff1f2);
}

.pos-btn--outline-warning {
    border-color: var(--app-warning-border, #fde68a);
    background: #fff;
    color: var(--app-warning, #d97706);
}

.pos-btn--outline-warning:hover:not(:disabled) {
    background: var(--app-warning-soft, #fffbeb);
}

.pos-btn--outline-info {
    border-color: var(--app-info-border, #bfdbfe);
    background: #fff;
    color: var(--app-info, #0369a1);
}

.pos-btn--outline-info:hover:not(:disabled) {
    background: var(--app-info-soft, #eff6ff);
}

.pos-btn--primary {
    background: var(--app-primary, #0d9488);
    color: #fff;
}

.pos-btn--primary:hover:not(:disabled) {
    background: var(--app-primary-hover, #0f766e);
}

.pos-btn--success {
    background: var(--app-success, #16a34a);
    color: #fff;
}

.pos-btn--success:hover:not(:disabled) {
    background: #15803d;
}

.pos-btn--teal {
    background: var(--app-primary, #0d9488);
    color: #fff;
    margin-left: auto;
}

.pos-btn--teal:hover:not(:disabled) {
    background: var(--app-primary-hover, #0f766e);
}

/* Overlays */
.pos-overlay {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.45);
    padding: 1rem;
}

.pos-overlay__panel {
    width: 100%;
    max-width: 26rem;
    max-height: 85vh;
    overflow-y: auto;
    border-radius: var(--app-radius-lg, 14px);
    background: #fff;
    padding: 1.25rem;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
}

.pos-overlay__panel--sm {
    max-width: 20rem;
}

.pos-overlay__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
}

.pos-overlay__head h3,
.pos-overlay__panel h3 {
    margin: 0 0 0.15rem;
    font-size: 0.95rem;
    font-weight: 700;
    color: #111827;
}

.pos-overlay__panel p {
    margin: 0 0 0.85rem;
    font-size: 0.8125rem;
    color: #64748b;
}

.pos-overlay__close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: var(--app-radius-sm, 7px);
    color: #94a3b8;
}

.pos-overlay__close:hover {
    background: #f1f5f9;
    color: #475569;
}

.pos-overlay__cancel {
    width: 100%;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-sm, 7px);
    padding: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #475569;
    background: #f8fafc;
}

.pos-overlay__empty {
    padding: 1rem 0;
    text-align: center;
    color: #94a3b8;
}

.pos-unit-options {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    margin-bottom: 0.85rem;
}

.pos-unit-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-sm, 7px);
    padding: 0.55rem 0.75rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #111827;
    background: #fff;
}

.pos-unit-option:hover {
    border-color: var(--app-primary, #0d9488);
    background: #f0fdfa;
}

.pos-unit-option small {
    font-weight: 500;
    color: #64748b;
}

/* Calculator */
.pos-calc-display {
    margin-bottom: 0.75rem;
    padding: 0.75rem 0.85rem;
    border-radius: var(--app-radius-md, 9px);
    background: #0f172a;
    color: #fff;
    text-align: right;
    font-size: 1.5rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    overflow-x: auto;
    white-space: nowrap;
}

.pos-calc-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.4rem;
}

.pos-calc-btn {
    padding: 0.65rem 0;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-sm, 7px);
    background: #f8fafc;
    color: #111827;
    font-size: 0.9375rem;
    font-weight: 600;
}

.pos-calc-btn:hover {
    background: #f1f5f9;
}

.pos-calc-btn--op {
    background: var(--app-primary-soft, #f0fdfa);
    color: var(--app-primary-hover, #0f766e);
}

.pos-calc-btn--fn {
    background: var(--app-danger-soft, #fff1f2);
    color: var(--app-danger, #e11d48);
}

.pos-calc-btn--eq {
    background: var(--app-primary, #0d9488);
    color: #fff;
}

.pos-calc-btn--eq:hover {
    background: var(--app-primary-hover, #0f766e);
}

/* Info / list panels */
.pos-info-list {
    display: flex;
    flex-direction: column;
    gap: 0.55rem;
}

.pos-info-list__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.8125rem;
    color: #475569;
}

.pos-info-list__row strong {
    color: #111827;
}

.pos-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.pos-list__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: var(--app-radius-md, 9px);
}

.pos-list__info {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 0.15rem;
}

.pos-list__info strong {
    font-size: 0.8125rem;
    color: #111827;
}

.pos-list__info span {
    font-size: 0.75rem;
    color: #64748b;
}

.pos-list__actions {
    display: flex;
    gap: 0.4rem;
    flex-shrink: 0;
}

.pos-list__amount {
    flex-shrink: 0;
    font-size: 0.8125rem;
    font-weight: 700;
    color: #0f172a;
}
</style>
