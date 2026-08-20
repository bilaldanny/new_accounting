<script setup lang="ts">
    import useCommons from '@/composables/common';
    import useCustomers from '@/composables/customer';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { computed, onMounted, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';
    import { Building, Envelope, RefreshCw, SliderAlt, Store, UserCircle } from '@boxicons/vue';

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
    const colQuarter = { container: 3, label: 12, wrapper: 12 };
    const colHalf = { container: 6, label: 12, wrapper: 12 };
    const colFull = { container: 12, label: 12, wrapper: 12 };
    const defaultDialCode = computed(() => String(page.props.dailCode ?? ''));

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
        currency_id?: number | string | null;
    } | null);

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const isEdit = computed(() => params.type === 'edit');

    const showCompanyField = computed(() => isSuperadmin.value);
    const showBranchField = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showHiddenCompanyField = computed(() => isCompanyadmin.value || (! isSuperadmin.value && ! isCompanyadmin.value));
    const showHiddenBranchField = computed(() => ! isSuperadmin.value && ! isCompanyadmin.value);

    const {
        fetchCompany,
        fetchBranch,
        fetchCustomerGroup,
        fetchCountry,
        fetchState,
        fetchCity,
        companiesdata,
        branchesdata,
        customergroupsdata,
        countriesdata,
        statesdata,
        citiesdata,
        Notify,
    } = useCommons();

    const { generateCustomerCode } = useCustomers();

    const codeGenerating = ref(false);

    const currenciesdata = ref<Array<{ id: number | string; text?: string; currency_name?: string }>>([]);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedCountryId = computed(() => params.formData?.country_id ?? '');
    const selectedStateId = computed(() => params.formData?.state_id ?? '');

    const lastFetchedCompanyId = ref('');
    const lastFetchedCountryId = ref('');
    const lastFetchedStateId = ref('');
    const lastFetchedBranchKey = ref('');

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const branchRules = computed(() => (showBranchField.value ? 'required' : ''));
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));

    const branchDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);

    const customerGroupDisabled = computed(() =>
        ! normalizeId(params.formData?.company_id) || ! normalizeId(params.formData?.branch_id),
    );

    const userTypeOptions = [
        { id: 'customer', text: 'Customer' },
        { id: 'both', text: 'Both (Supplier & Customer)' },
    ];

    const tradeTypeOptions = [
        { id: 'local', text: 'Local' },
        { id: 'export', text: 'Export' },
    ];

    const payTypeOptions = [
        { id: 'day', text: 'Days' },
        { id: 'month', text: 'Months' },
        { id: 'year', text: 'Years' },
    ];

    const payTypeValue = computed(() => String(params.formData?.pay_type ?? 'day'));

    function onPayTypeChange(event: Event) {
        const value = (event.target as HTMLSelectElement).value;
        params.formRef?.update?.({ pay_type: value });
    }

    async function generateContactCode() {
        const companyId = normalizeId(params.formData?.company_id);

        if (! companyId) {
            Notify('Select a company before generating a contact ID.', 'alert');

            return;
        }

        codeGenerating.value = true;

        try {
            const branchId = normalizeId(params.formData?.branch_id);
            const code = await generateCustomerCode(companyId, branchId || undefined);

            if (code) {
                params.formRef?.update?.({ code });
            }
        } finally {
            codeGenerating.value = false;
        }
    }

    const prefixOptions = [
        { id: 'Mr.', text: 'Mr.' },
        { id: 'Mrs.', text: 'Mrs.' },
        { id: 'Miss', text: 'Miss' },
        { id: 'Ms.', text: 'Ms.' },
        { id: 'Dr.', text: 'Dr.' },
        { id: 'Prof.', text: 'Prof.' },
        { id: 'Eng.', text: 'Eng.' },
        { id: 'Engr.', text: 'Engr.' },
        { id: 'M/s', text: 'M/s' },
        { id: 'Sir', text: 'Sir' },
        { id: 'Capt.', text: 'Capt.' },
        { id: 'Col.', text: 'Col.' },
        { id: 'Rev.', text: 'Rev.' },
        { id: 'Hon.', text: 'Hon.' },
    ];

    const phoneFields = ['mobile', 'alternate_no'] as const;

    function applyPhoneDefaults() {
        const updates: Record<string, string> = {};

        phoneFields.forEach((field) => {
            const value = params.formData?.[field];

            if (value === null || value === undefined || value === '') {
                updates[field] = defaultDialCode.value;
            }
        });

        if (Object.keys(updates).length > 0) {
            params.formRef?.update?.(updates);
        }
    }

    async function fetchCurrencies() {
        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchCurrencies);
            currenciesdata.value = response.data;
        } catch {
            currenciesdata.value = [];
        }
    }

    function applyScopedDefaults() {
        const updates: Record<string, string | number | boolean> = {};

        if (! isSuperadmin.value && authUser.value?.company_id) {
            updates.company_id = authUser.value.company_id;
        }

        if (! isSuperadmin.value && ! isCompanyadmin.value && authUser.value?.branch_id) {
            updates.branch_id = authUser.value.branch_id;
        }

        if (! params.formData?.currency_id && authUser.value?.currency_id) {
            updates.currency_id = authUser.value.currency_id;
        }

        if (Object.keys(updates).length > 0) {
            params.formRef?.update?.(updates);
        }

        applyPhoneDefaults();
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

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        const normalizedCompanyId = normalizeId(companyId);

        if (params.formData?.branch_id) {
            params.formRef?.update?.({ branch_id: '', customer_group_id: '' });
        }

        lastFetchedCompanyId.value = '';
        lastFetchedBranchKey.value = '';
        await loadBranchOptions(normalizedCompanyId || undefined);
    }

    async function loadCustomerGroupOptions(
        companyId: string | number | null | undefined,
        branchId: string | number | null | undefined,
    ) {
        const normalizedCompanyId = normalizeId(companyId);
        const normalizedBranchId = normalizeId(branchId);
        const key = `${normalizedCompanyId}:${normalizedBranchId}`;

        if (! normalizedCompanyId || ! normalizedBranchId) {
            customergroupsdata.value = [];

            return;
        }

        if (key === lastFetchedBranchKey.value) {
            return;
        }

        lastFetchedBranchKey.value = key;
        await fetchCustomerGroup(normalizedCompanyId, normalizedBranchId);
    }

    async function handleBranchChange(
        companyId: string | number | null | undefined,
        branchId: string | number | null | undefined,
    ) {
        if (params.formData?.customer_group_id) {
            params.formRef?.update?.({ customer_group_id: '' });
        }

        lastFetchedBranchKey.value = '';
        await loadCustomerGroupOptions(companyId, branchId);
    }

    async function handleCountryChange(countryId: string | number | null | undefined) {
        const normalizedCountryId = normalizeId(countryId);

        if (normalizedCountryId === lastFetchedCountryId.value) {
            return;
        }

        lastFetchedCountryId.value = normalizedCountryId;
        lastFetchedStateId.value = '';

        if (params.formData?.state_id || params.formData?.city_id) {
            params.formRef?.update?.({ state_id: '', city_id: '' });
        }

        if (normalizedCountryId) {
            await fetchState(normalizedCountryId);

            return;
        }

        statesdata.value = [];
        citiesdata.value = [];
    }

    async function handleStateChange(stateId: string | number | null | undefined) {
        const normalizedStateId = normalizeId(stateId);
        const normalizedCountryId = normalizeId(selectedCountryId.value);

        if (normalizedStateId === lastFetchedStateId.value) {
            return;
        }

        lastFetchedStateId.value = normalizedStateId;

        if (params.formData?.city_id) {
            params.formRef?.update?.({ city_id: '' });
        }

        if (normalizedStateId && normalizedCountryId) {
            await fetchCity(normalizedCountryId, normalizedStateId);

            return;
        }

        citiesdata.value = [];
    }

    onMounted(async () => {
        applyScopedDefaults();
        applyPhoneDefaults();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        const companyId = isCompanyadmin.value
            ? authUser.value?.company_id
            : selectedCompanyId.value;

        if (companyId) {
            await loadBranchOptions(companyId);
        }

        const branchId = isCompanyadmin.value
            ? (params.formData?.branch_id || authUser.value?.branch_id)
            : params.formData?.branch_id;

        if (companyId && branchId) {
            await loadCustomerGroupOptions(companyId, branchId);
        }

        await fetchCountry();
        await fetchCurrencies();

        if (selectedCountryId.value) {
            lastFetchedCountryId.value = normalizeId(selectedCountryId.value);
            await fetchState(selectedCountryId.value);
        }

        if (selectedStateId.value && selectedCountryId.value) {
            lastFetchedStateId.value = normalizeId(selectedStateId.value);
            await fetchCity(selectedCountryId.value, selectedStateId.value);
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
        () => [normalizeId(params.formData?.company_id), normalizeId(params.formData?.branch_id)],
        async ([companyId, branchId], [prevCompanyId, prevBranchId]) => {
            if (companyId === prevCompanyId && branchId === prevBranchId) {
                return;
            }

            await handleBranchChange(companyId || undefined, branchId || undefined);
        },
    );

    watch(
        () => normalizeId(params.formData?.country_id),
        async (countryId, previousCountryId) => {
            if (countryId === previousCountryId) {
                return;
            }

            await handleCountryChange(countryId || undefined);
        },
    );

    watch(
        () => normalizeId(params.formData?.state_id),
        async (stateId, previousStateId) => {
            if (stateId === previousStateId) {
                return;
            }

            await handleStateChange(stateId || undefined);
        },
    );

    watch(
        () => [
            normalizeId(params.formData?.company_id),
            normalizeId(params.formData?.branch_id),
        ],
        async ([companyId, branchId], [previousCompanyId, previousBranchId]) => {
            if (isEdit.value || codeGenerating.value) {
                return;
            }

            if (companyId === previousCompanyId && branchId === previousBranchId) {
                return;
            }

            if (! companyId) {
                return;
            }

            await generateContactCode();
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />

    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />
    <TextElement v-if="showHiddenBranchField" name="branch_id" hidden="true" />
    <TextElement name="link_account" default="0" hidden="true" />

    <StaticElement name="section_business" :columns="colFull">
        <div class="company-section-header company-section-header-primary">
            <span class="company-section-icon company-section-icon-primary">
                <Store size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Business Details</h6>
                <p class="company-section-subtitle mb-0">Organization scope, customer type, and billing currency</p>
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
        :rules="branchRules"
    />

    <SelectElement
        name="user_type"
        :native="false"
        :items="userTypeOptions"
        id="UserType"
        field-name="UserType"
        placeholder="Select contact type"
        label="Contact type"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        :can-clear="false"
        :disabled="isEdit"
        :default="'customer'"
        rules="required"
    />

    <TextElement
        id="Code"
        field-name="Code"
        name="code"
        label="Contact ID"
        placeholder="Generating…"
        :columns="colThird"
        :readonly="true"
        autocomplete="off"
        rules="max:255"
        :info="isEdit ? 'Auto-generated customer code.' : 'Auto-generated on open. Use reload to regenerate.'"
        :add-classes="{
            container: 'supplier-contact-id-field',
            inputContainer: 'supplier-contact-id-shell',
            input: 'supplier-contact-id-input',
            ElementAddon: {
                container: 'supplier-contact-id-addon',
            },
        }"
    >
        <template v-if="!isEdit" #addon-after>
            <button
                type="button"
                class="code-generate-btn"
                :class="{ 'code-generate-btn-loading': codeGenerating }"
                title="Regenerate contact ID"
                aria-label="Regenerate contact ID"
                :disabled="codeGenerating"
                @click="generateContactCode"
            >
                <RefreshCw size="xs" />
            </button>
        </template>
    </TextElement>

    <TextElement
        id="BusinessName"
        field-name="BusinessName"
        name="business_name"
        label="Business Name"
        placeholder="Registered business or trading name"
        :columns="colThird"
        autocomplete="organization"
        rules="required|min:2|max:255"
        info="The name shown on sales invoices and reports."
    />

    <SelectElement
        name="type"
        :native="false"
        :items="tradeTypeOptions"
        id="TradeType"
        field-name="TradeType"
        placeholder="Select trade type"
        label="Trade Type"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        :can-clear="false"
        :default="'local'"
        info="Local or export customer for tax and compliance."
    />

    <SelectElement
        name="currency_id"
        :native="false"
        :items="currenciesdata"
        id="CurrencyId"
        field-name="CurrencyId"
        placeholder="Select currency"
        label="Currency"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        info="Preferred currency for transactions with this customer."
    />

    <SelectElement
        name="customer_group_id"
        :native="false"
        :items="customergroupsdata"
        id="CustomerGroupId"
        field-name="CustomerGroupId"
        placeholder="Select customer group"
        label="Customer Group"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="customerGroupDisabled"
        info="Optional pricing group applied to this customer."
    />

    <StaticElement name="section_contact_person" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <UserCircle size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Contact Person</h6>
                <p class="company-section-subtitle mb-0">Primary individual associated with this customer</p>
            </div>
        </div>
    </StaticElement>

    <SelectElement
        name="prefix"
        :native="false"
        :items="prefixOptions"
        id="Prefix"
        field-name="Prefix"
        placeholder="Select prefix"
        label="Prefix"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
    />

    <TextElement
        id="FirstName"
        field-name="FirstName"
        name="first_name"
        label="First Name"
        placeholder="First name"
        :columns="colThird"
        autocomplete="given-name"
        rules="required|min:2|max:255"
    />

    <TextElement
        id="MiddleName"
        field-name="MiddleName"
        name="middle_name"
        label="Middle Name"
        placeholder="Middle name (optional)"
        :columns="colThird"
        autocomplete="additional-name"
    />

    <TextElement
        id="LastName"
        field-name="LastName"
        name="last_name"
        label="Last Name"
        placeholder="Last name (optional)"
        :columns="colThird"
        autocomplete="family-name"
    />

    <StaticElement name="section_communication" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <Envelope size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Communication</h6>
                <p class="company-section-subtitle mb-0">Phone numbers and email for day-to-day contact</p>
            </div>
        </div>
    </StaticElement>

    <PhoneElement
        id="Mobile"
        field-name="Mobile"
        name="mobile"
        label="Mobile"
        placeholder="Mobile number"
        :columns="colQuarter"
        :default="defaultDialCode"
        :allow-incomplete="true"
        :unmask="true"
        rules="required"
        info="Primary contact number for this customer."
    />

    <PhoneElement
        id="AlternateNo"
        field-name="AlternateNo"
        name="alternate_no"
        label="Alternate contact number"
        placeholder="Alternate number"
        :columns="colQuarter"
        :default="defaultDialCode"
        :allow-incomplete="true"
        :unmask="true"
    />

    <TextElement
        id="Landline"
        field-name="Landline"
        name="landline"
        label="Landline"
        placeholder="Landline or office number"
        :columns="colQuarter"
        autocomplete="tel"
    />

    <TextElement
        id="Email"
        field-name="Email"
        name="email"
        label="Email"
        placeholder="Email address (optional)"
        :columns="colQuarter"
        input-type="email"
        autocomplete="email"
        rules="email"
    />

    <DateElement
        id="DateOfBirth"
        field-name="DateOfBirth"
        name="date_of_birth"
        label="Date of birth"
        placeholder="Select date of birth"
        :columns="colQuarter"
        :floating="false"
    />

    <StaticElement name="section_location" :columns="colFull">
        <div class="company-section-header company-section-header-primary company-section-header-spaced">
            <span class="company-section-icon company-section-icon-primary">
                <Building size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Address & Tax</h6>
                <p class="company-section-subtitle mb-0">Structured address, location, and tax registration</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="AddressLine1"
        field-name="AddressLine1"
        name="address"
        label="Address line 1"
        placeholder="Address line 1"
        :columns="colHalf"
        autocomplete="address-line1"
        rules="required|max:1000"
    />

    <TextElement
        id="AddressLine2"
        field-name="AddressLine2"
        name="address_line_2"
        label="Address line 2"
        placeholder="Address line 2"
        :columns="colHalf"
        autocomplete="address-line2"
        rules="max:1000"
    />

    <SelectElement
        name="country_id"
        :native="false"
        :items="countriesdata"
        id="CountryId"
        field-name="CountryId"
        placeholder="Select country"
        label="Country"
        :columns="colQuarter"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
    />

    <SelectElement
        name="state_id"
        :native="false"
        :items="statesdata"
        id="StateId"
        field-name="StateId"
        placeholder="Select state"
        label="State"
        :columns="colQuarter"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="!selectedCountryId"
    />

    <SelectElement
        name="city_id"
        :native="false"
        :items="citiesdata"
        id="CityId"
        field-name="CityId"
        placeholder="Select city"
        label="City"
        :columns="colQuarter"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="!selectedStateId"
    />

    <TextElement
        id="Zipcode"
        field-name="Zipcode"
        name="zipcode"
        label="Zip Code"
        placeholder="Zip/Postal Code"
        :columns="colQuarter"
        autocomplete="postal-code"
        rules="max:50"
    />

    <TextElement
        id="Landmark"
        field-name="Landmark"
        name="landmark"
        label="Landmark"
        placeholder="Landmark"
        :columns="colThird"
    />

    <TextElement
        id="StreetName"
        field-name="StreetName"
        name="street_name"
        label="Street name"
        placeholder="Street name"
        :columns="colThird"
        autocomplete="off"
        rules="max:255"
    />

    <TextElement
        id="BuildingNumber"
        field-name="BuildingNumber"
        name="building_number"
        label="Building number"
        placeholder="Building number"
        :columns="colThird"
        autocomplete="off"
        rules="max:50"
    />


    <TextElement
        id="SecondaryNumber"
        field-name="SecondaryNumber"
        name="secondary_number"
        label="Additional number"
        placeholder="Secondary / additional number"
        :columns="colThird"
        autocomplete="off"
        rules="max:50"
    />


    <StaticElement name="section_tax_payment" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Tax & Payment</h6>
                <p class="company-section-subtitle mb-0">Tax registration, opening balance, and default pay terms</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="NtnNumber"
        field-name="NtnNumber"
        name="ntn_number"
        label="Tax number"
        placeholder="Tax registration number"
        :columns="colThird"
        autocomplete="off"
        rules="required|max:255"
        info="Tax registration number used on invoices and compliance."
    />

    <TextElement
        v-if="!isEdit"
        id="OpeningBalance"
        field-name="OpeningBalance"
        name="opening_balance"
        label="Opening Balance"
        placeholder="0"
        :columns="colThird"
        input-type="number"
        :default="0"
        rules="numeric"
        info="Opening ledger balance when the customer account is linked to COA."
    />

    <TextElement name="pay_type" hidden="true" :default="'day'" />

    <TextElement
        id="PayTerm"
        field-name="PayTerm"
        name="pay_term"
        label="Pay term"
        placeholder="e.g. 15"
        :columns="colThird"
        input-type="number"
        rules="numeric"
        info="Number of days, months, or years before payment is due."
        :add-classes="{
            container: 'supplier-pay-term-field',
            inputContainer: 'supplier-pay-term-shell',
            input: 'supplier-pay-term-input',
            ElementAddon: {
                container: 'supplier-pay-term-addon',
            },
        }"
    >
        <template #addon-after>
            <select
                class="form-select supplier-pay-term-select"
                :value="payTypeValue"
                aria-label="Pay term period"
                @change="onPayTypeChange"
            >
                <option
                    v-for="option in payTypeOptions"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.text }}
                </option>
            </select>
        </template>
    </TextElement>

    <StaticElement name="section_payment" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Credit Settings</h6>
                <p class="company-section-subtitle mb-0">Credit limit defaults for sales invoices</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="CreditLimit"
        field-name="CreditLimit"
        name="credit_limit"
        label="Credit Limit"
        placeholder="0"
        :columns="colThird"
        input-type="number"
        :default="0"
        rules="numeric"
        info="Maximum outstanding balance allowed for this customer."
    />

    <StaticElement name="section_status" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Status</h6>
                <p class="company-section-subtitle mb-0">Control whether this supplier is available for transactions</p>
            </div>
        </div>
    </StaticElement>

    <ToggleElement
        :labels="{ 1: 'Active', 0: 'Inactive' }"
        :columns="colThird"
        id="Active"
        field-name="Active"
        name="active"
        label="Customer Status"
        :true-value="true"
        :false-value="false"
        :default="true"
        info="Inactive customers are hidden from sales and payment selection."
    />
</template>
