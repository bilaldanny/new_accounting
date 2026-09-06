<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { onMounted, ref, watchEffect, computed } from 'vue';
    import TheFilter from '@/components/theFilter.vue';
    import TheTable from '@/components/theTable.vue';
    import TopButtons from '@/components/topButtons.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import usePayments from '@/composables/payment';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import debounce from '@/utils/debounce';

    defineOptions({
        layout: {
            title: 'Payment',
            subtitle: 'Bank, cash, and online payment vouchers',
            breadcrumbs: [
                {
                    title: 'Payment',
                    href: 'NULL',
                },
            ],
        },
    });

    const { props } = usePage();

    const {
        state,
        getPayments,
        deleteRecord,
        changeOrder,
        checkAll,
        duplicate,
    } = usePayments();

    const {select_data, getSavedValue, formatedText, fetchCompany, fetchBranch, companiesdata, branchesdata} = useCommons();

    const authUser = computed(() => props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyFilter = computed(() => isSuperadmin.value);
    const showBranchFilter = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showFilter = computed(() => true);
    const branchFilterDisabled = computed(() => showCompanyFilter.value && !state.search.company_id);

    const columns = computed(() => [
        ...(isSuperadmin.value ? [
            { key: 'company_name', label: 'Company', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        ] : []),
        ...(isSuperadmin.value || isCompanyadmin.value ? [
            { key: 'branch_name', label: 'Branch', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        ] : []),
        { key: 'voucher_no', label: 'Voucher No', type: 'primary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'kind_label', label: 'Type', type: 'secondary', responsive: ['sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'voucher_date_label', label: 'Date', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'formatted_amount', label: 'Total Amount', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'status_label', label: 'Status', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', actions: ['view', 'edit', 'delete', 'duplicate']},
    ]);

    const currentUrl = ref('');
    const oldcurrentUrl = ref((getSavedValue('currentUrl') || ''));
    const currentPage = ref(getSavedValue('currentPage', (v) => parseInt(v, 10)) || 1);
    const currentSearch = ref(getSavedValue('currentSearch') || '');
    const currentStatus = ref(getSavedValue('currentStatus') || 'all');
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

    const debouncedGetPayments = debounce((params) => {
        getPayments(params);
    }, 300);

    const getData = async () => {
        try {
            state.loading = true;

            if(currentRecord.value !== state.search.show_record){
                state.search.page = 1;
            }

            await debouncedGetPayments({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentStatus.value = state.search.status;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching payments:', error);
        }
    };

    onMounted(async () => {
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';

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

        debouncedGetPayments({ ...state.search });
    });

    async function handleCompanyFilterChange(companyId: string | number | null | undefined) {
        state.search.branch_id = '';
        await fetchBranch(companyId);
    }

    function onStateUpdate(newState) {
        Object.assign(state, newState)
    }

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.payments, () => state);

    const filterOpen = ref(false);

    function clearSearch() {
        state.search.status = 'all';
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
                    :deleteRecord="deleteRecord"
                    :url="`${props.routeName?.split('.')[0]}`"
                    add-href="/acpayment/add"
                    :show-filter="showFilter"
                    :show-import="false"
                    :show-status="false"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter v-if="showFilter" v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div v-if="showCompanyFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="payment-filter-company">Company</label>
                    <select
                        id="payment-filter-company"
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
                    <label class="form-label" for="payment-filter-branch">Branch</label>
                    <select
                        id="payment-filter-branch"
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
                    <label class="form-label" for="payment-filter-status">Status</label>
                    <select id="payment-filter-status" class="form-select form-select-sm" v-model="state.search.status">
                        <option value="all">All</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
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
                        :delete="deleteRecord"
                        :duplicate="duplicate"
                        :view-route="(id) => `/acpayment/${id}/view`"
                        actionType="link"
                        :apiUrl="props.routeName?.split('.')[0]"
                        show-export
                        :export-file-name="String(props.routeName ?? 'export').replace(/\./g, '-')"
                        :export-title="formatedText(props.routeName)"
                        :export-all-rows="fetchAllRowsForExport"
                        @update:state="onStateUpdate"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
