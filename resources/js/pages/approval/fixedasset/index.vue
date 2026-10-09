<script setup lang="ts">
    import SimpleApprovalList from '../simple/SimpleApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Fixed Asset Approval',
            subtitle: 'Revaluations, impairments and disposals wait here; approving one posts it to the ledger',
            breadcrumbs: [
                {
                    title: 'Fixed Asset Approval',
                    href: 'NULL',
                },
            ],
        },
    });

    const columns = [{ key: 'type', label: 'Request' }, { key: 'asset', label: 'Asset' }, { key: 'event_date', label: 'Date' }, { key: 'detail', label: 'Detail' }, { key: 'reason', label: 'Reason' }];
</script>

<template>
    <SimpleApprovalList
        endpoint="asset-approvals"
        approve-path="/assetapproval"
        reject-path="/assetapproval/reject"
        :columns="columns"
    />
</template>
