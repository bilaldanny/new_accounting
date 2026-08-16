<script setup lang="ts">

    import useCommons from '@/composables/common';

    import { onMounted, watch } from 'vue';

    import ApprovalTab from './tabs/ApprovalTab.vue';

    import BusinessSettingTab from './tabs/BusinessSettingTab.vue';

    import PosSettingTab from './tabs/PosSettingTab.vue';

    import PrefixTab from './tabs/PrefixTab.vue';



    const props = defineProps({

        activeTab: { type: String, default: 'business' },

        formData: { type: Object, default: () => ({}) },

        logoUrl: { type: String, default: '' },

        currenciesdata: { type: Array, default: () => [] },

        timezonesdata: { type: Array, default: () => [] },

        showCompanyFilter: { type: Boolean, default: false },

        isCompanyadmin: { type: Boolean, default: false },

        formRef: { type: Object, default: null },

    });



    const emit = defineEmits<{

        'company-change': [value: string | number];

    }>();



    const { fetchCompany, companiesdata } = useCommons();



    onMounted(async () => {

        if (props.showCompanyFilter) {

            await fetchCompany();

        }

    });



    watch(

        () => props.formData?.company_id,

        (companyId, previousCompanyId) => {

            if (!props.showCompanyFilter) {

                return;

            }



            if (String(companyId ?? '') === String(previousCompanyId ?? '')) {

                return;

            }



            if (companyId === null || companyId === undefined || companyId === '') {

                return;

            }



            emit('company-change', companyId as string | number);

        },

    );

</script>



<template>

    <TextElement name="id" hidden="true" />

    <TextElement name="_method" default="PUT" hidden="true" />



    <TextElement

        v-if="isCompanyadmin"

        name="company_id"

        hidden="true"

    />



    <BusinessSettingTab

        v-if="activeTab === 'business'"

        :logo-url="logoUrl"

        :currenciesdata="currenciesdata"

        :timezonesdata="timezonesdata"

        :companiesdata="companiesdata"

        :show-company-filter="showCompanyFilter"

        :form-data="formData"

        :form-ref="formRef"

    />



    <PrefixTab v-else-if="activeTab === 'prefix'" />



    <ApprovalTab v-else-if="activeTab === 'approval'" />



    <PosSettingTab v-else-if="activeTab === 'posSetting'" />

</template>


