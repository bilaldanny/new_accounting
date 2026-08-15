<script setup lang="ts">

    import { computed, onMounted, ref, watchEffect } from 'vue';
    import { branch } from '@/routes';
    import TopButtons from '@/components/topButtons.vue';
    import useCommons from '@/composables/common';
    import { Head, usePage } from '@inertiajs/vue3';
    import debounce from '@/utils/debounce';
    import useBranches from '@/composables/branch';
    import TheTable from '@/components/theTable.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { createTableExportAllRows } from '@/composables/tableExportList';

    const { props } = usePage();

    const {select_data, getSavedValue, formatedText} = useCommons();

    defineOptions({
        layout: {
            title: 'Branch Trash',
            subtitle: 'Restore or permanently delete removed branches',
            breadcrumbs: [
                {
                    title: 'Branch Management',
                    href: branch().url,
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
        getTrashBranches,
        changeStatus,
        deleteRecord,
        changeOrder,
        checkAll,
        perDeleteBulkRecord,
        restoreBulkRecord,
    } = useBranches();

    const columns = computed(() => {
        const cols = [
            { key: 'select', label: '', type: 'checkbox', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
            { key: 'count', label: 'S.No', type: 'count', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
        ];

        const authUser = props.auth?.user as { rolename?: string } | null;
        const isSuperadmin = String(authUser?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin';

        if (isSuperadmin) {
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
            { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled', actions: ['restore', 'delete'] },
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

    const debouncedGetTrashBranches = debounce((params) => {
        getTrashBranches(params);
    }, 300);

    const getData = async () => {
        try {
            if(currentRecord.value !== state.search.show_record){
                state.search.page = 1;
            }
            await debouncedGetTrashBranches({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentStatus.value = state.search.status;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching branches:', error);
        }
    };

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

        const authUser = props.auth?.user as { company_id?: number | string | null; rolename?: string } | null;
        const isSuperadmin = String(authUser?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin';

        if (!isSuperadmin && authUser?.company_id) {
            state.search.company_id = authUser.company_id;
        }

        currentPage.value = state.search.page;
        currentSearch.value = state.search.search;
        currentStatus.value = state.search.status;
        currentRecord.value = state.search.show_record;

        debouncedGetTrashBranches({ ...state.search });
    });

    const fetchAllRowsForExport = createTableExportAllRows(`${API_ENDPOINTS.branches}/trash`, () => state);

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
                    :changeStatus="changeStatus"
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
                        :changeStatus="changeStatus"
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
