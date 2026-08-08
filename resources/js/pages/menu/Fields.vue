<script setup lang="ts">
import useCommons from '@/composables/common';

    const params = defineProps({
        type: String
    });

    const {menusdata} = useCommons();
</script>

<template>

    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true"/>

    <SelectElement
        name="parent_id"
        :native="false"
        :items="menusdata"
        id="ParentId"
        field-name="ParentId"
        placeholder="Parent"
        label="Parent"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :conditions="[
            ['type', [1, 2]],
        ]"
    />

    <SelectElement
        name="type"
        :native="false"
        :items="[
            { value: 1, label: 'Single Menu' },
            { value: 2, label: 'Dropdown Menu' },
            { value: 3, label: 'Menu Heading' }
        ]"
        id="Type"
        field-name="Type"
        placeholder="Type"
        label="Type"
        rules="required"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        :floating="false"
        :can-clear="false"
    />

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Name"
        placeholder="Enter name"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        rules="required"
    />

    <TextElement
        id="RoutePath"
        field-name="RoutePath"
        name="route_path"
        label="Route Path"
        placeholder="Enter route path"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        rules="required"
    />

    <TextElement
        id="Route"
        field-name="Route"
        name="route_name"
        label="Route"
        placeholder="Enter route"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
    />

    <TextElement
        id="Icon"
        field-name="Icon"
        name="icon"
        label="Icon"
        placeholder="Enter icon"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
    />

    <TextElement
        id="SortOrder"
        field-name="SortOrder"
        name="sort_order"
        label="Sort Order"
        placeholder="Enter sort order"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        default="0"
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

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        id="IsHidden"
        field-name="IsHidden"
        name="is_hidden"
        label="Is Hidden"
        :true-value="true"
        :false-value="false"
        :default="false"
    />

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        id="IsPermission"
        field-name="IsPermission"
        name="is_permission"
        label="Is Permission"
        :true-value="true"
        :false-value="false"
        :default="false"
    />
</template>
