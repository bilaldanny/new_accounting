<script setup lang="ts">

    import { approvalFields, colHalf, colFull } from './constants';

</script>



<template>

    <StaticElement name="section_approval_intro" :columns="colFull">

        <p class="company-setting-section-help">

            Enable or disable approval steps for transactions and related workflows.

        </p>

    </StaticElement>



    <ToggleElement

        v-for="field in approvalFields"

        :key="field.name"

        :name="field.name"

        :label="field.label"

        :info="field.hint"

        :columns="colHalf"

        :labels="{ 1: 'Yes', 0: 'No' }"

        :true-value="true"

        :false-value="false"

    />

</template>


