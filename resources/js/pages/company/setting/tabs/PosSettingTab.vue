<script setup lang="ts">
    import { computed } from 'vue';
    import { colThird, posUnitItems } from './constants';

    const props = defineProps({
        formData: { type: Object, required: true },
    });

    const posUnitDisabled = computed(() => {
        return !props.formData?.company_id || !props.formData?.branch_id;
    });
</script>

<template>
    <SelectElement
        name="default_pos_unit"
        :native="false"
        :items="posUnitItems"
        label="Default Unit"
        placeholder="Select default unit"
        :columns="colThird"
        :search="false"
        :floating="false"
        :can-clear="false"
        :disabled="posUnitDisabled"
        info="Choose whether POS uses converted stock or asks for unit before adding to cart."
    />
</template>
