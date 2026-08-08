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
        placeholder="Enter role name"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        rules="required"
    />

    <StaticElement tag="br" name="element" />

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        id="IsActive"
        field-name="IsActive"
        name="is_active"
        label="Is Active"
        :true-value="true"
        :false-value="false"
        :default="true"
    />
</template>
