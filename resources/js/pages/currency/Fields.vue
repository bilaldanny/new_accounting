<script setup lang="ts">
    import { computed } from 'vue';

    const params = defineProps({
        type: String,
        recordId: {
            type: Number,
            default: null,
        },
    });

    const codeRules = computed(() => {
        if (params.recordId) {
            return `required|size:3|currency_code_unique:${params.recordId}`;
        }

        return 'required|size:3|currency_code_unique';
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <TextElement
        id="CurrencyName"
        field-name="CurrencyName"
        name="currency_name"
        label="Name"
        placeholder="Enter currency name"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        rules="required"
    />

    <TextElement
        id="Code"
        field-name="Code"
        name="code"
        label="Code"
        placeholder="e.g. USD"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        :rules="codeRules"
        input-type="text"
        :attrs="{ maxlength: 3, style: 'text-transform: uppercase' }"
    />

    <TextElement
        id="Symbol"
        field-name="Symbol"
        name="symbol"
        label="Symbol"
        placeholder="e.g. $"
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
