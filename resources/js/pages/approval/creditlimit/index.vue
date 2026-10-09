<script setup lang="ts">
    import SimpleApprovalList from '../simple/SimpleApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Credit Limit Approval',
            subtitle: 'Review credit limit change requests, then approve or reject them',
            breadcrumbs: [
                {
                    title: 'Credit Limit Approval',
                    href: 'NULL',
                },
            ],
        },
    });

    const columns = [{ key: 'contact_name', label: 'Contact' }, { key: 'current_limit', label: 'Current Limit' }, { key: 'requested_limit', label: 'Requested Limit' }, { key: 'reason', label: 'Reason' }, { key: 'requested_by_name', label: 'Requested By' }];
</script>

<template>
    <SimpleApprovalList
        endpoint="credit-limit-approvals"
        approve-path="/creditlimit/approval"
        reject-path="/creditlimit/reject"
        :columns="columns"
    />
</template>
