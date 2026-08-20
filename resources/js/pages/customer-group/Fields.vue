<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { computed, onMounted, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';

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
    const showHiddenCompanyField = computed(() => isCompanyadmin.value || (! isSuperadmin.value && ! isCompanyadmin.value));
    const showHiddenBranchField = computed(() => ! isSuperadmin.value && ! isCompanyadmin.value);

    const { fetchCompany, fetchBranch, companiesdata, branchesdata } = useCommons();

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');

    const lastFetchedCompanyId = ref('');

    const nameRules = computed(() => {
        if (params.recordId) {
            return `required|customer_group_name_unique:${params.recordId}`;
        }

        return 'required|customer_group_name_unique';
    });

    const branchRules = computed(() => (isCompanyadmin.value ? 'required' : ''));

    const branchPlaceholder = computed(() =>
        isCompanyadmin.value ? 'Select branch' : 'Select branch (optional)',
    );

    const branchInfo = computed(() =>
        isCompanyadmin.value
            ? 'Required for company administrators.'
            : 'Optional. Select a company first when creating a branch-specific customer group.',
    );

    const branchDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);

    const priceCalculationTypeOptions = [
        { id: 'percentage', text: 'Percentage' },
    ];

    const calculationPercentageInfo =
        'Selling price = Selling price set for the product + Calculation percentage. You can specify percentage as positive to increase and negative to decrease selling price.';

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
            params.formRef?.update?.(updates);
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
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <TextElement
        v-if="showHiddenCompanyField"
        name="company_id"
        hidden="true"
    />

    <TextElement
        v-if="showHiddenBranchField"
        name="branch_id"
        hidden="true"
    />

    <SelectElement
        v-if="showCompanyField"
        name="company_id"
        :native="false"
        :items="companiesdata"
        id="CompanyId"
        field-name="CompanyId"
        placeholder="Select company (optional)"
        label="Company"
        :columns="{ container: 12, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        info="Optional. Leave empty for a global customer group."
    />

    <SelectElement
        v-if="showBranchField"
        name="branch_id"
        :native="false"
        :items="branchesdata"
        id="BranchId"
        field-name="BranchId"
        :placeholder="branchPlaceholder"
        label="Branch"
        :columns="{ container: 12, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="!isCompanyadmin"
        :disabled="branchDisabled"
        :rules="branchRules"
        :info="branchInfo"
    />

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Customer Group Name"
        placeholder="Customer Group Name"
        :columns="{ container: 12, label: 12, wrapper: 12 }"
        autocomplete="off"
        :rules="nameRules"
    />

    <SelectElement
        name="price_calculation_type"
        :native="false"
        :items="priceCalculationTypeOptions"
        id="PriceCalculationType"
        field-name="PriceCalculationType"
        placeholder="Select price calculation type"
        label="Price calculation type"
        :columns="{ container: 12, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        :can-clear="false"
        :default="'percentage'"
        rules="required"
    />

    <TextElement
        id="CalculationPercentage"
        field-name="CalculationPercentage"
        name="calculation_percentage"
        label="Calculation Percentage (%)"
        placeholder="Calculation Percentage"
        :columns="{ container: 12, label: 12, wrapper: 12 }"
        autocomplete="off"
        input-type="number"
        rules="required|numeric"
        :info="calculationPercentageInfo"
    />

    <StaticElement tag="br" name="element" />

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="{ container: 12, label: 12, wrapper: 12 }"
        id="Active"
        field-name="Active"
        name="active"
        label="Active"
        :true-value="true"
        :false-value="false"
        :default="true"
    />
</template>
