<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { onMounted, ref, watchEffect, computed } from 'vue';
    import TheFilter from '@/components/theFilter.vue';
    import TheTable from '@/components/theTable.vue';
    import TopButtons from '@/components/topButtons.vue';
    import useCommons from '@/composables/common';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import useVoucherApprovals, { VOUCHER_APPROVAL } from '@/composables/voucherApproval';
    import type { VoucherFamily } from '@/composables/voucherApproval';
    import debounce from '@/utils/debounce';

    /**
     * The approval list shared by journal entries, payments, expenses, deposits and fund transfers:
     * pending vouchers by default, with view, approve and reject actions the user's role allows.
     */
    const voucher = defineProps<{
        family: VoucherFamily;
        /** Menu path prefix without the slash, e.g. `expense`: the row-level view permission is `/expense/:id/view`. */
        apiUrl: string;
        /** Where the row view link goes, e.g. `/expense/approval` gives `/expense/approval/{id}/view`. */
        viewBase: string;
        exportName: string;
    }>();

    const { props } = usePage();

    const {
        state,
        config,
        getApprovals,
        changeOrder,
        checkAll,
        approveVoucher,
        rejectVoucher,
    } = useVoucherApprovals(voucher.family);

    const {select_data, getSavedValue, formatedText, fetchCompany, fetchBranch, companiesdata, branchesdata} = useCommons();

    const authUser = computed(() => props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
        permission_paths?: string[];
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyFilter = computed(() => isSuperadmin.value);
    const showBranchFilter = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const branchFilterDisabled = computed(() => showCompanyFilter.value && !state.search.company_id);

    const permissionPaths = computed(() => authUser.value?.permission_paths ?? []);
    const canApprove = computed(() => permissionPaths.value.includes(VOUCHER_APPROVAL[voucher.family].approvePath));
    const canReject = computed(() => permissionPaths.value.includes(VOUCHER_APPROVAL[voucher.family].rejectPath));

    const columns = computed(() => [
        ...(isSuperadmin.value ? [
            { key: 'company_name', label: 'Company', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        ] : []),
        ...(isSuperadmin.value || isCompanyadmin.value ? [
            { key: 'branch_name', label: 'Branch', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        ] : []),
        { key: 'voucher_no', label: 'Voucher No', type: 'primary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'voucher_date_label', label: 'Date', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'formatted_amount', label: 'Total Amount', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'status_label', label: 'Status', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', actions: ['view', 'approve', 'reject']},
    ]);

    const currentUrl = ref('');
    const oldcurrentUrl = ref((getSavedValue('currentUrl') || ''));
    const currentPage = ref(getSavedValue('currentPage', (v) => parseInt(v, 10)) || 1);
    const currentSearch = ref(getSavedValue('currentSearch') || '');
    const currentStatus = ref(getSavedValue('currentStatus') || 'pending');
    const currentRecord = ref(getSavedValue('currentRecord', (v) => parseInt(v, 10)) || 10);

    if(getSavedValue('currentUrl') === props.routeName){
        currentUrl.value = (getSavedValue('currentUrl') || props.routeName);
    }else{
        currentUrl.value = props.routeName;
    }

    const stateRefMap = {
        currentPage,
        currentSearch,
        currentStatus,
        currentRecord,
        currentUrl,
    } as const;

    watchEffect(() => {
        if (typeof localStorage === 'undefined') {
            return;
        }

        ;['currentPage', 'currentSearch', 'currentStatus', 'currentRecord', 'currentUrl'].forEach((key) => {
            const val = stateRefMap[key as keyof typeof stateRefMap]?.value

            if (val !== undefined && val !== null) {
            localStorage.setItem(key, val)
            }
        })
    })

    const debouncedGetApprovals = debounce((params) => {
        getApprovals(params);
    }, 300);

    const getData = async () => {
        try {
            state.loading = true;

            if(currentRecord.value !== state.search.show_record){
                state.search.page = 1;
            }

            await debouncedGetApprovals({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentStatus.value = state.search.status;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching approvals:', error);
        }
    };

    async function handleCompanyFilterChange(companyId: string | number | null | undefined) {
        state.search.branch_id = '';
        await fetchBranch(companyId);
    }

    async function handleApprove(id: number) {
        if (await approveVoucher(id)) {
            getData();
        }
    }

    async function handleReject(id: number) {
        if (await rejectVoucher(id)) {
            getData();
        }
    }

    onMounted(async () => {
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';
        state.search.status = 'pending';

        if (showCompanyFilter.value) {
            await fetchCompany();
        }

        if (isCompanyadmin.value && authUser.value?.company_id) {
            await fetchBranch(authUser.value.company_id);
        }

        if(oldcurrentUrl.value === props.routeName){
            const savedValues = {
                page: getSavedValue('currentPage', (v) => parseInt(v, 10)),
                search: getSavedValue('currentSearch'),
                status: getSavedValue('currentStatus'),
                show_record: getSavedValue('currentRecord', (v) => parseInt(v, 10)),
            };

            Object.keys(savedValues).forEach((key) => {
                if (savedValues[key] !== null) {
                    state.search[key] = savedValues[key];
                }
            });
        }

        currentPage.value = state.search.page;
        currentSearch.value = state.search.search;
        currentStatus.value = state.search.status;
        currentRecord.value = state.search.show_record;

        debouncedGetApprovals({ ...state.search });
    });

    function onStateUpdate(newState) {
        Object.assign(state, newState)
    }

    const fetchAllRowsForExport = createTableExportAllRows(config.endpoint, () => state);

    const filterOpen = ref(false);

    function clearSearch() {
        state.search.status = 'pending';
        state.search.search = '';
        state.search.show_record = 10;
        state.search.page = 1;
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';
        getData();
    }

</script>

<template>
    <Head :title="formatedText(props.routeName)" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar">
                <TopButtons
                    :state="state"
                    :filter-open="filterOpen"
                    :getData="getData"
                    :url="voucher.apiUrl"
                    show-filter
                    :show-import="false"
                    :show-add="false"
                    :show-status="false"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div v-if="showCompanyFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" :for="`${voucher.family}-approval-filter-company`">Company</label>
                    <select
                        :id="`${voucher.family}-approval-filter-company`"
                        class="form-select form-select-sm"
                        v-model="state.search.company_id"
                        @change="handleCompanyFilterChange(state.search.company_id)"
                    >
                        <option value="">All</option>
                        <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                            {{ company.text ?? company.name }}
                        </option>
                    </select>
                </div>
                <div v-if="showBranchFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" :for="`${voucher.family}-approval-filter-branch`">Branch</label>
                    <select
                        :id="`${voucher.family}-approval-filter-branch`"
                        class="form-select form-select-sm"
                        v-model="state.search.branch_id"
                        :disabled="branchFilterDisabled"
                    >
                        <option value="">All</option>
                        <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                            {{ branch.text ?? branch.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" :for="`${voucher.family}-approval-filter-status`">Status</label>
                    <select :id="`${voucher.family}-approval-filter-status`" class="form-select form-select-sm" v-model="state.search.status">
                        <option value="all">All</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </TheFilter>

            <div class="admin-list-card__body">
                <div class="admin-list-table">
                    <TheTable
                        :columns="columns"
                        :selectData="select_data"
                        :state="state"
                        :checkAll="checkAll"
                        :getData="getData"
                        :changeOrder="changeOrder"
                        :approve="canApprove ? handleApprove : undefined"
                        :reject="canReject ? handleReject : undefined"
                        :view-route="(id) => `${voucher.viewBase}/${id}/view`"
                        actionType="link"
                        :apiUrl="voucher.apiUrl"
                        show-export
                        :export-file-name="voucher.exportName"
                        :export-title="formatedText(props.routeName)"
                        :export-all-rows="fetchAllRowsForExport"
                        @update:state="onStateUpdate"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
