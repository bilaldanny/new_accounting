<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { BadgeCheck, Landmark, MapPin, Phone, UserRound } from '@lucide/vue';
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

    const consumerTypes = [
        { id: 'individual', text: 'Individual' },
        { id: 'business', text: 'Business' },
    ];

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
        companiesdata,
        branchesdata,
        countriesdata,
    } = useCommons();

    const accountsdata = ref<Array<{ id: number | string; text?: string; code?: string }>>([]);
    const lastFetchedCompanyId = ref('');
    const lastFetchedAccountScope = ref('');

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedBranchId = computed(() => params.formData?.branch_id ?? '');

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

    async function loadAccounts(companyId: string | number | null | undefined, branchId: string | number | null | undefined) {
        const scope = `${normalizeId(companyId)}:${normalizeId(branchId)}`;

        if (! companyId) {
            accountsdata.value = [];
            lastFetchedAccountScope.value = '';

            return;
        }

        if (scope === lastFetchedAccountScope.value) {
            return;
        }

        lastFetchedAccountScope.value = scope;

        try {
            const response = await window.axios.get('/api/fetchallaccounts', {
                params: { company_id: companyId, branch_id: branchId },
            });
            accountsdata.value = response.data ?? [];
        } catch {
            accountsdata.value = [];
        }
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        persist({ branch_id: '' });
        lastFetchedCompanyId.value = '';
        await loadBranchOptions(companyId);
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
        }

        await loadAccounts(selectedCompanyId.value, selectedBranchId.value);
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
        async () => {
            await loadAccounts(selectedCompanyId.value, selectedBranchId.value);
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />
    <TextElement v-if="showHiddenBranchField" name="branch_id" hidden="true" />

    <GroupElement name="group_consumer" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_consumer" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <UserRound class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Consumer information</h2>
                    <p class="product-form-section-copy">Core details that identify this consumer</p>
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
            info="Assign this consumer to a company."
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
            info="The branch this consumer belongs to."
        />

        <TextElement
            id="Name"
            field-name="Name"
            name="name"
            label="Consumer Name"
            placeholder="Enter consumer name"
            :columns="colThird"
            autocomplete="off"
            rules="required|min:3|max:200"
        />

        <SelectElement
            name="consumer_type"
            :native="false"
            :items="consumerTypes"
            id="ConsumerType"
            field-name="ConsumerType"
            placeholder="Select type"
            label="Consumer Type"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="false"
            :floating="false"
            :can-clear="false"
            default="individual"
        />

        <SelectElement
            name="account_id"
            :native="false"
            :items="accountsdata"
            id="AccountId"
            field-name="AccountId"
            placeholder="Select linked account"
            label="Linked Chart of Account"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="true"
            info="Optional. Link this consumer to a ledger account."
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
                    <p class="product-form-section-copy">Who to reach and how</p>
                </div>
            </div>
        </StaticElement>

        <TextElement
            id="ContactPerson"
            field-name="ContactPerson"
            name="contact_person"
            label="Contact Person"
            placeholder="Enter contact person"
            :columns="colThird"
            autocomplete="off"
        />

        <PhoneElement
            id="PhoneRes"
            field-name="PhoneRes"
            name="phone_res"
            label="Residence Phone"
            placeholder="Enter residence phone"
            :columns="colThird"
            :allow-incomplete="true"
            :unmask="true"
        />

        <PhoneElement
            id="PhoneOff"
            field-name="PhoneOff"
            name="phone_off"
            label="Office Phone"
            placeholder="Enter office phone"
            :columns="colThird"
            :allow-incomplete="true"
            :unmask="true"
        />

        <TextElement
            id="FaxNo"
            field-name="FaxNo"
            name="fax_no"
            label="Fax No"
            placeholder="Enter fax number"
            :columns="colThird"
            autocomplete="off"
        />

        <TextElement
            id="Email"
            field-name="Email"
            name="email"
            label="Email Address"
            placeholder="consumer@example.com"
            input-type="email"
            :columns="colThird"
            autocomplete="off"
            rules="email"
        />
    </GroupElement>

    <GroupElement name="group_tax" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_tax" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <BadgeCheck class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Tax identifiers</h2>
                    <p class="product-form-section-copy">Registration numbers for invoicing and compliance</p>
                </div>
            </div>
        </StaticElement>

        <TextElement
            id="NtnNo"
            field-name="NtnNo"
            name="ntn_no"
            label="NTN No"
            placeholder="National tax number"
            :columns="colThird"
            autocomplete="off"
        />

        <TextElement
            id="CnicNo"
            field-name="CnicNo"
            name="cnic_no"
            label="CNIC No"
            placeholder="National ID number"
            :columns="colThird"
            autocomplete="off"
        />

        <TextElement
            id="SalesTaxNo"
            field-name="SalesTaxNo"
            name="sales_tax_no"
            label="Sales Tax No"
            placeholder="Sales tax registration number"
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
                    <p class="product-form-section-copy">Country, city, and addresses</p>
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

        <TextElement
            id="City"
            field-name="City"
            name="city"
            label="City"
            placeholder="Enter city"
            :columns="colThird"
            autocomplete="off"
        />

        <TextElement
            id="Address"
            field-name="Address"
            name="address"
            label="Address"
            placeholder="Enter address"
            input-type="textarea"
            :rows="3"
            :columns="colHalf"
            autocomplete="off"
        />

        <TextElement
            id="StoreAddress"
            field-name="StoreAddress"
            name="store_address"
            label="Store Address"
            placeholder="Enter store address (if different)"
            input-type="textarea"
            :rows="3"
            :columns="colHalf"
            autocomplete="off"
        />
    </GroupElement>

    <GroupElement name="group_settings" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_settings" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Landmark class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Status</h2>
                    <p class="product-form-section-copy">Control consumer availability</p>
                </div>
            </div>
        </StaticElement>

        <ToggleElement
            :labels="{ 1: 'Active', 0: 'Inactive' }"
            :columns="colThird"
            id="IsActive"
            field-name="IsActive"
            name="is_active"
            label="Consumer Status"
            :true-value="true"
            :false-value="false"
            :default="true"
            info="Inactive consumers are hidden from most operations."
        />
    </GroupElement>
</template>
