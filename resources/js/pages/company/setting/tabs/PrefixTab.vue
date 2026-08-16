<script setup lang="ts">

    import { colThird, prefixFields } from './constants';

</script>



<template>

    <TextElement

        v-for="field in prefixFields"

        :key="field.name"

        :name="field.name"

        :label="field.label"

        :placeholder="field.placeholder"

        :columns="colThird"

    />

</template>


