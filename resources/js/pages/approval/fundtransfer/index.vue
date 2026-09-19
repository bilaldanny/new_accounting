<script setup lang="ts">
    import VoucherApprovalList from '../voucher/VoucherApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Fund Transfer Approval',
            subtitle: 'Review pending fund transfers, then view, approve, or reject them',
            breadcrumbs: [
                {
                    title: 'Fund Transfer Approval',
                    href: 'NULL',
                },
            ],
        },
    });
</script>

<template>
    <VoucherApprovalList
        family="fundtransfer"
        api-url="fundtransfer"
        view-base="/fundtransfer/approval"
        export-name="fund-transfer-approval"
    />
</template>
