<script setup lang="ts">

    import { onMounted, ref, watchEffect, defineAsyncComponent } from 'vue';
    import {dashboard} from '@/routes';
    import TopButtons from '@/components/topButtons.vue';
    import TheFilter from '@/components/theFilter.vue';
    import useCommons from '@/composables/common';
    import { Head, usePage } from '@inertiajs/vue3';
    import debounce from '@/utils/debounce';
    import useMenus from '@/composables/menu';
    import TheTable from '@/components/theTable.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import './menu-management.scss';

    defineOptions({
        layout: {
            title: 'Menu',
            breadcrumbs: [
                {
                    title: 'Menu',
                    href: 'NULL',
                },
            ],
        },
    });

    /* Add & Edit Modals */
        const AddModal = defineAsyncComponent(() => import('./add.vue'));
        const EditModal = defineAsyncComponent(() => import('./edit.vue'));
    /* Add & Edit Modals */

    const { props } = usePage();

    const form$ = ref(null)

    const edit_id = ref({ id: 0 });

    const {
        state,
        getMenus,
        changeStatus,
        deleteRecord,
        changeOrder,
        checkAll,
        duplicate,
        formData,
        defaultFormData,
        getEditData
    } = useMenus();

    const {select_data, getSavedValue, formatedText, fetchMenu, handleError, handleSuccess, appUrl} = useCommons();

    const columns = [
        { key: 'select', label: '', type: 'checkbox', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled' },
        { key: 'count', label: 'S.No', type: 'count', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled' },
        { key: 'name', label: 'Name', responsive: ['sm', 'md', 'lg'] },
        { key: 'route_path', label: 'Route', responsive: ['md', 'lg'] },
        { key: 'sort_order', label: 'Sort Order', responsive: ['lg'] },
        { key: 'is_active', label: 'Status', type: 'badge', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', show: 'active' },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', actions: ['edit', 'delete', 'duplicate']},
    ]

    /* History State */
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
            ;['currentPage', 'currentSearch', 'currentStatus', 'currentRecord', 'currentUrl'].forEach((key) => {
                const val = stateRefMap[key as keyof typeof stateRefMap]?.value
                if (val !== undefined && val !== null) {
                localStorage.setItem(key, val)   // will also save empty string
                }
            })
        })
    /* History State */

    /* Debounce */
    const debouncedGetMenus = debounce((params) => {
        getMenus(params);
    }, 300);

    /* GetData */
    const getData = async () => {
        try {
            state.loading = true;

            if(currentRecord.value !== state.search.show_record){
                state.search.page = 1;
            }
            await debouncedGetMenus({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentStatus.value = state.search.status;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching menus:', error);
        }
    };

    /* Edit Modal */
    const EditModalOpen = async (id) => {
        edit_id.value.id = id;
    };

    /* OnMounted */
    onMounted(() => {
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

        debouncedGetMenus({ ...state.search });
    });

    const openAddModal = async () => {
        state.modalLoading = true;
        form$.value?.reset();
        formData.value = { ...defaultFormData.value }
        fetchMenu();
        setTimeout(()=>{
            state.modalLoading = false;
        },1000)
    }

    const openEditModal = async () => {
        state.modalLoading = true;
        fetchMenu();
        getEditData(edit_id.value.id, form$);
        form$.value?.reset();
        setTimeout(()=>{
            state.modalLoading = false;
        },1000)
    }

    function onStateUpdate(newState) {
        Object.assign(state, newState)
    }

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.menus, () => state);

    const filterOpen = ref(false);

    function clearSearch() {
        state.search.status = 'all';
        state.search.search = '';
        state.search.show_record = 10;
        state.search.page = 1;
        getData();
    }

</script>

<template>
    <Head :title="formatedText(props.routeName)" />

    <div class="menu-management-page">
        <div class="menu-management-card">
            <div class="menu-management-card__toolbar">
                <TopButtons
                    :state="state"
                    :filter-open="filterOpen"
                    :getData="getData"
                    :changeStatus="changeStatus"
                    :deleteRecord="deleteRecord"
                    :url="`${props.routeName?.split('.')[0]}`"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div class="col-md-4 col-lg-3 menu-management-filter__field">
                    <label class="form-label" for="menu-filter-status">Status</label>
                    <select
                        id="menu-filter-status"
                        class="form-select form-select-sm"
                        v-model="state.search.status"
                    >
                        <option value="all">All</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3 menu-management-filter__field">
                    <label class="form-label" for="menu-filter-records">Show records</label>
                    <select
                        id="menu-filter-records"
                        class="form-select form-select-sm"
                        v-model="state.search.show_record"
                    >
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </div>
            </TheFilter>

            <div class="menu-management-card__body">
                <div class="menu-management-table">
                    <div class="table-responsive">
                        <div class="dataTables_wrapper dt-bootstrap5">
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
        </div>
    </div>

    <AddModal
        :showLoader="state.modalLoading"
        :formData="formData"
        :formRef="form$"
        :endpoint="API_ENDPOINTS.menus"
        :onOpen="openAddModal"
        :success="(response) => handleSuccess(response, form$)"
        :error="(error, details) => handleError(error, details, form$)"
    />

    <EditModal
        :showLoader="state.modalLoading"
        :formData="formData"
        :formRef="form$"
        :endpoint="`${API_ENDPOINTS.menus}/${edit_id.id}`"
        :onOpen="openEditModal"
        :success="(response) => handleSuccess(response, form$)"
        :error="(error, details) => handleError(error, details, form$)"
    />
</template>

