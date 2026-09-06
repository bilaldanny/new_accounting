<script setup lang="ts">
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import type { SellReturnLine } from '@/composables/sellReturn';
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

    const customersdata = ref<Array<{ id: number | string; text?: string; business_name?: string }>>([]);
    const sellsdata = ref<Array<{ id: number | string; text?: string; invoice_no?: string; transaction_date?: string; final_amount?: number | string }>>([]);
    const loadingLines = ref(false);
    const lastFetchedCompanyId = ref('');
    const lastFetchedCustomerKey = ref('');
    const lastFetchedSellKey = ref('');
    const lastLoadedTransactionId = ref('');
    const hydratingFromSell = ref(false);

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedBranchId = computed(() => params.formData?.branch_id ?? '');
    const selectedContactId = computed(() => params.formData?.contact_id ?? '');
    const selectedTransactionId = computed(() => params.formData?.transaction_id ?? '');
    const scopeReady = computed(() => Boolean(normalizeId(selectedCompanyId.value) && normalizeId(selectedBranchId.value)));
    const customerDisabled = computed(() => isEdit.value || ! scopeReady.value);
    const sellDisabled = computed(() => isEdit.value || ! normalizeId(selectedContactId.value));
    const branchDisabled = computed(() => isEdit.value || (isSuperadmin.value && ! selectedCompanyId.value));
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const imageInputId = computed(() => (isEdit.value ? 'EditSellReturnAttachment' : 'SellReturnAttachment'));

    const sellLines = computed<SellReturnLine[]>(() => (
        Array.isArray(params.formData?.selllines) ? params.formData.selllines : []
    ));

    const itemCountLabel = computed(() => {
        const count = sellLines.value.length;

        return count === 1 ? '1 item' : `${count} items`;
    });

    const selectedSell = computed(() =>
        sellsdata.value.find((item) => String(item.id) === normalizeId(selectedTransactionId.value)),
    );

    const totals = computed(() => {
        const issued = sellLines.value.reduce((sum, line) => sum + toNumber(line.quantity_issue), 0);
        const returned = sellLines.value.reduce((sum, line) => sum + toNumber(line.quantity_returned), 0);
        const remaining = Math.max(issued - returned, 0);
        const amount = sellLines.value.reduce((sum, line) => sum + toNumber(line.row_subtotal), 0);
        const percent = issued > 0 ? Math.min((returned / issued) * 100, 100) : 0;

        return { issued, returned, remaining, amount, percent };
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

    function persistLines(lines: SellReturnLine[]) {
        const next = lines.map((line) => ({ ...line }));
        persist({
            selllines: next,
            final_amount: Number(next.reduce((sum, line) => sum + toNumber(line.row_subtotal), 0).toFixed(2)),
        });
    }

    function pricedLine(line: SellReturnLine, receiveReset = false): SellReturnLine {
        const received = Math.max(toNumber(line.quantity_issue), 0);
        let returned = toNumber(line.quantity_returned);

        if (receiveReset) {
            returned = 0;
        }

        returned = Math.min(Math.max(returned, 0), received);
        const rate = toNumber(line.unit_price_after_discount);

        return {
            ...line,
            unit_price_after_discount: rate,
            quantity_issue: received,
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

    async function loadEligibleSells() {
        const key = `${normalizeId(selectedCompanyId.value)}:${normalizeId(selectedBranchId.value)}:${normalizeId(selectedContactId.value)}`;

        if (! scopeReady.value || ! normalizeId(selectedContactId.value)) {
            sellsdata.value = [];

            return;
        }

        if (key === lastFetchedSellKey.value) {
            return;
        }

        lastFetchedSellKey.value = key;

        try {
            const response = await window.axios.get(API_ENDPOINTS.sellReturnEligibleSells, {
                params: {
                    company_id: selectedCompanyId.value,
                    branch_id: selectedBranchId.value,
                    contact_id: selectedContactId.value,
                },
            });
            sellsdata.value = response.data ?? [];
        } catch {
            sellsdata.value = [];
        }
    }

    async function loadSellLines(transactionId: string | number | null | undefined, resetReturned = false) {
        const id = normalizeId(transactionId);

        if (! id) {
            persistLines([]);

            return;
        }

        if (id === lastLoadedTransactionId.value && sellLines.value.length > 0) {
            return;
        }

        lastLoadedTransactionId.value = id;
        loadingLines.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.sellReturnSell(id));
            const lines = Array.isArray(response.data?.selllines) ? response.data.selllines : [];
            persist({
                sell_order_no: response.data?.invoice_no ?? selectedSell.value?.invoice_no ?? '',
                parent_id: id,
            });
            persistLines(lines.map((line: SellReturnLine) => pricedLine(line, resetReturned)));
        } catch {
            persistLines([]);
        } finally {
            loadingLines.value = false;
        }
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value || isEdit.value || hydratingFromSell.value) {
            return;
        }

        persist({
            branch_id: '',
            contact_id: '',
            transaction_id: '',
            parent_id: '',
            sell_order_no: '',
            selllines: [],
            final_amount: 0,
        });
        lastFetchedCompanyId.value = '';
        lastFetchedCustomerKey.value = '';
        lastFetchedSellKey.value = '';
        lastLoadedTransactionId.value = '';
        sellsdata.value = [];
        await loadBranchOptions(companyId);
    }

    async function handleBranchChange() {
        if (isEdit.value || hydratingFromSell.value) {
            return;
        }

        persist({
            contact_id: '',
            transaction_id: '',
            parent_id: '',
            sell_order_no: '',
            selllines: [],
            final_amount: 0,
        });
        lastFetchedCustomerKey.value = '';
        lastFetchedSellKey.value = '';
        lastLoadedTransactionId.value = '';
        sellsdata.value = [];
        await loadCustomers(selectedCompanyId.value, selectedBranchId.value);
    }

    async function handleCustomerChange() {
        if (isEdit.value || hydratingFromSell.value) {
            return;
        }

        persist({
            transaction_id: '',
            parent_id: '',
            sell_order_no: '',
            selllines: [],
            final_amount: 0,
        });
        lastFetchedSellKey.value = '';
        lastLoadedTransactionId.value = '';
        await loadEligibleSells();
    }

    function setReturnedQty(index: number, value: unknown) {
        persistLines(sellLines.value.map((line, lineIndex) => (
            lineIndex === index ? pricedLine({ ...line, quantity_returned: toNumber(value) }) : line
        )));
    }

    function stepReturnedQty(index: number, delta: number) {
        const line = sellLines.value[index];

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
            await loadCustomers(selectedCompanyId.value, selectedBranchId.value);
        }

        if (isEdit.value) {
            persistLines(sellLines.value.map((line) => pricedLine(line)));

            if (params.formData?.sell_order_no) {
                sellsdata.value = [{
                    id: selectedTransactionId.value,
                    text: params.formData.sell_order_no,
                    invoice_no: params.formData.sell_order_no,
                }];
            }

            return;
        }

        const sellId = new URLSearchParams(window.location.search).get('sell_id');

        if (! sellId) {
            return;
        }

        hydratingFromSell.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.sellReturnSell(sellId));
            persist({
                company_id: response.data?.company_id ?? '',
                branch_id: response.data?.branch_id ?? '',
                contact_id: response.data?.contact_id ?? '',
                transaction_id: sellId,
                parent_id: sellId,
                sell_order_no: response.data?.invoice_no ?? '',
            });
            await loadBranchOptions(response.data?.company_id);
            lastFetchedCustomerKey.value = '';
            await loadCustomers(response.data?.company_id, response.data?.branch_id);
            lastFetchedSellKey.value = '';
            await loadEligibleSells();
            lastLoadedTransactionId.value = '';
            await loadSellLines(sellId, true);
        } catch {
            persistLines([]);
        } finally {
            hydratingFromSell.value = false;
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

            await handleCustomerChange();
        },
    );

    watch(
        () => normalizeId(params.formData?.transaction_id),
        async (transactionId, previousTransactionId) => {
            if (isEdit.value || transactionId === previousTransactionId) {
                return;
            }

            persist({ parent_id: transactionId });
            await loadSellLines(transactionId, true);
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
                <h6 class="company-section-title mb-0">Sell return</h6>
                <p class="company-section-subtitle mb-0">
                    {{ isEdit
                        ? 'Location and sell invoice are locked. Only return quantities can be updated.'
                        : 'Choose an issued sell invoice, then enter the quantity to return' }}
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
                    <span>Customer</span>
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
                    <span>Sell invoice</span>
                    <strong>{{ params.formData?.sell_order_no || '—' }}</strong>
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
            id="SellReturnCompanyId"
            field-name="SellReturnCompanyId"
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
            id="SellReturnBranchId"
            field-name="SellReturnBranchId"
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
            :items="customersdata"
            id="SellReturnContactId"
            field-name="SellReturnContactId"
            placeholder="Select customer"
            label="Customer"
            :columns="colQuarter"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :disabled="customerDisabled"
            rules="required"
            info="Only issued sell invoices for this customer can be returned."
        />

        <SelectElement
            name="transaction_id"
            :native="false"
            :items="sellsdata"
            id="SellReturnTransactionId"
            field-name="SellReturnTransactionId"
            placeholder="Select sell invoice"
            label="Sell invoice"
            :columns="colQuarter"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :disabled="sellDisabled"
            rules="required"
            info="Received sell invoices that do not already have a return."
        />

        <TextElement
            id="SellReturnInvoiceNo"
            field-name="SellReturnInvoiceNo"
            name="invoice_no"
            label="Reference no"
            placeholder="Auto-generated if blank"
            :columns="colQuarter"
            autocomplete="off"
            info="Leave empty to generate from the company sell return prefix."
        />

        <DateElement
            id="SellReturnDate"
            field-name="SellReturnDate"
            name="transaction_date"
            label="Sell return date"
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
                        alt="Sell return attachment"
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

    <StaticElement v-if="!isEdit && selectedSell" name="po_context" :columns="colFull">
        <div class="receiving-note-context">
            <div>
                <p class="purchase-settlement__eyebrow">Selected order</p>
                <h6 class="purchase-settlement__heading">
                    {{ selectedSell.invoice_no || selectedSell.text || 'Sell invoice' }}
                </h6>
            </div>
            <dl>
                <div>
                    <dt>Order date</dt>
                    <dd>{{ selectedSell.transaction_date || '—' }}</dd>
                </div>
                <div>
                    <dt>Order total</dt>
                    <dd>{{ money(selectedSell.final_amount) }}</dd>
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
                Loading sell lines…
            </div>
            <div v-else-if="sellLines.length === 0" class="receiving-note-lines__empty">
                <Box size="md" class="receiving-note-lines__empty-icon" />
                <strong>No items to return</strong>
                <span>{{ isEdit ? 'This sell return has no sell lines.' : 'Select an issued sell invoice to load items.' }}</span>
            </div>
            <div v-else class="receiving-note-lines__table-wrap">
                <table class="receiving-note-lines__table">
                    <thead>
                        <tr>
                            <th class="is-count">#</th>
                            <th>Product</th>
                            <th class="is-num">Rate</th>
                            <th class="is-num">Sell qty</th>
                            <th class="is-num">Issue qty</th>
                            <th class="is-num">Remaining</th>
                            <th class="is-num">Return qty</th>
                            <th class="is-num">Return subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in sellLines" :key="line.id ?? index">
                            <td class="is-count">{{ index + 1 }}</td>
                            <td>
                                <strong>{{ line.product_name || '—' }}</strong>
                                <span v-if="line.sku" class="receiving-note-lines__sku">{{ line.sku }}</span>
                            </td>
                            <td class="is-num">{{ money(line.unit_price_after_discount) }}</td>
                            <td class="is-num">{{ line.quantity }}</td>
                            <td class="is-num">{{ line.quantity_issue }}</td>
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
                                        :max="toNumber(line.quantity_issue)"
                                        :value="line.quantity_returned"
                                        @input="setReturnedQty(index, ($event.target as HTMLInputElement).value)"
                                    >
                                    <button
                                        type="button"
                                        class="receiving-note-qty__btn"
                                        :disabled="toNumber(line.quantity_returned) >= toNumber(line.quantity_issue)"
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

            <div v-if="sellLines.length > 0" class="receiving-note-lines__summary">
                <div class="receiving-note-lines__progress">
                    <span>Returning {{ totals.returned }} of {{ totals.issued }} issued</span>
                    <div class="receiving-note-lines__bar">
                        <i :style="{ width: `${totals.percent}%` }"></i>
                    </div>
                </div>
                <strong>{{ money(totals.amount) }}</strong>
            </div>
        </div>
    </StaticElement>
</template>
