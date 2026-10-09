<script setup lang="ts">
    import SimpleApprovalList from '../simple/SimpleApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Purchase Return Approval',
            subtitle: 'Review pending purchase returns and approve them',
            breadcrumbs: [
                {
                    title: 'Purchase Return Approval',
                    href: 'NULL',
                },
            ],
        },
    });

    const columns = [{ key: 'invoice_no', label: 'Return No' }, { key: 'supplier_name', label: 'Supplier' }, { key: 'transaction_date_label', label: 'Date' }, { key: 'formatted_amount', label: 'Amount' }, { key: 'status_label', label: 'Status' }];
</script>

<template>
    <SimpleApprovalList
        endpoint="purchase-return-approvals"
        approve-path="/purchasereturn/approval"
        :columns="columns"
    />
</template>
