<script setup lang="ts">
    const params = defineProps({
        type: String
    });
</script>

<template>

    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true"/>

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Name"
        placeholder="Enter department name"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        rules="required"
    />

    <TextElement
        id="CompanyId"
        field-name="CompanyId"
        name="company_id"
        label="Company ID"
        placeholder="Enter company ID"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
    />

    <TextElement
        id="BranchId"
        field-name="BranchId"
        name="branch_id"
        label="Branch ID"
        placeholder="Enter branch ID"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
    />

    <StaticElement tag="br" name="element" />

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        id="Active"
        field-name="Active"
        name="active"
        label="Active"
        :true-value="true"
        :false-value="false"
        :default="true"
    />
</template>
