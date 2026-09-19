<script setup lang="ts">
    import VoucherApprovalList from '../voucher/VoucherApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Payment Approval',
            subtitle: 'Review pending payments, then view, approve, or reject them',
            breadcrumbs: [
                {
                    title: 'Payment Approval',
                    href: 'NULL',
                },
            ],
        },
    });
</script>

<template>
    <VoucherApprovalList
        family="payment"
        api-url="acpayment"
        view-base="/acpayment/approval"
        export-name="payment-approval"
    />
</template>
