<script setup lang="ts">
    import { onMounted, ref, watchEffect, computed } from 'vue';
    import TopButtons from '@/components/topButtons.vue';
    import TheFilter from '@/components/theFilter.vue';
    import useCommons from '@/composables/common';
    import { Head, usePage } from '@inertiajs/vue3';
    import debounce from '@/utils/debounce';
    import useSells from '@/composables/sell';
    import useSellApprovals from '@/composables/sellApproval';
    import TheTable from '@/components/theTable.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { createTableExportAllRows } from '@/composables/tableExportList';

    defineOptions({
        layout: {
            title: 'Sell Management',
            subtitle: 'Track customer invoices, status, and amounts',
            breadcrumbs: [
                {
                    title: 'Sell Management',
                    href: 'NULL',
                },
            ],
        },
    });

    const { props } = usePage();

    const {
        state,
        getSells,
        changeStatus,
        deleteRecord,
        changeOrder,
        checkAll,
        duplicate,
    } = useSells();
    const { approveSell } = useSellApprovals();

    const {select_data, getSavedValue, formatedText, fetchCompany, fetchBranch, companiesdata, branchesdata} = useCommons();

    const customersdata = ref<Array<{ id: number | string; text?: string; business_name?: string }>>([]);

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
        { key: 'transaction_date_label', label: 'Date', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'invoice_no', label: 'Invoice No', type: 'primary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'customer_name', label: 'Customer', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'status_label', label: 'Status', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'payment_status_label', label: 'Payment', type: 'secondary', responsive: ['sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'formatted_amount', label: 'Total', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', actions: ['view', 'edit', 'delete', 'duplicate', 'approve', 'issueNote']},
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

    const debouncedGetSells = debounce((params) => {
        getSells(params);
    }, 300);

    const getData = async () => {
        try {
            state.loading = true;

            if(currentRecord.value !== state.search.show_record){
                state.search.page = 1;
            }
            await debouncedGetSells({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentStatus.value = state.search.status;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching sells:', error);
        }
    };

    async function loadCustomers(companyId: string | number | null | undefined, branchId: string | number | null | undefined) {
        if (! companyId || ! branchId) {
            customersdata.value = [];

            return;
        }

        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchCustomers, {
                params: { company_id: companyId, branch_id: branchId },
            });
            customersdata.value = response.data ?? [];
        } catch {
            customersdata.value = [];
        }
    }

    async function handleCompanyFilterChange(companyId: string | number | null | undefined) {
        state.search.branch_id = '';
        state.search.contact_id = '';
        await fetchBranch(companyId);
        customersdata.value = [];
    }

    async function handleBranchFilterChange() {
        state.search.contact_id = '';
        await loadCustomers(state.search.company_id, state.search.branch_id);
    }

    onMounted(async () => {
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';

        if (showCompanyFilter.value) {
            await fetchCompany();
        }

        if (isCompanyadmin.value && authUser.value?.company_id) {
            await fetchBranch(authUser.value.company_id);
        }

        if (state.search.company_id && state.search.branch_id) {
            await loadCustomers(state.search.company_id, state.search.branch_id);
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

        debouncedGetSells({ ...state.search });
    });

    function onStateUpdate(newState) {
        Object.assign(state, newState)
    }

    async function handleApprove(id: number) {
        const approved = await approveSell(id);

        if (approved) {
            getData();
        }
    }

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.sells, () => state);

    const filterOpen = ref(false);

    function clearSearch() {
        state.search.status = 'all';
        state.search.payment_status = 'all';
        state.search.search = '';
        state.search.show_record = 10;
        state.search.page = 1;
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';
        state.search.contact_id = '';
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
                    :changeStatus="changeStatus"
                    :deleteRecord="deleteRecord"
                    :url="`${props.routeName?.split('.')[0]}`"
                    add-href="/sell/add"
                    :show-filter="showFilter"
                    :show-import="false"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter v-if="showFilter" v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div v-if="showCompanyFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="sell-filter-company">Company</label>
                    <select
                        id="sell-filter-company"
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
                    <label class="form-label" for="sell-filter-branch">Branch</label>
                    <select
                        id="sell-filter-branch"
                        class="form-select form-select-sm"
                        v-model="state.search.branch_id"
                        :disabled="branchFilterDisabled"
                        @change="handleBranchFilterChange"
                    >
                        <option value="">All</option>
                        <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                            {{ branch.text ?? branch.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="sell-filter-customer">Customer</label>
                    <select
                        id="sell-filter-customer"
                        class="form-select form-select-sm"
                        v-model="state.search.contact_id"
                    >
                        <option value="">All</option>
                        <option v-for="customer in customersdata" :key="customer.id" :value="customer.id">
                            {{ customer.text ?? customer.business_name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="sell-filter-status">Sell status</label>
                    <select id="sell-filter-status" class="form-select form-select-sm" v-model="state.search.status">
                        <option value="all">All</option>
                        <option value="final">Final</option>
                        <option value="draft">Draft</option>
                        <option value="quotation">Quotation</option>
                        <option value="approved">Approved</option>
                        <option value="issue">Issue</option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="sell-filter-payment">Payment status</label>
                    <select id="sell-filter-payment" class="form-select form-select-sm" v-model="state.search.payment_status">
                        <option value="all">All</option>
                        <option value="due">Due</option>
                        <option value="partial">Partial</option>
                        <option value="paid">Paid</option>
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
                        :changeStatus="changeStatus"
                        :delete="deleteRecord"
                        :duplicate="duplicate"
                        :approve="handleApprove"
                        :view-route="(id) => `/sell/${id}/view`"
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
