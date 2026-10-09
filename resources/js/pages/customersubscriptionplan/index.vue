<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import TheTable from '@/components/theTable.vue';
    import TopButtons from '@/components/topButtons.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useCustomerSubscriptionPlans from '@/composables/customerSubscriptionPlan';
    import { createTableExportAllRows } from '@/composables/tableExportList';
    import debounce from '@/utils/debounce';

    defineOptions({
        layout: {
            title: 'Subscription Plans',
            subtitle: 'What you sell your own customers (memberships, contracts, your own SaaS, ...)',
            breadcrumbs: [
                { title: 'Subscription Plans', href: 'NULL' },
            ],
        },
    });

    const { props } = usePage();

    const { state, getCustomerSubscriptionPlans, deleteRecord, changeOrder, checkAll } = useCustomerSubscriptionPlans();
    const { select_data, formatedText } = useCommons();

    const columns = [
        { key: 'name', label: 'Plan', type: 'primary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'billing_cycle', label: 'Cycle', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'price', label: 'Price', type: 'secondary', responsive: ['xs', 'sm', 'md', 'lg'] },
        { key: 'subscriptions_count', label: 'Subscribers', type: 'secondary', responsive: ['md', 'lg'] },
        { key: 'is_active', label: 'Status', type: 'badge', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled', show: 'active' },
        { key: 'action', label: 'Action', type: 'action', responsive: ['xs', 'sm', 'md', 'lg'], sorting: 'disabled', actions: ['edit', 'delete'] },
    ];

    const debouncedGetPlans = debounce((params) => {
        getCustomerSubscriptionPlans(params);
    }, 300);

    const getData = async () => {
        state.loading = true;
        await debouncedGetPlans({ ...state.search });
    };

    onMounted(() => {
        debouncedGetPlans({ ...state.search });
    });

    function onStateUpdate(newState) {
        Object.assign(state, newState);
    }

    const fetchAllRowsForExport = createTableExportAllRows(API_ENDPOINTS.customerSubscriptionPlans, () => state);
</script>

<template>
    <Head :title="formatedText(props.routeName)" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar">
                <TopButtons
                    :state="state"
                    :getData="getData"
                    :deleteRecord="deleteRecord"
                    :url="`${props.routeName?.split('.')[0]}`"
                    add-href="/customersubscriptionplans/add"
                    :show-filter="false"
                    :show-import="false"
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
                        :delete="deleteRecord"
                        actionType="link"
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
