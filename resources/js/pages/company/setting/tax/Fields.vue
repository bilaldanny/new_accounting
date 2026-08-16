<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { computed, onMounted } from 'vue';

    const params = defineProps({
        type: { type: String, default: 'add' },
        recordId: { type: Number, default: null },
        formData: { type: Object, default: () => ({}) },
        formRef: { type: Object, default: null },
        companyId: { type: [String, Number], default: '' },
        isSuperadmin: { type: Boolean, default: false },
    });

    const { fetchCompany, companiesdata } = useCommons();

    const showCompanyField = computed(() => params.isSuperadmin);

    onMounted(async () => {
        if (params.type !== 'edit' && params.companyId && !params.isSuperadmin) {
            params.formRef?.update?.({
                company_id: params.companyId,
                type: 0,
                status: true,
            });
        }

        if (showCompanyField.value) {
            await fetchCompany();
        }
    });
</script>

<template>
    <TextElement name="id" hidden="true" />
    <TextElement name="type" default="0" hidden="true" />
    <TextElement v-if="params.type === 'edit'" name="_method" default="PUT" hidden="true" />

    <TextElement
        v-if="!showCompanyField"
        name="company_id"
        :default="String(companyId || '')"
        hidden="true"
    />

    <SelectElement
        v-if="showCompanyField"
        name="company_id"
        :native="false"
        :items="companiesdata"
        label="Company"
        placeholder="Select company"
        :columns="{ container: 6, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        rules="required"
    />

    <TextElement
        name="name"
        label="Name"
        placeholder="Enter tax name"
        :columns="{ container: 6, label: 12, wrapper: 12 }"
        rules="required|min:3|max:200"
    />

    <TextElement
        name="percentage"
        input-type="number"
        label="Tax Rate %"
        placeholder="Enter tax rate"
        :columns="{ container: 6, label: 12, wrapper: 12 }"
        rules="required|numeric|min:0"
    />

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="{ container: 6, label: 12, wrapper: 12 }"
        name="status"
        label="Active"
        :true-value="true"
        :false-value="false"
        :default="true"
    />
</template>
