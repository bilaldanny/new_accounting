<script setup lang="ts">
    import { onMounted, ref, watchEffect } from 'vue';
    import TopButtons from '@/components/topButtons.vue';
    import TheFilter from '@/components/theFilter.vue';
    import useCommons from '@/composables/common';
    import { Head, usePage } from '@inertiajs/vue3';
    import debounce from '@/utils/debounce';
    import useTimezones from '@/composables/timezone';
    import TheTable from '@/components/theTable.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import AddModal from './add.vue';
    import EditModal from './edit.vue';

    defineOptions({
        layout: {
            title: 'Timezone Management',
            subtitle: 'Manage application timezones',
            breadcrumbs: [
                {
                    title: 'Timezone Management',
                    href: 'NULL',
                },
            ],
        },
    });

    const { props } = usePage();

    const form$ = ref(null);
    const edit_id = ref({ id: 0 });
    let editFetchToken = 0;

    const {
        state,
        getTimezones,
        deleteRecord,
        changeOrder,
        checkAll,
        formData,
        defaultFormData,
        getEditData,
    } = useTimezones();

    const { select_data, getSavedValue, formatedText, handleError, handleSuccess } = useCommons();

    const columns = [
        { key: 'select', label: '', type: 'checkbox', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
        { key: 'count', label: 'S.No', type: 'count', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
        { key: 'name', label: 'Name', type: 'primary', responsive: ['sm', 'md', 'lg'] },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled', actions: ['edit', 'delete'] },
    ];

    const currentUrl = ref('');
    const oldcurrentUrl = ref(getSavedValue('currentUrl') || '');
    const currentPage = ref(getSavedValue('currentPage', (v) => parseInt(v, 10)) || 1);
    const currentSearch = ref(getSavedValue('currentSearch') || '');
    const currentRecord = ref(getSavedValue('currentRecord', (v) => parseInt(v, 10)) || 10);

    if (getSavedValue('currentUrl') === props.routeName) {
        currentUrl.value = getSavedValue('currentUrl') || props.routeName;
    } else {
        currentUrl.value = props.routeName;
    }

    const stateRefMap = {
        currentPage,
        currentSearch,
        currentRecord,
        currentUrl,
    } as const;

    watchEffect(() => {
        if (typeof localStorage === 'undefined') {
            return;
        }

        ['currentPage', 'currentSearch', 'currentRecord', 'currentUrl'].forEach((key) => {
            const val = stateRefMap[key as keyof typeof stateRefMap]?.value;
            if (val !== undefined && val !== null) {
                localStorage.setItem(key, String(val));
            }
        });
    });

    const debouncedGetTimezones = debounce((params) => {
        getTimezones(params);
    }, 300);

    const getData = async () => {
        try {
            state.loading = true;

            if (currentRecord.value !== state.search.show_record) {
                state.search.page = 1;
            }
            await debouncedGetTimezones({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching timezones:', error);
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

    onMounted(() => {
        if (oldcurrentUrl.value === props.routeName) {
            const savedValues = {
                page: getSavedValue('currentPage', (v) => parseInt(v, 10)),
                search: getSavedValue('currentSearch'),
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
        currentRecord.value = state.search.show_record;

        debouncedGetTimezones({ ...state.search });
    });

    const openAddModal = async () => {
        state.modalLoading = true;
        form$.value?.reset();
        formData.value = { ...defaultFormData.value };
        state.modalLoading = false;
    };

    function handleAddModalClose() {
        state.modalLoading = true;
    }

    function handleEditModalClose() {
        state.modalLoading = true;
    }

    function onStateUpdate(newState) {
        Object.assign(state, newState);
    }

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.timezones, () => state);

    const filterOpen = ref(false);

    function clearSearch() {
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
            <div class="admin-list-card__toolbar">
                <TopButtons
                    :state="state"
                    :filter-open="filterOpen"
                    :getData="getData"
                    :deleteRecord="deleteRecord"
                    :url="`${props.routeName?.split('.')[0]}`"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="timezone-filter-records">Show records</label>
                    <select
                        id="timezone-filter-records"
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
                        :delete="deleteRecord"
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
        :endpoint="API_ENDPOINTS.timezones"
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
        :endpoint="`${API_ENDPOINTS.timezones}/${edit_id.id}`"
        :onClose="handleEditModalClose"
        :success="(response) => handleSuccess(response, form$)"
        :error="(error, details) => handleError(error, details, form$)"
    />
</template>
