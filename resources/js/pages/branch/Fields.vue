<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { computed, onMounted, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';
    import { Building, Envelope, GitBranch, SliderAlt } from '@boxicons/vue';

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

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
    } | null);

    const isSuperadmin = computed(() =>
        String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin',
    );

    const isEdit = computed(() => params.type === 'edit');

    const codeRules = computed(() =>
        isEdit.value ? '' : 'required|regex:/^BR-\\d{5}$/i',
    );

    const {
        fetchCompany,
        fetchCountry,
        fetchState,
        fetchCity,
        companiesdata,
        countriesdata,
        statesdata,
        citiesdata,
    } = useCommons();

    const selectedCountryId = computed(() => params.formData?.country_id ?? '');
    const selectedStateId = computed(() => params.formData?.state_id ?? '');

    const normalizeLocationId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const lastFetchedCountryId = ref('');
    const lastFetchedStateId = ref('');

    async function handleCountryChange(countryId: string | number | null | undefined) {
        const normalizedCountryId = normalizeLocationId(countryId);

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
        const normalizedStateId = normalizeLocationId(stateId);
        const normalizedCountryId = normalizeLocationId(selectedCountryId.value);

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
        if (isSuperadmin.value) {
            await fetchCompany();
        }

        await fetchCountry();

        if (selectedCountryId.value) {
            lastFetchedCountryId.value = normalizeLocationId(selectedCountryId.value);
            await fetchState(selectedCountryId.value);
        }

        if (selectedStateId.value && selectedCountryId.value) {
            lastFetchedStateId.value = normalizeLocationId(selectedStateId.value);
            await fetchCity(selectedCountryId.value, selectedStateId.value);
        }
    });

    watch(
        () => normalizeLocationId(params.formData?.country_id),
        async (countryId, previousCountryId) => {
            if (countryId === previousCountryId) {
                return;
            }

            await handleCountryChange(countryId || undefined);
        },
    );

    watch(
        () => normalizeLocationId(params.formData?.state_id),
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
    <TextElement v-if="isEdit" name="id" hidden="true" />
    <TextElement v-if="!isSuperadmin" name="company_id" hidden="true" />

    <StaticElement name="section_branch" :columns="colFull">
        <div class="company-section-header company-section-header-primary">
            <span class="company-section-icon company-section-icon-primary">
                <GitBranch size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Branch Information</h6>
                <p class="company-section-subtitle mb-0">Core details that identify this branch</p>
            </div>
        </div>
    </StaticElement>

    <SelectElement
        v-if="isSuperadmin"
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
        rules="required"
        info="Assign this branch to a company."
    />

    <TextElement
        id="Code"
        field-name="Code"
        name="code"
        label="Branch Code"
        placeholder="BR-00001"
        :columns="colThird"
        autocomplete="off"
        readonly
        :rules="codeRules"
        info="Unique branch identifier in BR-00001 format. Auto-generated and cannot be changed."
    />

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Branch Name"
        placeholder="Enter branch name"
        :columns="colThird"
        autocomplete="off"
        rules="required|min:3|max:200"
    />

    <TextElement
        id="Description"
        field-name="Description"
        name="description"
        label="Description"
        placeholder="Brief description (optional)"
        :columns="colThird"
        autocomplete="off"
    />

    <StaticElement name="section_contact" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <Envelope size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Contact Information</h6>
                <p class="company-section-subtitle mb-0">How customers and staff can reach this branch</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="Email"
        field-name="Email"
        name="email"
        label="Email Address"
        placeholder="branch@example.com"
        input-type="email"
        :columns="colThird"
        autocomplete="off"
        rules="required|email"
    />

    <PhoneElement
        id="Phone"
        field-name="Phone"
        name="phone"
        label="Phone No"
        placeholder="Enter phone number"
        :columns="colThird"
        :allow-incomplete="true"
        :unmask="true"
        rules="numeric"
    />

    <PhoneElement
        id="Mobile"
        field-name="Mobile"
        name="mobile"
        label="Mobile No"
        placeholder="Enter mobile number"
        :columns="colThird"
        :allow-incomplete="true"
        :unmask="true"
        rules="numeric"
    />

    <StaticElement name="section_location" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <Building size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Location</h6>
                <p class="company-section-subtitle mb-0">Country, region, and street address</p>
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
        placeholder="Enter full address"
        input-type="textarea"
        :rows="3"
        :columns="colFull"
        autocomplete="off"
    />

    <StaticElement name="section_settings" :columns="colFull">
        <div class="company-section-header company-section-header-primary company-section-header-spaced">
            <span class="company-section-icon company-section-icon-primary">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Status &amp; Settings</h6>
                <p class="company-section-subtitle mb-0">Control branch availability and defaults</p>
            </div>
        </div>
    </StaticElement>

    <ToggleElement
        :labels="{ 1: 'Active', 0: 'Inactive' }"
        :columns="colThird"
        id="IsActive"
        field-name="IsActive"
        name="is_active"
        label="Branch Status"
        :true-value="true"
        :false-value="false"
        :default="true"
        info="Inactive branches are hidden from most operations."
    />

    <ToggleElement
        :labels="{ 1: 'Yes', 0: 'No' }"
        :columns="colThird"
        id="IsDefault"
        field-name="IsDefault"
        name="is_default"
        label="Default Branch"
        :true-value="true"
        :false-value="false"
        :default="false"
        info="Mark as the primary branch for this company."
    />
</template>
