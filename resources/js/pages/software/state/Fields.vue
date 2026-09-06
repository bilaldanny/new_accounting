<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { computed, onMounted } from 'vue';

    const params = defineProps({
        type: String,
        recordId: {
            type: Number,
            default: null,
        },
        formData: {
            type: Object,
            default: () => ({}),
        },
    });

    const { fetchCountry, countriesdata } = useCommons();

    const nameRules = computed(() => {
        if (params.recordId) {
            return `required|state_name_unique:${params.recordId}`;
        }

        return 'required|state_name_unique';
    });

    onMounted(async () => {
        await fetchCountry();
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <SelectElement
        name="country_id"
        :native="false"
        :items="countriesdata"
        id="CountryId"
        field-name="CountryId"
        placeholder="Select country"
        label="Country"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        rules="required"
    />

    <TextElement
        id="StateName"
        field-name="StateName"
        name="name"
        label="Name"
        placeholder="Enter state name"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        :rules="nameRules"
    />

    <TextElement
        id="Iso2"
        field-name="Iso2"
        name="iso2"
        label="ISO2"
        placeholder="e.g. PB"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        input-type="text"
        :attrs="{ style: 'text-transform: uppercase' }"
    />

    <TextElement
        id="Type"
        field-name="Type"
        name="type"
        label="Type"
        placeholder="e.g. province"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
    />

    <StaticElement tag="br" name="element" />

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        id="Flag"
        field-name="Flag"
        name="flag"
        label="Is Active"
        :true-value="true"
        :false-value="false"
        :default="true"
    />
</template>
