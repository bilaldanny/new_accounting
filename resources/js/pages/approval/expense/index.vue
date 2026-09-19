<script setup lang="ts">
    import VoucherApprovalList from '../voucher/VoucherApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Expense Approval',
            subtitle: 'Review pending expenses, then view, approve, or reject them',
            breadcrumbs: [
                {
                    title: 'Expense Approval',
                    href: 'NULL',
                },
            ],
        },
    });
</script>

<template>
    <VoucherApprovalList
        family="expense"
        api-url="expense"
        view-base="/expense/approval"
        export-name="expense-approval"
    />
</template>
