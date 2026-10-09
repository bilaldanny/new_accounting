<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { Columns3 } from '@lucide/vue';
    import { onMounted, ref, watchEffect, computed } from 'vue';
    import TheFilter from '@/components/theFilter.vue';
    import TheTable from '@/components/theTable.vue';
    import TopButtons from '@/components/topButtons.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useOpportunities from '@/composables/opportunity';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import debounce from '@/utils/debounce';

    defineOptions({
        layout: {
            title: 'Opportunities',
            subtitle: 'Manage deals in progress',
            breadcrumbs: [
                {
                    title: 'Opportunities',
                    href: 'NULL',
                },
            ],
        },
    });

    const { props } = usePage();

    const {
        state,
        getOpportunities,
        deleteRecord,
        changeOrder,
        checkAll,
    } = useOpportunities();

    const { select_data, getSavedValue, formatedText, fetchCompany, companiesdata } = useCommons();

    const authUser = computed(() => props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const showCompanyFilter = computed(() => isSuperadmin.value);

    const columns = computed(() => [
        ...(isSuperadmin.value ? [
            { key: 'company_name', label: 'Company', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        ] : []),
        { key: 'name', label: 'Opportunity', type: 'primary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'deal_value', label: 'Deal Value', type: 'secondary', format: 'number', responsive: ['sm', 'md', 'lg'], emptyDisplay: '0' },
        { key: 'expected_closing_date', label: 'Expected Close', type: 'secondary', responsive: ['md', 'lg'], emptyDisplay: '-' },
        { key: 'pipeline_stage_name', label: 'Stage', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'assignee_name', label: 'Assigned To', type: 'secondary', responsive: ['md', 'lg'], emptyDisplay: 'Unassigned' },
        { key: 'status', label: 'Status', type: 'badge', show: 'lead_status', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled' },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting:'disabled', actions: ['view', 'edit', 'delete']},
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

    const debouncedGetOpportunities = debounce((params) => {
        getOpportunities(params);
    }, 300);

    const getData = async () => {
        try {
            state.loading = true;

            if(currentRecord.value !== state.search.show_record){
                state.search.page = 1;
            }

            await debouncedGetOpportunities({ ...state.search });
            currentPage.value = state.search.page;
            currentSearch.value = state.search.search;
            currentStatus.value = state.search.status;
            currentRecord.value = state.search.show_record;
        } catch (error) {
            console.error('Error fetching opportunities:', error);
        }
    };

    onMounted(async () => {
        state.search.company_id = authUser.value?.company_id ?? '';

        if (showCompanyFilter.value) {
            await fetchCompany();
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

        debouncedGetOpportunities({ ...state.search });
    });

    function onStateUpdate(newState) {
        Object.assign(state, newState)
    }

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.opportunities, () => state);

    const filterOpen = ref(false);

    function clearSearch() {
        state.search.status = 'all';
        state.search.search = '';
        state.search.show_record = 10;
        state.search.page = 1;
        state.search.company_id = authUser.value?.company_id ?? '';
        getData();
    }
</script>

<template>
    <Head :title="formatedText(props.routeName)" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center gap-2">
                <Link href="/pipeline" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1">
                    <Columns3 class="h-4 w-4" />
                    Pipeline Board
                </Link>
                <div class="flex-grow-1">
                    <TopButtons
                        :state="state"
                        :filter-open="filterOpen"
                        :getData="getData"
                        :deleteRecord="deleteRecord"
                        :url="`${props.routeName?.split('.')[0]}`"
                        add-href="/opportunities/add"
                        :show-filter="showCompanyFilter"
                        :show-import="false"
                        @toggle-filter="filterOpen = !filterOpen"
                    />
                </div>
            </div>

            <TheFilter v-if="showCompanyFilter" v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="opportunity-filter-company">Company</label>
                    <select
                        id="opportunity-filter-company"
                        class="form-select form-select-sm"
                        v-model="state.search.company_id"
                    >
                        <option value="">All</option>
                        <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                            {{ company.text ?? company.name }}
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
                        :delete="deleteRecord"
                        actionType="link"
                        :viewRoute="(id: number) => `/opportunities/${id}/view`"
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
