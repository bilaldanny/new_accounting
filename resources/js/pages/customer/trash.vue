<script setup lang="ts">
    import { onMounted, ref, watchEffect } from 'vue';
    import { customer } from '@/routes';
    import TopButtons from '@/components/topButtons.vue';
    import useCommons from '@/composables/common';
    import { Head, usePage } from '@inertiajs/vue3';
    import debounce from '@/utils/debounce';
    import useCustomers from '@/composables/customer';
    import TheTable from '@/components/theTable.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { createTableExportAllRows } from '@/composables/tableExportList';

    const { props } = usePage();
    const { select_data, getSavedValue, formatedText } = useCommons();

    defineOptions({
        layout: {
            title: 'Customer Trash',
            subtitle: 'Restore or permanently delete removed customers',
            breadcrumbs: [
                {
                    title: 'Customer Management',
                    href: customer().url,
                },
                {
                    title: 'Trash',
                    href: 'NULL',
                },
            ],
        },
    });

    const {
        state,
        getTrashCustomers,
        changeStatus,
        deleteRecord,
        changeOrder,
        checkAll,
        perDeleteBulkRecord,
        restoreBulkRecord,
    } = useCustomers();

    const columns = [
        { key: 'select', label: '', type: 'checkbox', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
        { key: 'count', label: 'S.No', type: 'count', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
        { key: 'code', label: 'Code', type: 'secondary', responsive: ['sm', 'md', 'lg'] },
        { key: 'business_name', label: 'Business Name', type: 'primary', responsive: ['sm', 'md', 'lg'] },
        { key: 'mobile', label: 'Mobile', type: 'secondary', responsive: ['md', 'lg'], emptyDisplay: '-' },
        { key: 'active', label: 'Status', type: 'badge', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled', show: 'active' },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled', actions: ['restore', 'delete'] },
    ];

    const currentUrl = ref('');
    const oldcurrentUrl = ref((getSavedValue('currentUrl') || ''));
    const currentPage = ref(getSavedValue('currentPage', (v) => parseInt(v, 10)) || 1);
    const currentSearch = ref(getSavedValue('currentSearch') || '');
    const currentStatus = ref(getSavedValue('currentStatus') || 'all');
    const currentRecord = ref(getSavedValue('currentRecord', (v) => parseInt(v, 10)) || 10);

    if (getSavedValue('currentUrl') === props.routeName) {
        currentUrl.value = (getSavedValue('currentUrl') || props.routeName);
    } else {
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

        ['currentPage', 'currentSearch', 'currentStatus', 'currentRecord', 'currentUrl'].forEach((key) => {
            const val = stateRefMap[key as keyof typeof stateRefMap]?.value;
            if (val !== undefined && val !== null) {
                localStorage.setItem(key, String(val));
            }
        });
    });

    const debouncedGetTrashCustomers = debounce((params) => {
        getTrashCustomers(params);
    }, 300);

    const getData = async () => {
        try {
            state.loading = true;

            if (currentRecord.value !== state.search.show_record) {
                state.search.page = 1;
            }
            await debouncedGetTrashCustomers({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentStatus.value = state.search.status;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching customer trash:', error);
        }
    };

    onMounted(async () => {
        if (oldcurrentUrl.value === props.routeName) {
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

        debouncedGetTrashCustomers({ ...state.search });
    });

    function onStateUpdate(newState) {
        Object.assign(state, newState);
    }

    const fetchAllRowsForExport = createTableExportAllRows(`${API_ENDPOINTS.customers}/trash`, () => state);
</script>

<template>
    <Head :title="formatedText(props.routeName)" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar">
                <TopButtons
                    :state="state"
                    :getData="getData"
                    :changeStatus="changeStatus"
                    :deleteRecord="perDeleteBulkRecord"
                    :restoreRecord="restoreBulkRecord"
                    :url="`${props.routeName?.split('.')[0]}`"
                    trash-page
                />
            </div>

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
                        :restore="restoreBulkRecord"
                        actionType="modal"
                        :apiUrl="props.routeName?.split('.')[0]"
                        trash-page
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
