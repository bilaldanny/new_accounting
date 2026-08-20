<script setup lang="ts">
    import useCommons from '@/composables/common';
    import {
        resolveClassificationForAccount,
        resolveClassificationForParent,
        type ControlAccountOption,
    } from '@/composables/chartOfAccountClassification';
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
        controlAccounts: {
            type: Array as () => ControlAccountOption[],
            default: () => [],
        },
        onParentChange: {
            type: Function,
            default: null,
        },
        onAccountTypeChange: {
            type: Function,
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
    const isEdit = computed(() => params.type === 'edit');

    const { fetchCompany, fetchBranch } = useCommons();

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const lastFetchedCompanyId = ref('');

    const colFull = { container: 12, label: 12, wrapper: 12 };
    const colHalf = { container: 6, label: 12, wrapper: 12 };

    const accountGroupItems = [
        { id: 'c', text: 'Control' },
        { id: 't', text: 'Detail (Transactional)' },
    ];

    const accountNatureItems = [
        { id: 'dr', text: 'Debit' },
        { id: 'cr', text: 'Credit' },
    ];

    const codeRules = computed(() => {
        if (isEdit.value) {
            return '';
        }

        return 'required|chart_of_account_code_unique';
    });

    const classification = computed(() => {
        if (isEdit.value) {
            return resolveClassificationForAccount(
                params.formData?.code,
                params.formData?.bs,
                params.formData?.pl,
                params.formData?.acc_type || 't',
            );
        }

        return resolveClassificationForParent(
            params.formData?.parent_id,
            params.controlAccounts,
            params.formData?.acc_type || 't',
        );
    });

    const accountTypeLabel = computed(() => classification.value?.label ?? 'Select a parent account');
    const financialStatementLabel = computed(() => classification.value?.financial_statement ?? '—');
    const allowTransactionsLabel = computed(() => (params.formData?.acc_type === 't' ? 'On' : 'Off'));

    const accountGroupLabel = computed(() => {
        if (params.formData?.acc_type === 'c') {
            return 'Control';
        }

        if (params.formData?.acc_type === 't') {
            return 'Detail (Transactional)';
        }

        return '—';
    });

    const accountNatureLabel = computed(() => {
        if (params.formData?.acc_nature === 'cr') {
            return 'Credit';
        }

        if (params.formData?.acc_nature === 'dr') {
            return 'Debit';
        }

        return '—';
    });

    function syncHiddenFinancialFields() {
        if (! classification.value || ! params.formRef?.update) {
            return;
        }

        params.formRef.update({
            bs: classification.value.bs,
            pl: classification.value.pl,
        });
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
            params.formRef?.update?.(updates);
        }
    }

    async function loadBranchOptions(companyId: string | number | null | undefined) {
        const normalizedCompanyId = normalizeId(companyId);

        if (! normalizedCompanyId) {
            return;
        }

        if (normalizedCompanyId === lastFetchedCompanyId.value) {
            return;
        }

        lastFetchedCompanyId.value = normalizedCompanyId;
        await fetchBranch(normalizedCompanyId);
    }

    function syncParentSelection() {
        if (isEdit.value) {
            return;
        }

        const parentId = params.formData?.parent_id;

        if (! parentId || ! params.formRef?.update) {
            return;
        }

        params.formRef.update({
            parent_id: Number(parentId),
        });
    }

    onMounted(async () => {
        applyScopedDefaults();

        if (isSuperadmin.value) {
            await fetchCompany();
        }

        const companyId = isCompanyadmin.value
            ? authUser.value?.company_id
            : selectedCompanyId.value;

        if (companyId) {
            await loadBranchOptions(companyId);
        }

        syncParentSelection();
        syncHiddenFinancialFields();
    });

    watch(
        () => normalizeId(params.formData?.parent_id),
        async (parentId, previousParentId) => {
            if (isEdit.value || parentId === previousParentId || ! parentId) {
                return;
            }

            await params.onParentChange?.(parentId);
            syncHiddenFinancialFields();
        },
    );

    watch(
        () => normalizeId(params.formData?.acc_type),
        async (accType, previousAccType) => {
            if (isEdit.value || accType === previousAccType || ! accType) {
                return;
            }

            const parentId = params.formData?.parent_id;

            if (! parentId) {
                return;
            }

            await params.onAccountTypeChange?.(accType);
            syncHiddenFinancialFields();
        },
    );

    watch(
        () => [params.formRef, params.formData?.parent_id, params.controlAccounts?.length, classification.value?.label],
        () => {
            syncParentSelection();
            syncHiddenFinancialFields();
        },
        { flush: 'post' },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />

    <TextElement v-if="isEdit" name="id" hidden="true" />

    <TextElement name="company_id" hidden="true" />

    <TextElement name="branch_id" hidden="true" />

    <TextElement name="bs" hidden="true" />

    <TextElement name="pl" hidden="true" />

    <SelectElement
        name="parent_id"
        :native="false"
        :items="controlAccounts"
        id="ParentId"
        field-name="ParentId"
        placeholder="Select parent account"
        label="Parent"
        :columns="colFull"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        rules="required"
        info="Child accounts must belong to the same company and branch as the selected parent."
    />

    <StaticElement
        v-if="isEdit"
        name="code_display"
        tag="div"
        label="Code"
        :content="params.formData?.code ?? ''"
        :columns="colHalf"
    />

    <TextElement
        v-else
        id="Code"
        field-name="Code"
        name="code"
        label="Code"
        placeholder="Account Code"
        :columns="colHalf"
        :readonly="true"
        autocomplete="off"
        :rules="codeRules"
        info="Auto-generated from the selected parent account."
    />

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Name"
        placeholder="Account Name"
        :columns="colHalf"
        autocomplete="off"
        rules="required|min:3|max:200"
    />

    <StaticElement
        name="account_type_display"
        tag="div"
        label="Account Type"
        :columns="colHalf"
        info="Inherited from the root classification of the selected parent hierarchy."
    >
        <span class="coa-readonly-badge">{{ accountTypeLabel }}</span>
    </StaticElement>

    <SelectElement
        v-if="! isEdit"
        name="acc_type"
        :native="false"
        :items="accountGroupItems"
        id="AccountGroup"
        field-name="AccountGroup"
        placeholder="Select account group"
        label="Account Group"
        :columns="colHalf"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        :can-clear="false"
        :default="'t'"
        rules="required"
        info="Control accounts organize the hierarchy. Detail accounts are used for transactions."
    />

    <StaticElement
        v-else
        name="account_group_display"
        tag="div"
        label="Account Group"
        :columns="colHalf"
        info="Cannot be changed once the account is created."
    >
        <span class="coa-readonly-badge">{{ accountGroupLabel }}</span>
    </StaticElement>

    <SelectElement
        v-if="! isEdit"
        name="acc_nature"
        :native="false"
        :items="accountNatureItems"
        id="AccountNature"
        field-name="AccountNature"
        placeholder="Select account nature"
        label="Account Nature"
        :columns="colHalf"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        :can-clear="false"
        :default="'dr'"
        rules="required"
        info="Suggested from account type. You may adjust if your accounting policy requires it."
    />

    <StaticElement
        v-else
        name="account_nature_display"
        tag="div"
        label="Account Nature"
        :columns="colHalf"
        info="Cannot be changed once the account is created."
    >
        <span class="coa-readonly-badge">{{ accountNatureLabel }}</span>
    </StaticElement>

    <StaticElement
        name="financial_statement_display"
        tag="div"
        label="Financial Statement"
        :columns="colHalf"
        info="Automatically determined from account classification."
    >
        <span class="coa-readonly-badge coa-readonly-badge--statement">{{ financialStatementLabel }}</span>
    </StaticElement>

    <StaticElement
        name="allow_transactions_display"
        tag="div"
        label="Allow Transactions"
        :columns="colHalf"
        info="Detail accounts allow transactions. Control accounts organize the chart of accounts."
    >
        <span
            class="coa-readonly-badge"
            :class="allowTransactionsLabel === 'On' ? 'coa-readonly-badge--success' : 'coa-readonly-badge--muted'"
        >
            {{ allowTransactionsLabel }}
        </span>
    </StaticElement>

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="colHalf"
        id="Active"
        field-name="Active"
        name="active"
        label="Status"
        :true-value="true"
        :false-value="false"
        :default="true"
    />
</template>
