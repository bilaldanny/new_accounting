<script setup lang="ts">

    import { onMounted, ref, watchEffect, computed, nextTick } from 'vue';
    import TopButtons from '@/components/topButtons.vue';
    import TheFilter from '@/components/theFilter.vue';
    import useCommons from '@/composables/common';
    import { Head, usePage } from '@inertiajs/vue3';
    import debounce from '@/utils/debounce';
    import useSellPayments from '@/composables/sellPayment';
    import TheTable from '@/components/theTable.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import AddModal from './add.vue';
    import EditModal from './edit.vue';

    defineOptions({
        layout: {
            title: 'Sell Payment',
            subtitle: 'Record customer payments against sales invoices',
            breadcrumbs: [
                {
                    title: 'Sell Payment',
                    href: 'NULL',
                },
            ],
        },
    });

    const { props } = usePage();
    const moduleUrl = 'sell/payment';

    const form$ = ref(null)

    const edit_id = ref({ id: 0 });
    let editFetchToken = 0;

    const {
        state,
        getSellPayments,
        deleteRecord,
        changeOrder,
        checkAll,
        formData,
        defaultFormData,
        emptyForm,
        getEditData
    } = useSellPayments();

    const {select_data, getSavedValue, formatedText, handleError, handleSuccess, fetchCompany, fetchBranch, companiesdata, branchesdata} = useCommons();

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
        { key: 'paid_on_label', label: 'Date', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'payment_ref_no', label: 'Payment No', type: 'primary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'invoice_no', label: 'Sell Ref', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'customer_name', label: 'Customer', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'method_label', label: 'Method', type: 'secondary', responsive: ['sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'formatted_amount', label: 'Amount', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'sell_payment_status', label: 'Sell Status', type: 'secondary', responsive: ['sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', actions: ['edit', 'delete']},
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

    const debouncedGetSellPayments = debounce((params) => {
        getSellPayments(params);
    }, 300);

    const getData = async () => {
        try {
            state.loading = true;

            if(currentRecord.value !== state.search.show_record){
                state.search.page = 1;
            }
            await debouncedGetSellPayments({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentStatus.value = state.search.status;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching sell payments:', error);
        }
    };

    const EditModalOpen = (id: number) => {
        edit_id.value.id = id;
        state.modalLoading = true;
        const token = ++editFetchToken;

        getEditData(id).finally(() => {
            if (token === editFetchToken) {
                state.modalLoading = false;
            }
        });
    };

    onMounted(async () => {
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';

        const pageParams = new URLSearchParams(window.location.search);
        const transactionId = pageParams.get('transaction_id') ?? '';
        const openAdd = pageParams.get('add') === '1';

        if (transactionId) {
            state.search.transaction_id = transactionId;
        }

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

        debouncedGetSellPayments({ ...state.search });

        if (openAdd && transactionId) {
            await nextTick();
            await openAddModal();
            const addButton = document.querySelector('[data-bs-target="#AddModal"]') as HTMLElement | null;
            addButton?.click();
        }
    });

    const openAddModal = async () => {
        state.modalLoading = true;
        form$.value?.reset();
        const params = new URLSearchParams(window.location.search);
        const transactionId = params.get('transaction_id') ?? '';

        formData.value = {
            ...emptyForm(),
            ...defaultFormData.value,
            ...(isSuperadmin.value
                ? {}
                : isCompanyadmin.value
                    ? { company_id: authUser.value?.company_id ?? '' }
                    : {
                        company_id: authUser.value?.company_id ?? '',
                        branch_id: authUser.value?.branch_id ?? '',
                    }),
            ...(transactionId ? { transaction_id: transactionId } : {}),
        };

        if (showCompanyFilter.value) {
            await fetchCompany();
        }

        if (isCompanyadmin.value && authUser.value?.company_id) {
            await fetchBranch(authUser.value.company_id);
        }

        state.modalLoading = false;
    };

    async function handleCompanyFilterChange(companyId: string | number | null | undefined) {
        state.search.branch_id = '';
        await fetchBranch(companyId);
    }

    function handleAddModalClose() {
        state.modalLoading = true;
    }

    function handleEditModalClose() {
        state.modalLoading = true;
    }

    function onStateUpdate(newState) {
        Object.assign(state, newState)
    }

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.sellPayments, () => state);

    const filterOpen = ref(false);

    function clearSearch() {
        state.search.status = 'all';
        state.search.search = '';
        state.search.show_record = 10;
        state.search.page = 1;
        state.search.method = 'all';
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
                    :url="moduleUrl"
                    :show-filter="showFilter"
                    :show-import="false"
                    :show-status="false"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter v-if="showFilter" v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div v-if="showCompanyFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="sell-payment-filter-company">Company</label>
                    <select
                        id="sell-payment-filter-company"
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
                    <label class="form-label" for="sell-payment-filter-branch">Branch</label>
                    <select
                        id="sell-payment-filter-branch"
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
                    <label class="form-label" for="sell-payment-filter-method">Method</label>
                    <select
                        id="sell-payment-filter-method"
                        class="form-select form-select-sm"
                        v-model="state.search.method"
                    >
                        <option value="all">All</option>
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="cheque">Cheque</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="other">Other</option>
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
                        :edit="EditModalOpen"
                        actionType="modal"
                        :apiUrl="moduleUrl"
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

    <AddModal
        :showLoader="state.modalLoading"
        :formData="formData"
        :formRef="form$"
        :endpoint="API_ENDPOINTS.sellPayments"
        :onOpen="openAddModal"
        :onClose="handleAddModalClose"
        :success="(response) => handleSuccess(response, form$)"
        :error="(error, details) => handleError(error, details, form$)"
    />

    <EditModal
        :showLoader="state.modalLoading"
        :formData="formData"
        :formRef="form$"
        :record-id="edit_id.id || null"
        :endpoint="`${API_ENDPOINTS.sellPayments}/${edit_id.id}`"
        :onClose="handleEditModalClose"
        :success="(response) => handleSuccess(response, form$)"
        :error="(error, details) => handleError(error, details, form$)"
    />
</template>
