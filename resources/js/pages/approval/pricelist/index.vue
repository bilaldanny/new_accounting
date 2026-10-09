<script setup lang="ts">
    import SimpleApprovalList from '../simple/SimpleApprovalList.vue';

    defineOptions({
        layout: {
            title: 'Price List Approval',
            subtitle: 'Review pending price lists and approve them',
            breadcrumbs: [
                {
                    title: 'Price List Approval',
                    href: 'NULL',
                },
            ],
        },
    });

    const columns = [{ key: 'brand_name', label: 'Brand' }, { key: 'contact_name', label: 'Customer / Supplier' }, { key: 'date_label', label: 'Date' }, { key: 'discount', label: 'Discount %' }, { key: 'line_count', label: 'Products' }];
</script>

<template>
    <SimpleApprovalList
        endpoint="pricelist-approvals"
        approve-path="/pricelist/approval"
        :columns="columns"
    />
</template>
