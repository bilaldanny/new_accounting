<script setup lang="ts">
    import SimpleApprovalList from '../simple/SimpleApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Stock Adjustment Approval',
            subtitle: 'Review pending stock adjustments and approve them',
            breadcrumbs: [
                {
                    title: 'Stock Adjustment Approval',
                    href: 'NULL',
                },
            ],
        },
    });

    const columns = [{ key: 'invoice_no', label: 'Adjustment No' }, { key: 'branch_name', label: 'Branch' }, { key: 'adjustment_type_label', label: 'Type' }, { key: 'transaction_date_label', label: 'Date' }, { key: 'status_label', label: 'Status' }];
</script>

<template>
    <SimpleApprovalList
        endpoint="stock-adjustment-approvals"
        approve-path="/stockadjustment/approval"
        :columns="columns"
    />
</template>
