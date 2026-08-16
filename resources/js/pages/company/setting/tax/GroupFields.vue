<script setup lang="ts">
    import useTaxes from '@/composables/tax';
    import useCommons from '@/composables/common';
    import { computed, onMounted, watch } from 'vue';

    const params = defineProps({
        type: { type: String, default: 'add' },
        recordId: { type: Number, default: null },
        formData: { type: Object, default: () => ({}) },
        formRef: { type: Object, default: null },
        companyId: { type: [String, Number], default: '' },
        isSuperadmin: { type: Boolean, default: false },
    });

    const { fetchCompany, companiesdata } = useCommons();
    const { taxesdata, fetchTaxOptions } = useTaxes();

    const showCompanyField = computed(() => params.isSuperadmin);

    const selectedCompanyId = computed(() =>
        String(params.formData?.company_id || params.companyId || ''),
    );

    const taxOptions = computed(() =>
        taxesdata.value.map((tax) => ({
            value: tax.id,
            label: `${tax.name} (${tax.percentage}%)`,
        })),
    );

    async function loadTaxOptions(companyId: string) {
        if (!companyId) {
            taxesdata.value = [];

            return;
        }

        await fetchTaxOptions(companyId);
    }

    onMounted(async () => {
        if (params.type !== 'edit' && params.companyId && !params.isSuperadmin) {
            params.formRef?.update?.({
                company_id: params.companyId,
                type: 1,
                status: true,
                sub_tax: [],
            });
        }

        if (showCompanyField.value) {
            await fetchCompany();
        }

        if (selectedCompanyId.value) {
            await loadTaxOptions(selectedCompanyId.value);
        }
    });

    watch(selectedCompanyId, async (companyId, previousCompanyId) => {
        if (companyId === previousCompanyId) {
            return;
        }

        if (params.formRef && companyId !== previousCompanyId && previousCompanyId !== '') {
            params.formRef?.update?.({ sub_tax: [] });
        }

        await loadTaxOptions(companyId);
    });
</script>

<template>
    <TextElement name="id" hidden="true" />
    <TextElement name="type" default="1" hidden="true" />
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
        placeholder="Enter tax group name"
        :columns="{ container: 6, label: 12, wrapper: 12 }"
        rules="required|min:3|max:200"
    />

    <SelectElement
        name="sub_tax"
        :native="false"
        :items="taxOptions"
        label="Taxes"
        placeholder="Select taxes"
        :columns="{ container: 6, label: 12, wrapper: 12 }"
        label-prop="label"
        value-prop="value"
        :search="true"
        :floating="false"
        :multiple="true"
        :disabled="!selectedCompanyId"
        rules="required"
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
