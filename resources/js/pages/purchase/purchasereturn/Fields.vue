<script setup lang="ts">
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import type { PurchaseReturnLine } from '@/composables/purchaseReturn';
    import { openLfmImagePicker } from '@/utils/openLfmImagePicker';
    import { usePage } from '@inertiajs/vue3';
    import { Box, ImagePlus, Minus, Package, Plus, RefreshCw } from '@boxicons/vue';
    import { computed, onMounted, ref, watch } from 'vue';

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
    const colFull = { container: 12, label: 12, wrapper: 12 };

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
    } | null);

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
        companiesdata,
        branchesdata,
        appUrl,
    } = useCommons();

    const suppliersdata = ref<Array<{ id: number | string; text?: string; business_name?: string }>>([]);
    const purchasesdata = ref<Array<{ id: number | string; text?: string; invoice_no?: string; transaction_date?: string; final_amount?: number | string }>>([]);
    const loadingLines = ref(false);
    const lastFetchedCompanyId = ref('');
    const lastFetchedSupplierKey = ref('');
    const lastFetchedPurchaseKey = ref('');
    const lastLoadedTransactionId = ref('');

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedBranchId = computed(() => params.formData?.branch_id ?? '');
    const selectedContactId = computed(() => params.formData?.contact_id ?? '');
    const selectedTransactionId = computed(() => params.formData?.transaction_id ?? '');
    const scopeReady = computed(() => Boolean(normalizeId(selectedCompanyId.value) && normalizeId(selectedBranchId.value)));
    const supplierDisabled = computed(() => isEdit.value || ! scopeReady.value);
    const purchaseDisabled = computed(() => isEdit.value || ! normalizeId(selectedContactId.value));
    const branchDisabled = computed(() => isEdit.value || (isSuperadmin.value && ! selectedCompanyId.value));
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const imageInputId = computed(() => (isEdit.value ? 'EditPurchaseReturnAttachment' : 'PurchaseReturnAttachment'));

    const purchaseLines = computed<PurchaseReturnLine[]>(() => (
        Array.isArray(params.formData?.purchaselines) ? params.formData.purchaselines : []
    ));

    const itemCountLabel = computed(() => {
        const count = purchaseLines.value.length;

        return count === 1 ? '1 item' : `${count} items`;
    });

    const selectedPurchase = computed(() =>
        purchasesdata.value.find((item) => String(item.id) === normalizeId(selectedTransactionId.value)),
    );

    const totals = computed(() => {
        const received = purchaseLines.value.reduce((sum, line) => sum + toNumber(line.quantity_received), 0);
        const returned = purchaseLines.value.reduce((sum, line) => sum + toNumber(line.quantity_returned), 0);
        const remaining = Math.max(received - returned, 0);
        const amount = purchaseLines.value.reduce((sum, line) => sum + toNumber(line.row_subtotal), 0);
        const percent = received > 0 ? Math.min((returned / received) * 100, 100) : 0;

        return { received, returned, remaining, amount, percent };
    });

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

    function persistLines(lines: PurchaseReturnLine[]) {
        const next = lines.map((line) => ({ ...line }));
        persist({
            purchaselines: next,
            final_amount: Number(next.reduce((sum, line) => sum + toNumber(line.row_subtotal), 0).toFixed(2)),
        });
    }

    function pricedLine(line: PurchaseReturnLine, receiveReset = false): PurchaseReturnLine {
        const received = Math.max(toNumber(line.quantity_received), 0);
        let returned = toNumber(line.quantity_returned);

        if (receiveReset) {
            returned = 0;
        }

        returned = Math.min(Math.max(returned, 0), received);
        const rate = toNumber(line.purchase_rate);

        return {
            ...line,
            purchase_rate: rate,
            quantity_received: received,
            quantity_returned: returned,
            remaining_qty: Number((received - returned).toFixed(4)),
            row_subtotal: Number((rate * returned).toFixed(2)),
        };
    }

    function applyScopedDefaults() {
        if (isSuperadmin.value || isEdit.value) {
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

    async function loadSuppliers(companyId: string | number | null | undefined, branchId: string | number | null | undefined) {
        const key = `${normalizeId(companyId)}:${normalizeId(branchId)}`;

        if (! normalizeId(companyId) || ! normalizeId(branchId)) {
            suppliersdata.value = [];

            return;
        }

        if (key === lastFetchedSupplierKey.value) {
            return;
        }

        lastFetchedSupplierKey.value = key;

        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchSuppliers, {
                params: { company_id: companyId, branch_id: branchId },
            });
            suppliersdata.value = response.data ?? [];
        } catch {
            suppliersdata.value = [];
        }
    }

    async function loadEligiblePurchases() {
        const key = `${normalizeId(selectedCompanyId.value)}:${normalizeId(selectedBranchId.value)}:${normalizeId(selectedContactId.value)}`;

        if (! scopeReady.value || ! normalizeId(selectedContactId.value)) {
            purchasesdata.value = [];

            return;
        }

        if (key === lastFetchedPurchaseKey.value) {
            return;
        }

        lastFetchedPurchaseKey.value = key;

        try {
            const response = await window.axios.get(API_ENDPOINTS.purchaseReturnEligiblePurchases, {
                params: {
                    company_id: selectedCompanyId.value,
                    branch_id: selectedBranchId.value,
                    contact_id: selectedContactId.value,
                },
            });
            purchasesdata.value = response.data ?? [];
        } catch {
            purchasesdata.value = [];
        }
    }

    async function loadPurchaseLines(transactionId: string | number | null | undefined, resetReturned = false) {
        const id = normalizeId(transactionId);

        if (! id) {
            persistLines([]);

            return;
        }

        if (id === lastLoadedTransactionId.value && purchaseLines.value.length > 0) {
            return;
        }

        lastLoadedTransactionId.value = id;
        loadingLines.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.purchaseReturnPurchase(id));
            const lines = Array.isArray(response.data?.purchaselines) ? response.data.purchaselines : [];
            persist({
                purchase_order_no: response.data?.invoice_no ?? selectedPurchase.value?.invoice_no ?? '',
                parent_id: id,
            });
            persistLines(lines.map((line: PurchaseReturnLine) => pricedLine(line, resetReturned)));
        } catch {
            persistLines([]);
        } finally {
            loadingLines.value = false;
        }
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value || isEdit.value) {
            return;
        }

        persist({
            branch_id: '',
            contact_id: '',
            transaction_id: '',
            parent_id: '',
            purchase_order_no: '',
            purchaselines: [],
            final_amount: 0,
        });
        lastFetchedCompanyId.value = '';
        lastFetchedSupplierKey.value = '';
        lastFetchedPurchaseKey.value = '';
        lastLoadedTransactionId.value = '';
        purchasesdata.value = [];
        await loadBranchOptions(companyId);
    }

    async function handleBranchChange() {
        if (isEdit.value) {
            return;
        }

        persist({
            contact_id: '',
            transaction_id: '',
            parent_id: '',
            purchase_order_no: '',
            purchaselines: [],
            final_amount: 0,
        });
        lastFetchedSupplierKey.value = '';
        lastFetchedPurchaseKey.value = '';
        lastLoadedTransactionId.value = '';
        purchasesdata.value = [];
        await loadSuppliers(selectedCompanyId.value, selectedBranchId.value);
    }

    async function handleSupplierChange() {
        if (isEdit.value) {
            return;
        }

        persist({
            transaction_id: '',
            parent_id: '',
            purchase_order_no: '',
            purchaselines: [],
            final_amount: 0,
        });
        lastFetchedPurchaseKey.value = '';
        lastLoadedTransactionId.value = '';
        await loadEligiblePurchases();
    }

    function setReturnedQty(index: number, value: unknown) {
        persistLines(purchaseLines.value.map((line, lineIndex) => (
            lineIndex === index ? pricedLine({ ...line, quantity_returned: toNumber(value) }) : line
        )));
    }

    function stepReturnedQty(index: number, delta: number) {
        const line = purchaseLines.value[index];

        if (! line) {
            return;
        }

        setReturnedQty(index, toNumber(line.quantity_returned) + delta);
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
        resolveMediaUrl(params.formData?.attachment_url)
        || resolveMediaUrl(params.formData?.attachment)
    ));

    function chooseImage(event: MouseEvent) {
        openLfmImagePicker(event, appUrl);
    }

    function clearAttachment() {
        persist({ attachment: '', attachment_url: '' });
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
            await loadSuppliers(selectedCompanyId.value, selectedBranchId.value);
        }

        if (isEdit.value) {
            persistLines(purchaseLines.value.map((line) => pricedLine(line)));

            if (params.formData?.purchase_order_no) {
                purchasesdata.value = [{
                    id: selectedTransactionId.value,
                    text: params.formData.purchase_order_no,
                    invoice_no: params.formData.purchase_order_no,
                }];
            }
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
        () => `${normalizeId(params.formData?.company_id)}:${normalizeId(params.formData?.branch_id)}`,
        async (key, previousKey) => {
            if (key === previousKey) {
                return;
            }

            await handleBranchChange();
        },
    );

    watch(
        () => normalizeId(params.formData?.contact_id),
        async (contactId, previousContactId) => {
            if (contactId === previousContactId) {
                return;
            }

            await handleSupplierChange();
        },
    );

    watch(
        () => normalizeId(params.formData?.transaction_id),
        async (transactionId, previousTransactionId) => {
            if (isEdit.value || transactionId === previousTransactionId) {
                return;
            }

            persist({ parent_id: transactionId });
            await loadPurchaseLines(transactionId, true);
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />
    <TextElement v-if="showHiddenCompanyField && !isEdit" name="company_id" hidden="true" />
    <TextElement v-if="showHiddenBranchField && !isEdit" name="branch_id" hidden="true" />
    <TextElement name="parent_id" hidden="true" />
    <TextElement name="status" hidden="true" default="pending" />
    <TextElement name="payment_status" hidden="true" default="due" />
    <TextElement name="final_amount" hidden="true" />

    <StaticElement name="section_return" :columns="colFull">
        <div class="company-section-header company-section-header-indigo">
            <span class="company-section-icon company-section-icon-indigo">
                <RefreshCw size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Purchase return</h6>
                <p class="company-section-subtitle mb-0">
                    {{ isEdit
                        ? 'Location and purchase order are locked. Only return quantities can be updated.'
                        : 'Choose a received purchase order, then enter the quantity to return' }}
                </p>
            </div>
        </div>
    </StaticElement>

    <template v-if="isEdit">
        <StaticElement name="edit_summary" :columns="colFull">
            <div class="receiving-note-facts">
                <div class="receiving-note-facts__card" v-if="showCompanyField">
                    <span>Company</span>
                    <strong>{{ params.formData?.company_name || '—' }}</strong>
                </div>
                <div class="receiving-note-facts__card" v-if="showBranchField">
                    <span>Branch</span>
                    <strong>{{ params.formData?.branch_name || '—' }}</strong>
                </div>
                <div class="receiving-note-facts__card">
                    <span>Supplier</span>
                    <strong>{{ params.formData?.business_name || '—' }}</strong>
                </div>
                <div class="receiving-note-facts__card">
                    <span>Reference no</span>
                    <strong>{{ params.formData?.invoice_no || '—' }}</strong>
                </div>
                <div class="receiving-note-facts__card">
                    <span>Return date</span>
                    <strong>{{ params.formData?.transaction_date_label || params.formData?.transaction_date || '—' }}</strong>
                </div>
                <div class="receiving-note-facts__card">
                    <span>Purchase order</span>
                    <strong>{{ params.formData?.purchase_order_no || '—' }}</strong>
                </div>
            </div>
        </StaticElement>
        <TextElement name="company_id" hidden="true" />
        <TextElement name="branch_id" hidden="true" />
        <TextElement name="contact_id" hidden="true" />
        <TextElement name="transaction_id" hidden="true" />
        <TextElement name="invoice_no" hidden="true" />
        <TextElement name="transaction_date" hidden="true" />
    </template>

    <template v-else>
        <SelectElement
            v-if="showCompanyField"
            name="company_id"
            :native="false"
            :items="companiesdata"
            id="PurchaseReturnCompanyId"
            field-name="PurchaseReturnCompanyId"
            placeholder="Select company"
            label="Company"
            :columns="colQuarter"
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
            id="PurchaseReturnBranchId"
            field-name="PurchaseReturnBranchId"
            placeholder="Select branch"
            label="Branch"
            :columns="colQuarter"
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
            :items="suppliersdata"
            id="PurchaseReturnContactId"
            field-name="PurchaseReturnContactId"
            placeholder="Select supplier"
            label="Supplier"
            :columns="colQuarter"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :disabled="supplierDisabled"
            rules="required"
            info="Only received purchase orders for this supplier can be returned."
        />

        <SelectElement
            name="transaction_id"
            :native="false"
            :items="purchasesdata"
            id="PurchaseReturnTransactionId"
            field-name="PurchaseReturnTransactionId"
            placeholder="Select purchase order"
            label="Purchase order"
            :columns="colQuarter"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :disabled="purchaseDisabled"
            rules="required"
            info="Received purchase orders that do not already have a return."
        />

        <TextElement
            id="PurchaseReturnInvoiceNo"
            field-name="PurchaseReturnInvoiceNo"
            name="invoice_no"
            label="Reference no"
            placeholder="Auto-generated if blank"
            :columns="colQuarter"
            autocomplete="off"
            info="Leave empty to generate from the company purchase return prefix."
        />

        <DateElement
            id="PurchaseReturnDate"
            field-name="PurchaseReturnDate"
            name="transaction_date"
            label="Purchase return date"
            placeholder="Select return date"
            :columns="colQuarter"
            :floating="false"
            rules="required"
        />

        <TextElement
            :id="imageInputId"
            field-name="Attachment"
            name="attachment"
            label="Attachment"
            placeholder="Select return attachment"
            :columns="colQuarter"
            :add-classes="{
                ElementAddon: {
                    container: 'p-0',
                },
            }"
        >
            <template #addon-before>
                <button
                    :data-input="imageInputId"
                    data-field-name="attachment"
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
                        alt="Purchase return attachment"
                        class="company-logo-preview-img d-block rounded object-fit-contain"
                        style="height: 4.5rem"
                    >
                    <button
                        v-if="imagePreviewUrl"
                        type="button"
                        class="btn btn-sm btn-link text-danger px-0"
                        @click="clearAttachment"
                    >
                        Remove attachment
                    </button>
                </div>
            </template>
        </TextElement>
    </template>

    <StaticElement v-if="!isEdit && selectedPurchase" name="po_context" :columns="colFull">
        <div class="receiving-note-context">
            <div>
                <p class="purchase-settlement__eyebrow">Selected order</p>
                <h6 class="purchase-settlement__heading">
                    {{ selectedPurchase.invoice_no || selectedPurchase.text || 'Purchase order' }}
                </h6>
            </div>
            <dl>
                <div>
                    <dt>Order date</dt>
                    <dd>{{ selectedPurchase.transaction_date || '—' }}</dd>
                </div>
                <div>
                    <dt>Order total</dt>
                    <dd>{{ money(selectedPurchase.final_amount) }}</dd>
                </div>
            </dl>
        </div>
    </StaticElement>

    <StaticElement name="section_items" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <Package size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Return items</h6>
                <p class="company-section-subtitle mb-0">
                    Enter the quantity to return. Remaining quantity and line total update automatically.
                </p>
            </div>
            <span class="purchase-form__count">{{ itemCountLabel }}</span>
        </div>
    </StaticElement>

    <StaticElement name="lines_editor" :columns="colFull">
        <div class="receiving-note-lines">
            <div v-if="loadingLines" class="receiving-note-lines__empty">
                Loading purchase lines…
            </div>
            <div v-else-if="purchaseLines.length === 0" class="receiving-note-lines__empty">
                <Box size="md" class="receiving-note-lines__empty-icon" />
                <strong>No items to return</strong>
                <span>{{ isEdit ? 'This purchase return has no purchase lines.' : 'Select a received purchase order to load items.' }}</span>
            </div>
            <div v-else class="receiving-note-lines__table-wrap">
                <table class="receiving-note-lines__table">
                    <thead>
                        <tr>
                            <th class="is-count">#</th>
                            <th>Product</th>
                            <th class="is-num">Rate</th>
                            <th class="is-num">Purchase qty</th>
                            <th class="is-num">Received qty</th>
                            <th class="is-num">Remaining</th>
                            <th class="is-num">Return qty</th>
                            <th class="is-num">Return subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in purchaseLines" :key="line.id ?? index">
                            <td class="is-count">{{ index + 1 }}</td>
                            <td>
                                <strong>{{ line.product_name || '—' }}</strong>
                                <span v-if="line.sku" class="receiving-note-lines__sku">{{ line.sku }}</span>
                            </td>
                            <td class="is-num">{{ money(line.purchase_rate) }}</td>
                            <td class="is-num">{{ line.quantity }}</td>
                            <td class="is-num">{{ line.quantity_received }}</td>
                            <td class="is-num">
                                <span
                                    class="receiving-note-lines__remaining"
                                    :class="{ 'is-clear': toNumber(line.remaining_qty) === 0 }"
                                >
                                    {{ line.remaining_qty }}
                                </span>
                            </td>
                            <td class="is-num">
                                <div class="receiving-note-qty">
                                    <button
                                        type="button"
                                        class="receiving-note-qty__btn"
                                        :disabled="toNumber(line.quantity_returned) <= 0"
                                        @click="stepReturnedQty(index, -1)"
                                    >
                                        <Minus size="xs" />
                                    </button>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        :max="toNumber(line.quantity_received)"
                                        :value="line.quantity_returned"
                                        @input="setReturnedQty(index, ($event.target as HTMLInputElement).value)"
                                    >
                                    <button
                                        type="button"
                                        class="receiving-note-qty__btn"
                                        :disabled="toNumber(line.quantity_returned) >= toNumber(line.quantity_received)"
                                        @click="stepReturnedQty(index, 1)"
                                    >
                                        <Plus size="xs" />
                                    </button>
                                </div>
                            </td>
                            <td class="is-num">
                                <strong>{{ money(line.row_subtotal) }}</strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="purchaseLines.length > 0" class="receiving-note-lines__summary">
                <div class="receiving-note-lines__progress">
                    <span>Returning {{ totals.returned }} of {{ totals.received }} received</span>
                    <div class="receiving-note-lines__bar">
                        <i :style="{ width: `${totals.percent}%` }"></i>
                    </div>
                </div>
                <strong>{{ money(totals.amount) }}</strong>
            </div>
        </div>
    </StaticElement>
</template>
