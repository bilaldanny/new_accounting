<script setup lang="ts">
    import SimpleApprovalList from '../simple/SimpleApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Stock Transfer Approval',
            subtitle: 'Review pending stock transfers and approve them',
            breadcrumbs: [
                {
                    title: 'Stock Transfer Approval',
                    href: 'NULL',
                },
            ],
        },
    });

    const columns = [{ key: 'invoice_no', label: 'Transfer No' }, { key: 'branch_name', label: 'From' }, { key: 'tobranch_name', label: 'To' }, { key: 'transaction_date_label', label: 'Date' }, { key: 'status_label', label: 'Status' }];
</script>

<template>
    <SimpleApprovalList
        endpoint="stock-transfer-approvals"
        approve-path="/stocktransfer/approval"
        :columns="columns"
    />
</template>
