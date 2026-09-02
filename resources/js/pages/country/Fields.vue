<script setup lang="ts">
    import { computed } from 'vue';

    const params = defineProps({
        type: String,
        recordId: {
            type: Number,
            default: null,
        },
    });

    const nameRules = computed(() => {
        if (params.recordId) {
            return `required|country_name_unique:${params.recordId}`;
        }

        return 'required|country_name_unique';
    });

    const iso2Rules = computed(() => {
        if (params.recordId) {
            return `required|size:2|country_iso2_unique:${params.recordId}`;
        }

        return 'required|size:2|country_iso2_unique';
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <TextElement
        id="CountryName"
        field-name="CountryName"
        name="name"
        label="Name"
        placeholder="Enter country name"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        :rules="nameRules"
    />

    <TextElement
        id="Iso2"
        field-name="Iso2"
        name="iso2"
        label="ISO2"
        placeholder="e.g. PK"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        :rules="iso2Rules"
        input-type="text"
        :attrs="{ maxlength: 2, style: 'text-transform: uppercase' }"
    />

    <TextElement
        id="Iso3"
        field-name="Iso3"
        name="iso3"
        label="ISO3"
        placeholder="e.g. PAK"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        rules="nullable|size:3"
        input-type="text"
        :attrs="{ maxlength: 3, style: 'text-transform: uppercase' }"
    />

    <TextElement
        id="Phonecode"
        field-name="Phonecode"
        name="phonecode"
        label="Phone Code"
        placeholder="e.g. +92"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
    />

    <TextElement
        id="Capital"
        field-name="Capital"
        name="capital"
        label="Capital"
        placeholder="Enter capital city"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
    />

    <TextElement
        id="Nationality"
        field-name="Nationality"
        name="nationality"
        label="Nationality"
        placeholder="e.g. Pakistani"
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
