<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { computed, onMounted, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';
    import { Building, Envelope, SliderAlt, Store, UserCircle } from '@boxicons/vue';

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
        fetchCountry,
        fetchState,
        fetchCity,
        companiesdata,
        branchesdata,
        countriesdata,
        statesdata,
        citiesdata,
    } = useCommons();

    const currenciesdata = ref<Array<{ id: number | string; text?: string; currency_name?: string }>>([]);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedCountryId = computed(() => params.formData?.country_id ?? '');
    const selectedStateId = computed(() => params.formData?.state_id ?? '');

    const lastFetchedCompanyId = ref('');
    const lastFetchedCountryId = ref('');
    const lastFetchedStateId = ref('');

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const branchRules = computed(() => (showBranchField.value ? 'required' : ''));
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));

    const branchDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);

    const userTypeOptions = [
        { id: 'supplier', text: 'Supplier' },
        { id: 'both', text: 'Both (Supplier & Customer)' },
    ];

    const tradeTypeOptions = [
        { id: 'local', text: 'Local' },
        { id: 'export', text: 'Export' },
    ];

    const payTypeOptions = [
        { id: 'day', text: 'Day(s)' },
        { id: 'month', text: 'Month(s)' },
        { id: 'year', text: 'Year(s)' },
    ];

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
            params.formRef?.update?.({ branch_id: '' });
        }

        lastFetchedCompanyId.value = '';
        await loadBranchOptions(normalizedCompanyId || undefined);
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
                <p class="company-section-subtitle mb-0">Organization scope, supplier type, and billing currency</p>
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

    <TextElement
        v-if="isEdit"
        id="Code"
        field-name="Code"
        name="code"
        label="Supplier Code"
        :columns="colThird"
        readonly
        info="Auto-generated supplier code."
    />

    <TextElement
        id="BusinessName"
        field-name="BusinessName"
        name="business_name"
        label="Business Name"
        placeholder="Registered business or trading name"
        :columns="colThird"
        autocomplete="organization"
        rules="required|min:2|max:255"
        info="The name shown on purchase orders and reports."
    />

    <SelectElement
        name="user_type"
        :native="false"
        :items="userTypeOptions"
        id="UserType"
        field-name="UserType"
        placeholder="Select contact type"
        label="Contact Type"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        :can-clear="false"
        :disabled="isEdit"
        :default="'supplier'"
        info="Whether this contact is supplier-only or both supplier and customer."
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
        info="Local or export supplier for tax and compliance."
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
        info="Preferred currency for transactions with this supplier."
    />

    <StaticElement name="section_contact_person" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <UserCircle size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Contact Person</h6>
                <p class="company-section-subtitle mb-0">Primary individual associated with this supplier</p>
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
        :columns="colThird"
        :default="defaultDialCode"
        :allow-incomplete="true"
        :unmask="true"
        rules="required"
        info="Primary contact number for this supplier."
    />

    <PhoneElement
        id="AlternateNo"
        field-name="AlternateNo"
        name="alternate_no"
        label="Alternate Number"
        placeholder="Alternate number"
        :columns="colThird"
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
        :columns="colThird"
        autocomplete="tel"
    />

    <TextElement
        id="Email"
        field-name="Email"
        name="email"
        label="Email"
        placeholder="Email address (optional)"
        :columns="colThird"
        input-type="email"
        autocomplete="email"
        rules="email"
    />

    <StaticElement name="section_location" :columns="colFull">
        <div class="company-section-header company-section-header-primary company-section-header-spaced">
            <span class="company-section-icon company-section-icon-primary">
                <Building size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Address & Tax</h6>
                <p class="company-section-subtitle mb-0">Location details and tax registration information</p>
            </div>
        </div>
    </StaticElement>

    <SelectElement
        name="country_id"
        :native="false"
        :items="countriesdata"
        id="CountryId"
        field-name="CountryId"
        placeholder="Select country"
        label="Country"
        :columns="colThird"
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
        label="State / Province"
        :columns="colThird"
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
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="!selectedStateId"
    />

    <TextElement
        id="Address"
        field-name="Address"
        name="address"
        label="Street Address"
        placeholder="Enter complete street address"
        input-type="textarea"
        :rows="3"
        :columns="colFull"
        autocomplete="street-address"
        rules="required|max:1000"
    />

    <TextElement
        id="Landmark"
        field-name="Landmark"
        name="landmark"
        label="Landmark"
        placeholder="Nearby landmark (optional)"
        :columns="colThird"
    />

    <TextElement
        id="NtnNumber"
        field-name="NtnNumber"
        name="ntn_number"
        label="NTN / Tax Number"
        placeholder="National Tax Number"
        :columns="colThird"
        autocomplete="off"
        rules="required|max:255"
        info="Tax registration number used on invoices and compliance."
    />

    <StaticElement name="section_payment" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Payment Terms</h6>
                <p class="company-section-subtitle mb-0">Credit and payment schedule defaults for purchase orders</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="PayTerm"
        field-name="PayTerm"
        name="pay_term"
        label="Pay Term"
        placeholder="e.g. 30"
        :columns="colThird"
        input-type="number"
        rules="numeric"
        info="Number of days, months, or years before payment is due."
    />

    <SelectElement
        name="pay_type"
        :native="false"
        :items="payTypeOptions"
        id="PayType"
        field-name="PayType"
        placeholder="Select period"
        label="Pay Term Period"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        :can-clear="false"
        :default="'day'"
    />

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
        info="Maximum outstanding balance allowed for this supplier."
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
        label="Supplier Status"
        :true-value="true"
        :false-value="false"
        :default="true"
        info="Inactive suppliers are hidden from purchase and payment selection."
    />
</template>
