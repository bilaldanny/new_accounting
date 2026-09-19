<script setup lang="ts">
    import VoucherApprovalList from '../voucher/VoucherApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Deposit Approval',
            subtitle: 'Review pending deposits, then view, approve, or reject them',
            breadcrumbs: [
                {
                    title: 'Deposit Approval',
                    href: 'NULL',
                },
            ],
        },
    });
</script>

<template>
    <VoucherApprovalList
        family="deposit"
        api-url="deposit"
        view-base="/deposit/approval"
        export-name="deposit-approval"
    />
</template>
