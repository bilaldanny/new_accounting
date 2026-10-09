<script setup lang="ts">
    import SimpleApprovalList from '../simple/SimpleApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Cash Collection Approval',
            subtitle: 'Review pending cash collections and stamp them as reviewed',
            breadcrumbs: [
                {
                    title: 'Cash Collection Approval',
                    href: 'NULL',
                },
            ],
        },
    });

    const columns = [{ key: 'reference', label: 'Reference' }, { key: 'contact_name', label: 'Customer' }, { key: 'collected_on', label: 'Collected On' }, { key: 'amount', label: 'Amount' }, { key: 'approved_at', label: 'Reviewed At' }];
</script>

<template>
    <SimpleApprovalList
        endpoint="cash-collection-approvals"
        approve-path="/cashcollection/approval"
        :columns="columns"
    />
</template>
