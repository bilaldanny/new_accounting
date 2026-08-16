<script setup lang="ts">
    import { onMounted, ref, watchEffect } from 'vue';
    import { timezone } from '@/routes';
    import TopButtons from '@/components/topButtons.vue';
    import useCommons from '@/composables/common';
    import { Head, usePage } from '@inertiajs/vue3';
    import debounce from '@/utils/debounce';
    import useTimezones from '@/composables/timezone';
    import TheTable from '@/components/theTable.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { createTableExportAllRows } from '@/composables/tableExportList';

    const { props } = usePage();

    const { select_data, getSavedValue, formatedText } = useCommons();

    defineOptions({
        layout: {
            title: 'Timezone Trash',
            subtitle: 'Restore or permanently delete removed timezones',
            breadcrumbs: [
                {
                    title: 'Timezone Management',
                    href: timezone().url,
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
        getTrashTimezones,
        deleteRecord,
        changeOrder,
        checkAll,
        perDeleteBulkRecord,
        restoreBulkRecord,
    } = useTimezones();

    const columns = [
        { key: 'select', label: '', type: 'checkbox', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
        { key: 'count', label: 'S.No', type: 'count', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
        { key: 'name', label: 'Name', type: 'primary', responsive: ['sm', 'md', 'lg'] },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled', actions: ['restore', 'delete'] },
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

    const debouncedGetTrashTimezones = debounce((params) => {
        getTrashTimezones(params);
    }, 300);

    const getData = async () => {
        try {
            if (currentRecord.value !== state.search.show_record) {
                state.search.page = 1;
            }
            await debouncedGetTrashTimezones({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching timezones:', error);
        }
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

        debouncedGetTrashTimezones({ ...state.search });
    });

    const fetchAllRowsForExport = createTableExportAllRows(`${API_ENDPOINTS.timezones}/trash`, () => state);
</script>

<template>
    <Head :title="formatedText(props.routeName)" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar">
                <TopButtons
                    :state="state"
                    type="trash"
                    :getData="getData"
                    :deleteRecord="deleteRecord"
                    :show-filter="false"
                    :url="`${props.routeName?.split('.')[0]}`"
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
                        :restore="restoreBulkRecord"
                        :delete="perDeleteBulkRecord"
                        actionType="modal"
                        :apiUrl="props.routeName?.split('.')[0]"
                        show-export
                        :export-file-name="String(props.routeName ?? 'export').replace(/\./g, '-')"
                        :export-title="formatedText(props.routeName)"
                        :export-all-rows="fetchAllRowsForExport"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
