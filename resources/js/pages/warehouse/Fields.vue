<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { Building2, MapPin, Phone, Warehouse as WarehouseIcon } from '@lucide/vue';
    import { computed, onMounted, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

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
    const cardClasses = {
        ElementLayout: {
            container: 'product-form-card',
        },
        GroupElement: {
            wrapper: 'product-form-card__body',
        },
    };

    const isEdit = computed(() => params.type === 'edit');

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const canManageBranch = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showBranchField = computed(() => canManageBranch.value && branchesdata.value.length > 1);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const showHiddenBranchField = computed(() => ! showBranchField.value);
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const branchDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);

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

    const usersdata = ref<Array<{ id: number | string; text?: string }>>([]);
    const lastFetchedCompanyId = ref('');
    const lastFetchedCountryId = ref('');
    const lastFetchedStateId = ref('');

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedCountryId = computed(() => params.formData?.country_id ?? '');
    const selectedStateId = computed(() => params.formData?.state_id ?? '');

    function persist(patch: Record<string, unknown>) {
        if (params.formData) {
            Object.assign(params.formData, patch);
        }

        params.formRef?.update?.(patch);
    }

    function applyScopedDefaults() {
        if (isSuperadmin.value) {
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
        if (! canManageBranch.value) {
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

        if (branchesdata.value.length === 1) {
            persist({ branch_id: branchesdata.value[0].id });
        }
    }

    async function loadUsers(companyId: string | number | null | undefined) {
        if (! companyId) {
            usersdata.value = [];

            return;
        }

        try {
            const response = await window.axios.get('/api/fetchusers', {
                params: { company_id: companyId },
            });
            usersdata.value = response.data ?? [];
        } catch {
            usersdata.value = [];
        }
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        persist({ branch_id: '' });
        lastFetchedCompanyId.value = '';
        await loadBranchOptions(companyId);
        await loadUsers(companyId);
    }

    async function handleCountryChange(countryId: string | number | null | undefined) {
        const normalizedCountryId = normalizeId(countryId);

        if (normalizedCountryId === lastFetchedCountryId.value) {
            return;
        }

        lastFetchedCountryId.value = normalizedCountryId;
        lastFetchedStateId.value = '';

        if (params.formData?.state_id || params.formData?.city_id) {
            persist({ state_id: '', city_id: '' });
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
            persist({ city_id: '' });
        }

        if (normalizedStateId && normalizedCountryId) {
            await fetchCity(normalizedCountryId, normalizedStateId);

            return;
        }

        citiesdata.value = [];
    }

    onMounted(async () => {
        applyScopedDefaults();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        await fetchCountry();

        const companyId = isCompanyadmin.value
            ? authUser.value?.company_id
            : selectedCompanyId.value;

        if (companyId) {
            await loadBranchOptions(companyId);
            await loadUsers(companyId);
        }

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

    <GroupElement name="group_warehouse" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_warehouse" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <WarehouseIcon class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Warehouse information</h2>
                    <p class="product-form-section-copy">Core details that identify this warehouse</p>
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
            info="Assign this warehouse to a company."
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
            rules="required"
            info="The branch this warehouse belongs to."
        />

        <TextElement
            id="Name"
            field-name="Name"
            name="name"
            label="Warehouse Name"
            placeholder="Enter warehouse name"
            :columns="colThird"
            autocomplete="off"
            rules="required|min:3|max:200"
        />

        <SelectElement
            name="user_id"
            :native="false"
            :items="usersdata"
            id="UserId"
            field-name="UserId"
            placeholder="Select in-charge user"
            label="Warehouse Manager"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="true"
            info="Optional. The user responsible for this warehouse."
        />
    </GroupElement>

    <GroupElement name="group_contact" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_contact" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Phone class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Contact information</h2>
                    <p class="product-form-section-copy">How staff can reach this warehouse</p>
                </div>
            </div>
        </StaticElement>

        <PhoneElement
            id="Phone"
            field-name="Phone"
            name="phone"
            label="Phone No"
            placeholder="Enter phone number"
            :columns="colThird"
            :allow-incomplete="true"
            :unmask="true"
        />

        <TextElement
            id="Fax"
            field-name="Fax"
            name="fax"
            label="Fax"
            placeholder="Enter fax number"
            :columns="colThird"
            autocomplete="off"
        />

        <TextElement
            id="Zipcode"
            field-name="Zipcode"
            name="zipcode"
            label="Zip / Postal Code"
            placeholder="Enter zip code"
            :columns="colThird"
            autocomplete="off"
        />
    </GroupElement>

    <GroupElement name="group_location" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_location" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <MapPin class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Location</h2>
                    <p class="product-form-section-copy">Country, region, and street address</p>
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
    </GroupElement>

    <GroupElement name="group_settings" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_settings" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Building2 class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Status</h2>
                    <p class="product-form-section-copy">Control warehouse availability</p>
                </div>
            </div>
        </StaticElement>

        <ToggleElement
            :labels="{ 1: 'Active', 0: 'Inactive' }"
            :columns="colThird"
            id="IsActive"
            field-name="IsActive"
            name="is_active"
            label="Warehouse Status"
            :true-value="true"
            :false-value="false"
            :default="true"
            info="Inactive warehouses are hidden from most operations."
        />
    </GroupElement>
</template>
