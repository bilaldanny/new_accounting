<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import TheFilter from '@/components/theFilter.vue';
    import TheTable from '@/components/theTable.vue';
    import TopButtons from '@/components/topButtons.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useLowStock from '@/composables/lowstock';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import debounce from '@/utils/debounce';

    defineOptions({
        layout: {
            title: 'Low Stock',
            subtitle: 'Products at or below their alert quantity',
            breadcrumbs: [
                {
                    title: 'Products',
                    href: 'NULL',
                },
                {
                    title: 'Low Stock',
                    href: 'NULL',
                },
            ],
        },
    });

    const { props } = usePage();

    const { state, getLowStock, changeOrder, checkAll, select_data } = useLowStock();

    const { formatedText, fetchCompany, fetchBranch, companiesdata, branchesdata } = useCommons();

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
    const branchFilterDisabled = computed(() => showCompanyFilter.value && !state.search.company_id);

    const columns = computed(() => [
        ...(isSuperadmin.value ? [
            { key: 'company_name', label: 'Company', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        ] : []),
        { key: 'branch_name', label: 'Branch', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'name', label: 'Product', type: 'primary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'sku', label: 'SKU', type: 'secondary', responsive: ['sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'category_name', label: 'Category', type: 'secondary', responsive: ['md', 'lg'], emptyDisplay: '-' },
        { key: 'brand_name', label: 'Brand', type: 'secondary', responsive: ['md', 'lg'], emptyDisplay: '-' },
        { key: 'unit_name', label: 'Unit', type: 'secondary', responsive: ['sm', 'md', 'lg'], emptyDisplay: '-' },
        { key: 'stock', label: 'In Stock', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'alert_qty', label: 'Alert Qty', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'shortage', label: 'Shortage', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'] },
    ]);

    const debouncedGetLowStock = debounce((params) => {
        getLowStock(params);
    }, 300);

    const getData = async () => {
        try {
            state.loading = true;
            await debouncedGetLowStock({ ...state.search });
        } catch (error) {
            console.error('Error fetching low stock products:', error);
        }
    };

    async function handleCompanyFilterChange(companyId: string | number | null | undefined) {
        state.search.branch_id = '';
        await fetchBranch(companyId);
    }

    onMounted(async () => {
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';

        if (showCompanyFilter.value) {
            await fetchCompany();
        }

        if (isCompanyadmin.value && authUser.value?.company_id) {
            await fetchBranch(authUser.value.company_id);
        }

        debouncedGetLowStock({ ...state.search });
    });

    function onStateUpdate(newState) {
        Object.assign(state, newState);
    }

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.lowStock, () => state);

    const filterOpen = ref(false);

    function clearSearch() {
        state.search.search = '';
        state.search.show_record = 10;
        state.search.page = 1;
        state.search.company_id = authUser.value?.company_id ?? '';
        state.search.branch_id = authUser.value?.branch_id ?? '';
        getData();
    }
</script>

<template>
    <Head title="Low Stock" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar">
                <TopButtons
                    :state="state"
                    :filter-open="filterOpen"
                    :getData="getData"
                    :url="`${props.routeName?.split('.')[0]}`"
                    :show-filter="true"
                    :show-add="false"
                    :show-status="false"
                    :show-bulk-icons="false"
                    :show-import="false"
                    @toggle-filter="filterOpen = !filterOpen"
                />
            </div>

            <TheFilter v-model:open="filterOpen" :loading="state.loading" @clear="clearSearch" @search="getData">
                <div v-if="showCompanyFilter" class="col-md-4 col-lg-3 admin-filter-field">
                    <label class="form-label" for="lowstock-filter-company">Company</label>
                    <select
                        id="lowstock-filter-company"
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
                    <label class="form-label" for="lowstock-filter-branch">Branch</label>
                    <select
                        id="lowstock-filter-branch"
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
                        actionType="link"
                        :apiUrl="props.routeName?.split('.')[0]"
                        show-export
                        export-file-name="low-stock"
                        :export-title="formatedText(props.routeName)"
                        :export-all-rows="fetchAllRowsForExport"
                        @update:state="onStateUpdate"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
