<script setup lang="ts">
import useCommons from '@/composables/common';

const params = defineProps({
    type: String,
});

const { menusdata } = useCommons();

const colThird = { container: 4, label: 12, wrapper: 12 };
const colFull = { container: 12, label: 12, wrapper: 12 };
const colTwoThirds = { container: 8, label: 12, wrapper: 12 };
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <SelectElement
        name="parent_id"
        :native="false"
        :items="menusdata"
        id="ParentId"
        field-name="ParentId"
        placeholder="Parent"
        label="Parent"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        :can-clear="false"
        :conditions="[['type', [1, 2]]]"
    />

    <SelectElement
        name="type"
        :native="false"
        :search="false"
        :items="[
            { value: 1, label: 'Single Menu' },
            { value: 2, label: 'Dropdown Menu' },
            { value: 3, label: 'Menu Heading' },
        ]"
        id="Type"
        field-name="Type"
        placeholder="Type"
        label="Type"
        rules="required"
        :columns="colThird"
        :floating="false"
        :can-clear="false"
    />

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Name"
        placeholder="enter name*"
        :columns="colThird"
        autocomplete="off"
        rules="required"
    />

    <TextElement
        id="RoutePath"
        field-name="RoutePath"
        name="route_path"
        label="Route Path"
        placeholder="Enter route path*"
        :columns="colThird"
        autocomplete="off"
        rules="required"
    />

    <TextElement
        id="Route"
        field-name="Route"
        name="route_name"
        label="Route"
        placeholder="Enter route"
        :columns="colThird"
        autocomplete="off"
    />

    <TextElement
        id="Icon"
        field-name="Icon"
        name="icon"
        label="Icon"
        placeholder="Enter icon"
        :columns="colThird"
        autocomplete="off"
    />

    <TextElement
        id="SortOrder"
        field-name="SortOrder"
        name="sort_order"
        label="Sort Order"
        placeholder="Enter sort order"
        :columns="colThird"
        autocomplete="off"
        default="0"
        input-type="number"
    />

    <StaticElement name="menu_sort_spacer" tag="div" :columns="colTwoThirds" />

    <StaticElement name="menu_toggles_divider" tag="div" :columns="colFull">
        <div class="menu-form-divider" aria-hidden="true"></div>
    </StaticElement>

    <ToggleElement
        :columns="colThird"
        id="IsActive"
        field-name="IsActive"
        name="is_active"
        label="Is Active"
        :true-value="true"
        :false-value="false"
        :default="true"
    />

    <ToggleElement
        :columns="colThird"
        id="IsHidden"
        field-name="IsHidden"
        name="is_hidden"
        label="Is Hidden"
        :true-value="true"
        :false-value="false"
        :default="false"
    />

    <ToggleElement
        :columns="colThird"
        id="IsPermission"
        field-name="IsPermission"
        name="is_permission"
        label="Is Permission"
        :true-value="true"
        :false-value="false"
        :default="false"
    />
</template>
