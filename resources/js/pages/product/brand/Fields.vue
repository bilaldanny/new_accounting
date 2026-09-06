<script setup lang="ts">

    import useCommons from '@/composables/common';

    import { computed, onMounted } from 'vue';

    import { usePage } from '@inertiajs/vue3';

    import { PriceTagAlt, SliderAlt, Store } from '@boxicons/vue';



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

        formRef: {

            type: Object,

            default: null,

        },

    });



    const page = usePage();



    const colThird = { container: 4, label: 12, wrapper: 12 };

    const colFull = { container: 12, label: 12, wrapper: 12 };



    const normalizeRoleName = (name: unknown): string =>

        String(name ?? '').toLowerCase().replace(/\s+/g, '');



    const authUser = computed(() => page.props.auth?.user as {

        rolename?: string;

        company_id?: number | string | null;

    } | null);



    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));

    const isSuperadmin = computed(() => roleName.value === 'superadmin');

    const showCompanyField = computed(() => isSuperadmin.value);

    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);



    const { fetchCompany, companiesdata } = useCommons();



    const nameRules = computed(() => {

        if (params.recordId) {

            return `required|min:3|max:200|brand_name_unique:${params.recordId}`;

        }



        return 'required|min:3|max:200|brand_name_unique';

    });



    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));



    function applyScopedDefaults() {

        if (isSuperadmin.value) {

            return;

        }



        if (authUser.value?.company_id) {

            params.formRef?.update?.({ company_id: authUser.value.company_id });

        }

    }



    onMounted(async () => {

        applyScopedDefaults();



        if (showCompanyField.value) {

            await fetchCompany();

        }

    });

</script>



<template>

    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />



    <TextElement

        v-if="showHiddenCompanyField"

        name="company_id"

        hidden="true"

    />



    <StaticElement v-if="showCompanyField" name="section_scope" :columns="colFull">

        <div class="company-section-header company-section-header-primary">

            <span class="company-section-icon company-section-icon-primary">

                <Store size="sm" />

            </span>

            <div>

                <h6 class="company-section-title mb-0">Company Scope</h6>

                <p class="company-section-subtitle mb-0">Assign this brand to the correct company workspace</p>

            </div>

        </div>

    </StaticElement>



    <SelectElement

        v-if="showCompanyField"

        name="company_id"

        :native="false"

        :items="companiesdata"

        id="CompanyId"

        field-name="CompanyId"

        placeholder="Select company"

        label="Company"

        :columns="colThird"

        label-prop="text"

        value-prop="id"

        :search="true"

        :floating="false"

        :can-clear="false"

        :rules="companyRules"

        info="Required. Brands are scoped to a single company."

    />



    <StaticElement name="section_details" :columns="colFull">

        <div class="company-section-header company-section-header-indigo company-section-header-spaced">

            <span class="company-section-icon company-section-icon-indigo">

                <PriceTagAlt size="sm" />

            </span>

            <div>

                <h6 class="company-section-title mb-0">Brand Details</h6>

                <p class="company-section-subtitle mb-0">Name shown on products, catalogs, and purchase documents</p>

            </div>

        </div>

    </StaticElement>



    <TextElement

        id="Name"

        field-name="Name"

        name="name"

        label="Brand Name"

        placeholder="e.g. Samsung, Nike, Apple"

        :columns="colThird"

        autocomplete="off"

        :rules="nameRules"

        info="Use the official or commonly recognized brand name."

    />



    <StaticElement name="section_settings" :columns="colFull">

        <div class="company-section-header company-section-header-teal company-section-header-spaced">

            <span class="company-section-icon company-section-icon-teal">

                <SliderAlt size="sm" />

            </span>

            <div>

                <h6 class="company-section-title mb-0">Status</h6>

                <p class="company-section-subtitle mb-0">Control whether this brand appears in product forms</p>

            </div>

        </div>

    </StaticElement>



    <ToggleElement

        :labels="{ 1: 'Active', 0: 'Inactive' }"

        :columns="colThird"

        id="Active"

        field-name="Active"

        name="active"

        label="Brand Status"

        :true-value="true"

        :false-value="false"

        :default="true"

        info="Inactive brands are hidden from product add and edit screens."

    />

</template>

