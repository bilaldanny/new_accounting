<script setup lang="ts">
    import VoucherApprovalList from '../voucher/VoucherApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Credit/Debit Note Approval',
            subtitle: 'Review pending credit/debit notes, then view, approve, or reject them',
            breadcrumbs: [
                {
                    title: 'Credit/Debit Note Approval',
                    href: 'NULL',
                },
            ],
        },
    });
</script>

<template>
    <VoucherApprovalList
        family="creditdebitnote"
        api-url="creditdebitnote"
        view-base="/creditdebitnote/approval"
        export-name="creditdebitnote-approval"
    />
</template>
