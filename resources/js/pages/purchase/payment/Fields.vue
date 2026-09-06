<script setup lang="ts">
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import { usePage } from '@inertiajs/vue3';
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
    const colQuarter = { container: 4, label: 12, wrapper: 12 };
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

    const { fetchCompany, fetchBranch, companiesdata, branchesdata } = useCommons();

    const suppliersdata = ref<Array<{ id: number | string; text?: string; business_name?: string }>>([]);
    const purchasesdata = ref<Array<{ id: number | string; text?: string; invoice_no?: string; remaining_amount?: number | string }>>([]);
    const accountsdata = ref<Array<{ id: number | string; text?: string; name?: string }>>([]);
    const lastFetchedCompanyId = ref('');
    const lastFetchedSupplierKey = ref('');
    const lastFetchedPurchaseKey = ref('');
    const lastAccountScope = ref('');
    const lastLoadedTransactionId = ref('');

    const methodItems = [
        { id: 'cash', text: 'Cash' },
        { id: 'card', text: 'Card' },
        { id: 'cheque', text: 'Cheque' },
        { id: 'bank_transfer', text: 'Bank Transfer' },
        { id: 'other', text: 'Other' },
    ];

    const cardTypeItems = [
        { id: 'cc', text: 'Credit Card' },
        { id: 'dc', text: 'Debit Card' },
        { id: 'visa', text: 'Visa' },
        { id: 'mastercard', text: 'MasterCard' },
    ];

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedBranchId = computed(() => params.formData?.branch_id ?? '');
    const selectedContactId = computed(() => params.formData?.contact_id ?? '');
    const selectedTransactionId = computed(() => params.formData?.transaction_id ?? '');
    const selectedMethod = computed(() => String(params.formData?.method ?? 'cash'));
    const scopeReady = computed(() => Boolean(normalizeId(selectedCompanyId.value) && normalizeId(selectedBranchId.value)));
    const branchDisabled = computed(() => isEdit.value || (isSuperadmin.value && ! selectedCompanyId.value));
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const supplierDisabled = computed(() => isEdit.value || ! scopeReady.value);
    const purchaseDisabled = computed(() => isEdit.value || ! normalizeId(selectedContactId.value));

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
        if (! showBranchField.value) {
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
            const response = await window.axios.get(API_ENDPOINTS.purchasePaymentEligiblePurchases, {
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

    async function loadPaymentAccounts() {
        const key = `${normalizeId(selectedCompanyId.value)}:${normalizeId(selectedBranchId.value)}`;

        if (! scopeReady.value) {
            accountsdata.value = [];

            return;
        }

        if (key === lastAccountScope.value) {
            return;
        }

        lastAccountScope.value = key;

        try {
            const response = await window.axios.get(API_ENDPOINTS.purchasePaymentAccounts, {
                params: {
                    company_id: selectedCompanyId.value,
                    branch_id: selectedBranchId.value,
                },
            });
            accountsdata.value = response.data ?? [];
        } catch {
            accountsdata.value = [];
        }
    }

    async function loadPurchase(transactionId: string | number | null | undefined) {
        const id = normalizeId(transactionId);

        if (! id) {
            persist({
                invoice_no: '',
                supplier_name: '',
                business_name: '',
                branch_name: '',
                final_amount: '',
                remaining_amount: '',
                amount: isEdit.value ? params.formData?.amount : '',
            });

            return;
        }

        if (id === lastLoadedTransactionId.value && params.formData?.invoice_no) {
            return;
        }

        lastLoadedTransactionId.value = id;

        try {
            const response = await window.axios.get(API_ENDPOINTS.purchasePaymentPurchase(id));
            const remaining = response.data?.remaining_amount ?? 0;
            persist({
                company_id: response.data?.company_id ?? params.formData?.company_id,
                branch_id: response.data?.branch_id ?? params.formData?.branch_id,
                contact_id: response.data?.contact_id ?? params.formData?.contact_id,
                invoice_no: response.data?.invoice_no ?? '',
                supplier_name: response.data?.supplier_name ?? '',
                business_name: response.data?.business_name ?? '',
                branch_name: response.data?.branch_name ?? '',
                final_amount: response.data?.final_amount ?? params.formData?.final_amount,
                remaining_amount: isEdit.value
                    ? (params.formData?.remaining_amount ?? remaining)
                    : remaining,
                amount: isEdit.value ? params.formData?.amount : remaining,
            });
        } catch {
            persist({
                invoice_no: '',
                remaining_amount: '',
            });
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
            payment_account: '',
        });
        lastFetchedCompanyId.value = '';
        lastFetchedSupplierKey.value = '';
        lastFetchedPurchaseKey.value = '';
        lastAccountScope.value = '';
        lastLoadedTransactionId.value = '';
        await loadBranchOptions(companyId);
    }

    function onDocumentSelected(event: Event) {
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0];

        if (! file) {
            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            persist({
                document: String(reader.result ?? ''),
                file_name: file.name,
            });
        };
        reader.readAsDataURL(file);
        input.value = '';
    }

    onMounted(async () => {
        applyScopedDefaults();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        const companyId = isCompanyadmin.value
            ? authUser.value?.company_id
            : selectedCompanyId.value;

        if (companyId) {
            await loadBranchOptions(companyId);
        }

        if (scopeReady.value) {
            await loadSuppliers(selectedCompanyId.value, selectedBranchId.value);
            await loadPaymentAccounts();
        }

        if (normalizeId(selectedContactId.value)) {
            await loadEligiblePurchases();
        }

        if (normalizeId(selectedTransactionId.value)) {
            await loadPurchase(selectedTransactionId.value);
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
        () => `${normalizeId(selectedCompanyId.value)}:${normalizeId(selectedBranchId.value)}`,
        async () => {
            lastFetchedSupplierKey.value = '';
            lastFetchedPurchaseKey.value = '';
            lastAccountScope.value = '';
            await loadSuppliers(selectedCompanyId.value, selectedBranchId.value);
            await loadPaymentAccounts();
        },
    );

    watch(
        () => normalizeId(selectedContactId.value),
        async (contactId, previous) => {
            if (contactId === previous) {
                return;
            }

            if (! isEdit.value && previous) {
                persist({ transaction_id: '' });
                lastLoadedTransactionId.value = '';
            }

            lastFetchedPurchaseKey.value = '';
            await loadEligiblePurchases();
        },
    );

    watch(
        () => normalizeId(selectedTransactionId.value),
        async (transactionId) => {
            await loadPurchase(transactionId);
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <TextElement v-if="showHiddenCompanyField || isEdit" name="company_id" hidden="true" />
    <TextElement v-if="showHiddenBranchField || isEdit" name="branch_id" hidden="true" />
    <TextElement v-if="isEdit" name="contact_id" hidden="true" />
    <TextElement v-if="isEdit" name="transaction_id" hidden="true" />

    <SelectElement
        v-if="showCompanyField && !isEdit"
        name="company_id"
        :native="false"
        :items="companiesdata"
        id="CompanyId"
        field-name="CompanyId"
        placeholder="Select company"
        label="Company"
        :columns="colQuarter"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="isEdit"
        :rules="companyRules"
    />

    <SelectElement
        v-if="showBranchField && !isEdit"
        name="branch_id"
        :native="false"
        :items="branchesdata"
        id="BranchId"
        field-name="BranchId"
        placeholder="Select branch"
        label="Branch"
        :columns="colQuarter"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :disabled="branchDisabled"
        rules="required"
    />

    <SelectElement
        v-if="!isEdit"
        name="contact_id"
        :native="false"
        :items="suppliersdata"
        id="SupplierId"
        field-name="SupplierId"
        placeholder="Select supplier"
        label="Supplier"
        :columns="colQuarter"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :disabled="supplierDisabled"
        rules="required"
    />

    <SelectElement
        v-if="!isEdit"
        name="transaction_id"
        :native="false"
        :items="purchasesdata"
        id="PurchaseId"
        field-name="PurchaseId"
        placeholder="Select purchase"
        label="Purchase"
        :columns="colQuarter"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :disabled="purchaseDisabled"
        rules="required"
        info="Only purchases with a remaining balance are listed."
    />

    <StaticElement
        v-if="params.formData?.invoice_no || params.formData?.final_amount"
        name="purchase_summary"
        :columns="colFull"
        tag="div"
    >
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <strong>Supplier:</strong> {{ params.formData?.supplier_name || '-' }}<br>
                    <strong>Business:</strong> {{ params.formData?.business_name || '-' }}
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <strong>Reference No:</strong> {{ params.formData?.invoice_no || '-' }}<br>
                    <strong>Branch:</strong> {{ params.formData?.branch_name || '-' }}
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <strong>Total Amount:</strong> {{ money(params.formData?.final_amount) }}<br>
                    <strong>Remaining:</strong> {{ money(params.formData?.remaining_amount) }}
                </div>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="Amount"
        field-name="Amount"
        name="amount"
        label="Amount"
        input-type="number"
        placeholder="Payment amount"
        :columns="colQuarter"
        autocomplete="off"
        rules="required|numeric|min:0.01"
    />

    <DateElement
        id="PaidOn"
        field-name="PaidOn"
        name="paid_on"
        label="Payment date"
        placeholder="Select payment date"
        :columns="colQuarter"
        :floating="false"
        rules="required"
    />

    <SelectElement
        name="method"
        :native="false"
        :items="methodItems"
        id="PaymentMethod"
        field-name="PaymentMethod"
        placeholder="Select method"
        label="Payment method"
        :columns="colQuarter"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        rules="required"
    />

    <SelectElement
        name="payment_account"
        :native="false"
        :items="accountsdata"
        id="PaymentAccount"
        field-name="PaymentAccount"
        placeholder="Select payment account"
        label="Payment account"
        :columns="colQuarter"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        rules="required"
        info="Cash and bank accounts for this branch."
    />

    <StaticElement name="document_upload" :columns="colQuarter" tag="div">
        <label class="form-label" for="PurchasePaymentDocument">Attachment</label>
        <input
            id="PurchasePaymentDocument"
            class="form-control form-control-sm"
            type="file"
            accept="image/*,.pdf"
            @change="onDocumentSelected"
        >
        <TextElement name="document" hidden="true" />
        <TextElement name="file_name" hidden="true" />
        <small v-if="params.formData?.file_name || params.formData?.document" class="text-muted">
            {{ params.formData?.file_name || 'Attachment selected' }}
        </small>
    </StaticElement>

    <template v-if="selectedMethod === 'card'">
        <TextElement
            id="CardNumber"
            field-name="CardNumber"
            name="card_number"
            label="Card number"
            placeholder="Card number"
            :columns="colQuarter"
            autocomplete="off"
            rules="required"
        />
        <TextElement
            id="CardHolderName"
            field-name="CardHolderName"
            name="card_holder_name"
            label="Card holder name"
            placeholder="Card holder name"
            :columns="colQuarter"
            autocomplete="off"
            rules="required"
        />
        <SelectElement
            name="card_type"
            :native="false"
            :items="cardTypeItems"
            id="CardType"
            field-name="CardType"
            placeholder="Select card type"
            label="Card type"
            :columns="colQuarter"
            label-prop="text"
            value-prop="id"
            :floating="false"
            rules="required"
        />
        <TextElement
            id="CardTransactionNumber"
            field-name="CardTransactionNumber"
            name="card_transaction_number"
            label="Card transaction no."
            placeholder="Card transaction no."
            :columns="colQuarter"
            autocomplete="off"
            rules="required"
        />
        <TextElement
            id="CardMonth"
            field-name="CardMonth"
            name="card_month"
            label="Month"
            placeholder="MM"
            :columns="colQuarter"
            autocomplete="off"
            rules="required"
        />
        <TextElement
            id="CardYear"
            field-name="CardYear"
            name="card_year"
            label="Year"
            placeholder="YYYY"
            :columns="colQuarter"
            autocomplete="off"
            rules="required"
        />
        <TextElement
            id="CardSecurity"
            field-name="CardSecurity"
            name="card_security"
            label="Security code"
            placeholder="CVV"
            :columns="colQuarter"
            autocomplete="off"
            rules="required"
        />
    </template>

    <TextElement
        v-if="selectedMethod === 'cheque'"
        id="ChequeNumber"
        field-name="ChequeNumber"
        name="cheque_number"
        label="Cheque no"
        placeholder="Cheque number"
        :columns="colQuarter"
        autocomplete="off"
        rules="required"
    />

    <TextElement
        v-if="selectedMethod === 'bank_transfer'"
        id="BankAccountNumber"
        field-name="BankAccountNumber"
        name="bank_account_number"
        label="Bank account no"
        placeholder="Bank account number"
        :columns="colQuarter"
        autocomplete="off"
        rules="required"
    />

    <TextareaElement
        id="PaymentNote"
        field-name="PaymentNote"
        name="note"
        label="Payment notes"
        placeholder="Payment notes"
        :columns="colFull"
        :floating="false"
        rows="3"
    />
</template>
