<script setup lang="ts">

    import { onMounted, ref, watchEffect, computed } from 'vue';
    import TopButtons from '@/components/topButtons.vue';
    import TheFilter from '@/components/theFilter.vue';
    import useCommons from '@/composables/common';
    import { Head, usePage } from '@inertiajs/vue3';
    import debounce from '@/utils/debounce';
    import useUsers from '@/composables/user';
    import TheTable from '@/components/theTable.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import AddModal from './add.vue';
    import EditModal from './edit.vue';
    import ImportModal from './import.vue';

    defineOptions({
        layout: {
            title: 'User Management',
            subtitle: 'Manage system users and access',
            breadcrumbs: [
                {
                    title: 'User Management',
                    href: 'NULL',
                },
            ],
        },
    });

    const { props } = usePage();

    const form$ = ref(null)

    const edit_id = ref({ id: 0 });
    let editFetchToken = 0;

    const {
        state,
        getUsers,
        changeStatus,
        deleteRecord,
        changeOrder,
        checkAll,
        duplicate,
        formData,
        defaultFormData,
        getEditData
    } = useUsers();

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
    const showFilter = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const branchFilterDisabled = computed(() => showCompanyFilter.value && !state.search.company_id);

    const columns = computed(() => [
        { key: 'select', label: '', type: 'checkbox', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled' },
        { key: 'count', label: 'S.No', type: 'count', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled' },
        ...(isSuperadmin.value ? [
            { key: 'company_name', label: 'Company', type: 'secondary', responsive: ['md', 'lg'], emptyDisplay: '-' },
        ] : []),
        ...(isSuperadmin.value || isCompanyadmin.value ? [
            { key: 'branch_name', label: 'Branch', type: 'secondary', responsive: ['md', 'lg'], emptyDisplay: '-' },
        ] : []),
        { key: 'full_name', label: 'Name', type: 'primary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'email', label: 'Email', type: 'secondary', responsive: ['sm', 'md', 'lg'] },
        { key: 'role_name', label: 'Role', type: 'secondary', responsive: ['md', 'lg'] },
        { key: 'is_active', label: 'Status', type: 'badge', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', show: 'active' },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', actions: ['edit', 'delete', 'duplicate']},
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

    const debouncedGetUsers = debounce((params) => {
        getUsers(params);
    }, 300);

    const getData = async () => {
        try {
            state.loading = true;

            if(currentRecord.value !== state.search.show_record){
                state.search.page = 1;
            }
            await debouncedGetUsers({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentStatus.value = state.search.status;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching users:', error);
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

        debouncedGetUsers({ ...state.search });
    });

    const openAddModal = async () => {
        state.modalLoading = true;
        form$.value?.reset();
        formData.value = {
            ...defaultFormData.value,
            ...(isSuperadmin.value
                ? {}
                : isCompanyadmin.value
                    ? { company_id: authUser.value?.company_id ?? '' }
                    : {
                        company_id: authUser.value?.company_id ?? '',
                        branch_id: authUser.value?.branch_id ?? '',
                    }),
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

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.users, () => state);

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
                    :changeStatus="changeStatus"
                    :deleteRecord="deleteRecord"
                    :url="`${props.routeName?.split('.')[0]}`"
                    :show-filter="showFilter"
                    :show-import="true"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="user-filter-status">Status</label>
                    <select
                        id="user-filter-status"
                        class="form-select form-select-sm"
                        v-model="state.search.status"
                    >
                        <option value="all">All</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="user-filter-records">Show records</label>
                    <select
                        id="user-filter-records"
                        class="form-select form-select-sm"
                        v-model="state.search.show_record"
                    >
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </div>
                <div v-if="showCompanyFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="user-filter-company">Company</label>
                    <select
                        id="user-filter-company"
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
                    <label class="form-label" for="user-filter-branch">Branch</label>
                    <select
                        id="user-filter-branch"
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
                        :edit="EditModalOpen"
                        actionType="modal"
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

    <AddModal
        :showLoader="state.modalLoading"
        :formData="formData"
        :formRef="form$"
        :endpoint="API_ENDPOINTS.users"
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
        :endpoint="`${API_ENDPOINTS.users}/${edit_id.id}`"
        :onClose="handleEditModalClose"
        :success="(response) => handleSuccess(response, form$)"
        :error="(error, details) => handleError(error, details, form$)"
    />

    <ImportModal :on-success="getData" />
</template>
