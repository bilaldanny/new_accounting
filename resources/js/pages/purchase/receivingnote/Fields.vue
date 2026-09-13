<script setup lang="ts">
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import type { ReceivingNoteLine } from '@/composables/receivingNote';
    import { usePage } from '@inertiajs/vue3';
    import { Boxes, CalendarDays, CheckCircle2, Minus, Package, Plus } from '@lucide/vue';
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
            wrapper: 'product-form-card__body journal-form-card__body--flush receiving-note-lines-card',
        },
    };

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

    const purchaseLines = computed<ReceivingNoteLine[]>(() => (
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
        const ordered = purchaseLines.value.reduce((sum, line) => sum + toNumber(line.quantity), 0);
        const received = purchaseLines.value.reduce((sum, line) => sum + toNumber(line.quantity_received), 0);
        const remaining = Math.max(ordered - received, 0);
        const percent = ordered > 0 ? Math.min((received / ordered) * 100, 100) : 0;

        return {
            ordered,
            received,
            remaining,
            percent,
            isFullyReceived: remaining === 0 && received > 0,
        };
    });

    const hasPurchaseOrder = computed(() => (
        Boolean(normalizeId(selectedTransactionId.value) || params.formData?.purchase_order_no)
    ));

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

    function persistLines(lines: ReceivingNoteLine[]) {
        persist({ purchaselines: lines.map((line) => ({ ...line })) });
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

    function normalizeLines(lines: ReceivingNoteLine[], receiveAll: boolean): ReceivingNoteLine[] {
        return lines.map((line) => {
            const quantity = Math.max(toNumber(line.quantity), 0);
            let received = toNumber(line.quantity_received);

            if (receiveAll && received <= 0) {
                received = quantity;
            }

            received = Math.min(Math.max(received, 0), quantity);

            return {
                ...line,
                quantity,
                quantity_received: received,
                remaining_qty: Number((quantity - received).toFixed(4)),
            };
        });
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
            const response = await window.axios.get(API_ENDPOINTS.receivingNoteEligiblePurchases, {
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

    async function loadPurchaseLines(transactionId: string | number | null | undefined, receiveAll = false) {
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
            const response = await window.axios.get(API_ENDPOINTS.receivingNotePurchase(id));
            const lines = Array.isArray(response.data?.purchaselines) ? response.data.purchaselines : [];
            persist({
                purchase_order_no: response.data?.invoice_no ?? selectedPurchase.value?.invoice_no ?? '',
                parent_id: id,
            });
            persistLines(normalizeLines(lines, receiveAll));
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
        });
        lastFetchedPurchaseKey.value = '';
        lastLoadedTransactionId.value = '';
        await loadEligiblePurchases();
    }

    function setReceivedQty(index: number, value: unknown) {
        persistLines(purchaseLines.value.map((line, lineIndex) => {
            if (lineIndex !== index) {
                return line;
            }

            const quantity = Math.max(toNumber(line.quantity), 0);
            const received = Math.min(Math.max(toNumber(value), 0), quantity);

            return {
                ...line,
                quantity_received: received,
                remaining_qty: Number((quantity - received).toFixed(4)),
            };
        }));
    }

    function stepReceivedQty(index: number, delta: number) {
        const line = purchaseLines.value[index];

        if (! line) {
            return;
        }

        setReceivedQty(index, toNumber(line.quantity_received) + delta);
    }

    function receiveAllLines() {
        persistLines(purchaseLines.value.map((line) => {
            const quantity = Math.max(toNumber(line.quantity), 0);

            return {
                ...line,
                quantity_received: quantity,
                remaining_qty: 0,
            };
        }));
    }

    function resetReceivedLines() {
        persistLines(purchaseLines.value.map((line) => {
            const quantity = Math.max(toNumber(line.quantity), 0);

            return {
                ...line,
                quantity_received: 0,
                remaining_qty: quantity,
            };
        }));
    }

    function remainingClass(line: ReceivingNoteLine): string {
        const remaining = toNumber(line.remaining_qty);
        const received = toNumber(line.quantity_received);

        if (remaining === 0 && received > 0) {
            return 'is-clear';
        }

        if (remaining > 0) {
            return 'is-pending';
        }

        return '';
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
            persistLines(normalizeLines(purchaseLines.value, false));

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
    <TextElement name="status" hidden="true" default="received" />

    <GroupElement name="group_receipt" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_receipt" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <CalendarDays class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Goods receipt</h2>
                    <p class="product-form-section-copy">
                        {{ isEdit
                            ? 'Location and purchase order are locked. Only received quantities can be updated.'
                            : 'Choose location, supplier, and the approved purchase order to receive' }}
                    </p>
                </div>
                <span
                    v-if="hasPurchaseOrder"
                    class="receiving-note-synced"
                >
                    <CheckCircle2 class="h-3 w-3" />
                    PO Synced
                </span>
            </div>
        </StaticElement>

        <StaticElement v-if="isEdit" name="edit_summary" :columns="colFull">
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
                    <span>Purchase order</span>
                    <strong class="is-mono is-teal">{{ params.formData?.purchase_order_no || '—' }}</strong>
                </div>
                <div class="receiving-note-facts__card">
                    <span>GRN ref no</span>
                    <strong class="is-mono">{{ params.formData?.invoice_no || '—' }}</strong>
                </div>
            </div>
        </StaticElement>
        <TextElement v-if="isEdit" name="company_id" hidden="true" />
        <TextElement v-if="isEdit" name="branch_id" hidden="true" />
        <TextElement v-if="isEdit" name="contact_id" hidden="true" />
        <TextElement v-if="isEdit" name="transaction_id" hidden="true" />

        <SelectElement
            v-if="!isEdit && showCompanyField"
                name="company_id"
                :native="false"
                :items="companiesdata"
                id="ReceivingCompanyId"
                field-name="ReceivingCompanyId"
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
            v-if="!isEdit && showBranchField"
            name="branch_id"
            :native="false"
            :items="branchesdata"
            id="ReceivingBranchId"
            field-name="ReceivingBranchId"
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
            v-if="!isEdit"
            name="contact_id"
            :native="false"
            :items="suppliersdata"
            id="ReceivingContactId"
            field-name="ReceivingContactId"
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
            info="Only approved purchase orders for this supplier can be received."
        />

        <SelectElement
            v-if="!isEdit"
            name="transaction_id"
            :native="false"
            :items="purchasesdata"
            id="ReceivingTransactionId"
            field-name="ReceivingTransactionId"
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
            info="Approved purchase orders that do not already have a receiving note."
        />

        <StaticElement v-if="selectedPurchase || params.formData?.purchase_order_no" name="po_context" :columns="colFull">
            <div class="receiving-note-context">
                <div class="receiving-note-context__order">
                    <span>Selected order</span>
                    <strong>
                        {{ selectedPurchase?.invoice_no || params.formData?.purchase_order_no || 'Purchase order' }}
                    </strong>
                </div>
                <dl>
                    <div>
                        <dt>Date</dt>
                        <dd>{{ selectedPurchase?.transaction_date || params.formData?.transaction_date || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Order total</dt>
                        <dd>{{ money(selectedPurchase?.final_amount ?? params.formData?.final_amount) }}</dd>
                    </div>
                    <div>
                        <dt>Supplier invoice</dt>
                        <dd>{{ params.formData?.sup_ref_no || '—' }}</dd>
                    </div>
                </dl>
            </div>
        </StaticElement>
    </GroupElement>

    <GroupElement name="group_items" :columns="colFull" :add-classes="linesCardClasses">
        <StaticElement name="section_items" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Boxes class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Received items</h2>
                    <p class="product-form-section-copy">
                        Enter the quantity received for each line. Remaining quantity updates automatically.
                    </p>
                </div>
                <div class="receiving-note-lines__toolbar">
                    <span class="purchase-form__count">{{ itemCountLabel }}</span>
                    <button
                        v-if="purchaseLines.length > 0"
                        type="button"
                        class="receiving-note-batch receiving-note-batch--teal"
                        title="Mark all line items as fully received"
                        @click="receiveAllLines"
                    >
                        Receive All
                    </button>
                    <button
                        v-if="purchaseLines.length > 0"
                        type="button"
                        class="receiving-note-batch"
                        title="Reset all received quantities to 0"
                        @click="resetReceivedLines"
                    >
                        Reset
                    </button>
                </div>
            </div>
        </StaticElement>

        <StaticElement name="lines_editor" :columns="colFull">
            <div class="receiving-note-lines">
                <div v-if="loadingLines" class="receiving-note-lines__empty">
                    Loading purchase lines…
                </div>
                <div v-else-if="purchaseLines.length === 0" class="receiving-note-lines__empty">
                    <Package class="receiving-note-lines__empty-icon h-8 w-8" />
                    <strong>No items to receive</strong>
                    <span>{{ isEdit ? 'This receiving note has no purchase lines.' : 'Select an approved purchase order to load items.' }}</span>
                </div>
                <div v-else class="receiving-note-lines__table-wrap">
                    <table class="receiving-note-lines__table">
                        <thead>
                            <tr>
                                <th class="is-count">#</th>
                                <th>Product</th>
                                <th class="is-num">Purchase qty</th>
                                <th>Unit</th>
                                <th class="is-num">Received qty</th>
                                <th class="is-num">Remaining</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in purchaseLines" :key="line.id ?? index">
                                <td class="is-count">{{ index + 1 }}</td>
                                <td>
                                    <strong>{{ line.product_name || '—' }}</strong>
                                    <span v-if="line.sku" class="receiving-note-lines__sku">{{ line.sku }}</span>
                                </td>
                                <td class="is-num is-qty">{{ line.quantity }}</td>
                                <td>
                                    <span class="receiving-note-lines__unit">
                                        {{ line.unit_name || '—' }}
                                        <small v-if="line.unit_short_name">{{ line.unit_short_name }}</small>
                                    </span>
                                </td>
                                <td class="is-num">
                                    <div class="receiving-note-qty">
                                        <button
                                            type="button"
                                            class="receiving-note-qty__btn"
                                            :disabled="toNumber(line.quantity_received) <= 0"
                                            title="Decrease received quantity"
                                            @click="stepReceivedQty(index, -1)"
                                        >
                                            <Minus class="h-3.5 w-3.5" />
                                        </button>
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            :max="toNumber(line.quantity)"
                                            :value="line.quantity_received"
                                            @input="setReceivedQty(index, ($event.target as HTMLInputElement).value)"
                                        >
                                        <button
                                            type="button"
                                            class="receiving-note-qty__btn"
                                            :disabled="toNumber(line.quantity_received) >= toNumber(line.quantity)"
                                            title="Increase received quantity"
                                            @click="stepReceivedQty(index, 1)"
                                        >
                                            <Plus class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                                <td class="is-num">
                                    <span
                                        class="receiving-note-lines__remaining"
                                        :class="remainingClass(line)"
                                    >
                                        {{ line.remaining_qty }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="purchaseLines.length > 0" class="receiving-note-lines__summary">
                    <div class="receiving-note-lines__progress">
                        <span>Received {{ totals.received }} of {{ totals.ordered }}</span>
                        <strong>{{ totals.remaining }} remaining</strong>
                    </div>
                    <div class="receiving-note-lines__bar">
                        <i
                            :class="{ 'is-complete': totals.isFullyReceived }"
                            :style="{ width: `${totals.percent}%` }"
                        ></i>
                    </div>
                </div>
            </div>
        </StaticElement>
    </GroupElement>
</template>
