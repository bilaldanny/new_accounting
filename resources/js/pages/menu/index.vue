<script setup lang="ts">

    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { onMounted, ref, watchEffect } from 'vue';
    import TheFilter from '@/components/theFilter.vue';
    import TheTable from '@/components/theTable.vue';
    import TopButtons from '@/components/topButtons.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useMenus from '@/composables/menu';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import {dashboard} from '@/routes';
    import debounce from '@/utils/debounce';
    import AddModal from './add.vue';
    import EditModal from './edit.vue';
    import ImportModal from './import.vue';

    defineOptions({
        layout: {
            title: 'Menu Management',
            subtitle: 'Manage system menus and navigation',
            breadcrumbs: [
                {
                    title: 'Menu Management',
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
        getMenus,
        changeStatus,
        deleteRecord,
        changeOrder,
        updateSortOrder,
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
        { key: 'name', label: 'Name', type: 'primary', responsive: ['sm', 'md', 'lg'] },
        { key: 'route_path', label: 'Route', type: 'code', responsive: ['md', 'lg'] },
        { key: 'sort_order', label: 'Sort Order', type: 'sort_stepper', responsive: ['lg'] },
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
            if (typeof localStorage === 'undefined') {
                return;
            }

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

    /* Edit Modal — fetch on click so data loads even before Bootstrap show event fires */
    const EditModalOpen = (id: number) => {
        edit_id.value.id = id;
        state.modalLoading = true;
        const token = ++editFetchToken;

        Promise.all([fetchMenu(), getEditData(id)]).finally(() => {
            if (token === editFetchToken) {
                state.modalLoading = false;
            }
        });
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
        formData.value = { ...defaultFormData.value };
        await fetchMenu();
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

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.menus, () => state);

    /** Expands the current-page selection to every row matching the active search/status filter. */
    const selectAllAcrossPages = async () => {
        const rows = await fetchAllRowsForExport();
        state.edit_ids = rows.map((row) => Number(row.id)).filter((id) => Number.isFinite(id));
    };

    const filterOpen = ref(false);

    function setStatusFilter(status: 'all' | '1' | '0') {
        if (state.search.status === status) {
            return;
        }

        state.search.status = status;
        state.search.page = 1;
        getData();
    }

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

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar admin-list-card__toolbar--with-kpis">
                <div class="admin-list-card__toolbar-left">
                    <div class="modern-kpi-row">
                        <div class="modern-kpi-card">
                            <span class="modern-kpi-card__label">Total</span>
                            <span class="modern-kpi-card__value">{{ state.records.total }}</span>
                        </div>
                        <div class="modern-kpi-card modern-kpi-card--success">
                            <span class="modern-kpi-card__label">Active</span>
                            <span class="modern-kpi-card__value">{{ state.active_count }}</span>
                        </div>
                        <div class="modern-kpi-card modern-kpi-card--warning">
                            <span class="modern-kpi-card__label">Inactive</span>
                            <span class="modern-kpi-card__value">{{ state.inactive_count }}</span>
                        </div>
                        <Link
                            v-if="state.trash_count > 0"
                            :href="`/${props.routeName?.split('.')[0]}/trash`"
                            class="modern-kpi-card modern-kpi-card--danger"
                            title="Click to open trash"
                        >
                            <span class="modern-kpi-card__label">In Trash</span>
                            <span class="modern-kpi-card__value">{{ state.trash_count }}</span>
                        </Link>
                    </div>

                    <div class="modern-status-pills" role="tablist" aria-label="Filter by status">
                        <button
                            type="button"
                            class="modern-status-pill"
                            :class="{ 'is-active': state.search.status === 'all' }"
                            @click="setStatusFilter('all')"
                        >
                            All <span class="modern-status-pill__count">({{ state.records.total }})</span>
                        </button>
                        <button
                            type="button"
                            class="modern-status-pill"
                            :class="{ 'is-active': state.search.status === '1' }"
                            @click="setStatusFilter('1')"
                        >
                            <span class="modern-status-pill__dot modern-status-pill__dot--success"></span>
                            Active <span class="modern-status-pill__count">({{ state.active_count }})</span>
                        </button>
                        <button
                            type="button"
                            class="modern-status-pill"
                            :class="{ 'is-active': state.search.status === '0' }"
                            @click="setStatusFilter('0')"
                        >
                            <span class="modern-status-pill__dot modern-status-pill__dot--muted"></span>
                            Inactive <span class="modern-status-pill__count">({{ state.inactive_count }})</span>
                        </button>
                    </div>
                </div>

                <TopButtons
                    :state="state"
                    :filter-open="filterOpen"
                    :getData="getData"
                    :changeStatus="changeStatus"
                    :deleteRecord="deleteRecord"
                    :url="`${props.routeName?.split('.')[0]}`"
                    :show-import="true"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div class="col-md-4 col-lg-3 admin-filter-field">
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
                <div class="col-md-4 col-lg-3 admin-filter-field">
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

            <div class="admin-list-card__body">
                <div class="admin-list-table">
                    <TheTable
                        :columns="columns"
                        :selectData="select_data"
                        :state="state"
                        :checkAll="checkAll"
                        :getData="getData"
                        :changeOrder="changeOrder"
                        :updateSortOrder="updateSortOrder"
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
                        :select-all-across-pages="selectAllAcrossPages"
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
        :endpoint="API_ENDPOINTS.menus"
        :onOpen="openAddModal"
        :onClose="handleAddModalClose"
        :success="(response) => handleSuccess(response, form$)"
        :error="(error, details) => handleError(error, details, form$)"
    />

    <EditModal
        :showLoader="state.modalLoading"
        :formData="formData"
        :formRef="form$"
        :endpoint="`${API_ENDPOINTS.menus}/${edit_id.id}`"
        :onClose="handleEditModalClose"
        :success="(response) => handleSuccess(response, form$)"
        :error="(error, details) => handleError(error, details, form$)"
    />

    <ImportModal :on-success="getData" />
</template>

