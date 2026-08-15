<script setup>
    import { computed } from 'vue';
    import { usePage } from '@inertiajs/vue3';

    const props = defineProps({
        showCompanyFilter: {
            type: Boolean,
            default: false,
        },
        showBranchFilter: {
            type: Boolean,
            default: false,
        },
        showDepartmentFilter: {
            type: Boolean,
            default: true,
        },
        companiesdata: {
            type: Array,
            default: () => [],
        },
        branchesdata: {
            type: Array,
            default: () => [],
        },
        departmentsdata: {
            type: Array,
            default: () => [],
        },
        branchFilterDisabled: {
            type: Boolean,
            default: false,
        },
        departmentFilterDisabled: {
            type: Boolean,
            default: false,
        },
    });

    const page = usePage();

    const normalizeRoleName = (name) =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const authUser = computed(() => page.props.auth?.user ?? null);
    const isCompanyadmin = computed(() => normalizeRoleName(authUser.value?.rolename) === 'companyadmin');

    const colThird = { container: 4, label: 12, wrapper: 12 };
</script>

<template>
    <TextElement
        v-if="isCompanyadmin"
        name="company_id"
        hidden="true"
    />

    <SelectElement
        v-if="showCompanyFilter"
        name="company_id"
        :native="false"
        :items="companiesdata"
        id="PermissionScopeCompanyId"
        field-name="PermissionScopeCompanyId"
        placeholder="All companies"
        label="Company"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
    />

    <SelectElement
        v-if="showBranchFilter"
        name="branch_id"
        :native="false"
        :items="branchesdata"
        id="PermissionScopeBranchId"
        field-name="PermissionScopeBranchId"
        placeholder="All branches"
        label="Branch"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="!isCompanyadmin"
        :disabled="branchFilterDisabled"
    />

    <SelectElement
        v-if="showDepartmentFilter"
        name="department_id"
        :native="false"
        :items="departmentsdata"
        id="PermissionScopeDepartmentId"
        field-name="PermissionScopeDepartmentId"
        placeholder="All departments"
        label="Department"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="departmentFilterDisabled"
    />
</template>
