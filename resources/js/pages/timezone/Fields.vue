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
            return `required|timezone_name_unique:${params.recordId}`;
        }

        return 'required|timezone_name_unique';
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Name"
        placeholder="e.g. Asia/Karachi"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        :rules="nameRules"
    />
</template>
