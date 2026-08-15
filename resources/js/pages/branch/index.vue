<script setup lang="ts">

    import { computed, onMounted, ref, watchEffect } from 'vue';
    import TopButtons from '@/components/topButtons.vue';
    import TheFilter from '@/components/theFilter.vue';
    import useCommons from '@/composables/common';
    import { Head, usePage } from '@inertiajs/vue3';
    import debounce from '@/utils/debounce';
    import useBranches from '@/composables/branch';
    import TheTable from '@/components/theTable.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import AddModal from './add.vue';
    import EditModal from './edit.vue';
    import ImportModal from './import.vue';

    defineOptions({
        layout: {
            title: 'Branch Management',
            subtitle: 'Manage company branches',
            breadcrumbs: [
                {
                    title: 'Branch Management',
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
        getBranches,
        changeStatus,
        deleteRecord,
        changeOrder,
        checkAll,
        duplicate,
        formData,
        defaultFormData,
        getEditData,
        generateBranchCode,
    } = useBranches();

    const {select_data, getSavedValue, formatedText, fetchCompany, fetchCountry, fetchState, fetchCity, companiesdata, countriesdata, statesdata, citiesdata, handleError, handleSuccess} = useCommons();

    const authUser = computed(() => props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
    } | null);

    const isSuperadmin = computed(() =>
        String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin',
    );

    const showCompanyFilter = computed(() => isSuperadmin.value);

    const columns = computed(() => {
        const cols = [
            { key: 'select', label: '', type: 'checkbox', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
            { key: 'count', label: 'S.No', type: 'count', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
        ];

        if (isSuperadmin.value) {
            cols.push({
                key: 'company_name',
                label: 'Company',
                type: 'secondary',
                responsive: ['md', 'lg'],
                emptyDisplay: '-',
            });
        }

        cols.push(
            { key: 'name', label: 'Branch Name', type: 'primary', responsive: ['sm', 'md', 'lg'] },
            { key: 'email', label: 'Email', type: 'secondary', responsive: ['md', 'lg'] },
            { key: 'phone', label: 'Phone', type: 'secondary', responsive: ['lg'] },
            { key: 'is_default', label: 'Default', type: 'badge', responsive: ['md', 'lg'], sorting: 'disabled', show: 'yes' },
            { key: 'is_active', label: 'Status', type: 'badge', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled', show: 'active' },
            { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled', actions: ['edit', 'delete', 'duplicate'] },
        );

        return cols;
    });

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

    const debouncedGetBranches = debounce((params) => {
        getBranches(params);
    }, 300);

    const getData = async () => {
        try {
            state.loading = true;

            if(currentRecord.value !== state.search.show_record){
                state.search.page = 1;
            }
            await debouncedGetBranches({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentStatus.value = state.search.status;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching branches:', error);
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
        if (showCompanyFilter.value) {
            await fetchCompany();
            await fetchCountry();
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

        if (!isSuperadmin.value && authUser.value?.company_id) {
            state.search.company_id = authUser.value.company_id;
            formData.value.company_id = authUser.value.company_id;
            defaultFormData.value.company_id = authUser.value.company_id;
        }

        if (state.search.country_id) {
            await fetchState(state.search.country_id);
        }

        if (state.search.state_id && state.search.country_id) {
            await fetchCity(state.search.country_id, state.search.state_id);
        }

        currentPage.value = state.search.page;
        currentSearch.value = state.search.search;
        currentStatus.value = state.search.status;
        currentRecord.value = state.search.show_record;

        debouncedGetBranches({ ...state.search });
    });

    const openAddModal = async () => {
        state.modalLoading = true;
        form$.value?.reset();
        formData.value = { ...defaultFormData.value };
        if (!isSuperadmin.value && authUser.value?.company_id) {
            formData.value.company_id = authUser.value.company_id;
        }
        if (showCompanyFilter.value) {
            await fetchCompany();
        }
        await fetchCountry();
        const code = await generateBranchCode();
        if (code) {
            formData.value.code = code;
        }
        state.modalLoading = false;
    };

    function handleAddModalClose() {
        state.modalLoading = true;
    }

    function handleEditModalClose() {
        state.modalLoading = true;
    }

    function onStateUpdate(newState) {
        Object.assign(state, newState)
    }

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.branches, () => state);

    const filterOpen = ref(false);

    function clearSearch() {
        state.search.status = 'all';
        state.search.search = '';
        state.search.show_record = 10;
        state.search.page = 1;
        state.search.country_id = '';
        state.search.state_id = '';
        state.search.city_id = '';
        if (!isSuperadmin.value && authUser.value?.company_id) {
            state.search.company_id = authUser.value.company_id;
        } else {
            state.search.company_id = '';
        }
        getData();
    }

    async function onCountryFilterChange(countryId: string | number) {
        state.search.state_id = '';
        state.search.city_id = '';
        if (countryId) {
            await fetchState(countryId);
        }
    }

    async function onStateFilterChange(stateId: string | number) {
        state.search.city_id = '';
        if (stateId && state.search.country_id) {
            await fetchCity(state.search.country_id, stateId);
        }
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
                    :show-add="state.can_add_branch"
                    :show-import="true"
                    @toggle-filter="filterOpen = !filterOpen"
                    :show-filter="isSuperadmin"
                />
            </div>

            <TheFilter v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div v-if="showCompanyFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="branch-filter-company">Company</label>
                    <select
                        id="branch-filter-company"
                        class="form-select form-select-sm"
                        v-model="state.search.company_id"
                    >
                        <option value="">All</option>
                        <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                            {{ company.text ?? company.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="branch-filter-country">Country</label>
                    <select
                        id="branch-filter-country"
                        class="form-select form-select-sm"
                        v-model="state.search.country_id"
                        @change="onCountryFilterChange(state.search.country_id)"
                    >
                        <option value="">All</option>
                        <option v-for="country in countriesdata" :key="country.id" :value="country.id">
                            {{ country.text ?? country.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="branch-filter-state">State</label>
                    <select
                        id="branch-filter-state"
                        class="form-select form-select-sm"
                        v-model="state.search.state_id"
                        :disabled="!state.search.country_id"
                        @change="onStateFilterChange(state.search.state_id)"
                    >
                        <option value="">All</option>
                        <option v-for="item in statesdata" :key="item.id" :value="item.id">
                            {{ item.text ?? item.name }}
                        </option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="branch-filter-city">City</label>
                    <select
                        id="branch-filter-city"
                        class="form-select form-select-sm"
                        v-model="state.search.city_id"
                        :disabled="!state.search.state_id"
                    >
                        <option value="">All</option>
                        <option v-for="city in citiesdata" :key="city.id" :value="city.id">
                            {{ city.text ?? city.name }}
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
        :endpoint="API_ENDPOINTS.branches"
        :onOpen="openAddModal"
        :onClose="handleAddModalClose"
        :success="(response) => handleSuccess(response, form$)"
        :error="(error, details) => handleError(error, details, form$)"
    />

    <EditModal
        :showLoader="state.modalLoading"
        :formData="formData"
        :formRef="form$"
        :endpoint="`${API_ENDPOINTS.branches}/${edit_id.id}`"
        :onClose="handleEditModalClose"
        :success="(response) => handleSuccess(response, form$)"
        :error="(error, details) => handleError(error, details, form$)"
    />

    <ImportModal :on-success="getData" />
</template>
