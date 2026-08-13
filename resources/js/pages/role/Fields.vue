<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { computed, onMounted, watch } from 'vue';
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

    const { fetchCompany, fetchBranch, companiesdata, branchesdata } = useCommons();

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');

    const nameRules = computed(() => {
        if (params.recordId) {
            return `required|role_name_unique:${params.recordId}`;
        }

        return 'required|role_name_unique';
    });

    const branchDisabled = computed(() => showCompanyField.value && !selectedCompanyId.value);

    async function loadBranchOptions(companyId: string | number | null | undefined) {
        if (!showBranchField.value) {
            return;
        }

        await fetchBranch(companyId);
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        await loadBranchOptions(companyId);
        params.formRef?.update?.({ branch_id: '' });
    }

    onMounted(async () => {
        if (showCompanyField.value) {
            await fetchCompany();
        }

        if (isCompanyadmin.value && authUser.value?.company_id) {
            await loadBranchOptions(authUser.value.company_id);
            return;
        }

        if (selectedCompanyId.value) {
            await loadBranchOptions(selectedCompanyId.value);
        }
    });

    watch(
        () => params.formData?.company_id,
        async (companyId, previousCompanyId) => {
            if (companyId === previousCompanyId) {
                return;
            }

            await loadBranchOptions(companyId);
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true"/>

    <SelectElement
        v-if="showCompanyField"
        name="company_id"
        :native="false"
        :items="companiesdata"
        id="CompanyId"
        field-name="CompanyId"
        placeholder="Select company"
        label="Company"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        @input="handleCompanyChange"
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
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="branchDisabled"
    />

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Name"
        placeholder="Enter role name"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        :rules="nameRules"
    />

    <StaticElement tag="br" name="element" />

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        id="IsActive"
        field-name="IsActive"
        name="is_active"
        label="Is Active"
        :true-value="true"
        :false-value="false"
        :default="true"
    />
</template>
