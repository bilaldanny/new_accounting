<script setup lang="ts">

    import { Cog } from '@boxicons/vue';

    import { resolvePublicAppBaseUrl } from '@/utils/publicAppUrl';

    import { openLfmImagePicker } from '@/utils/openLfmImagePicker';

    import useCommons from '@/composables/common';
    import { usePage } from '@inertiajs/vue3';
    import { onMounted, ref, watch, computed } from 'vue';

    import {

        accountingMethodItems,

        colFull,

        colHalf,

        colThird,

        currencyPlacementItems,

        dateFormatItems,

        financialMonthItems,

        searchTypeItems,

        timeFormatItems,

    } from './constants';



    const props = defineProps({

        logoUrl: { type: String, default: '' },

        currenciesdata: { type: Array, default: () => [] },

        timezonesdata: { type: Array, default: () => [] },

        companiesdata: { type: Array, default: () => [] },

        showCompanyFilter: { type: Boolean, default: false },

        formData: { type: Object, default: () => ({}) },

        formRef: { type: Object, default: null },

    });



    const {

        fetchCountry,

        fetchState,

        fetchCity,

        countriesdata,

        statesdata,

        citiesdata,

    } = useCommons();

    const page = usePage();
    const defaultDialCode = computed(() => String(page.props.dailCode ?? ''));

    const selectedCountryId = computed(() => props.formData?.country_id ?? '');

    const selectedStateId = computed(() => props.formData?.state_id ?? '');



    const normalizeLocationId = (id: unknown) =>

        id === null || id === undefined || id === '' ? '' : String(id);



    const lastFetchedCountryId = ref('');

    const lastFetchedStateId = ref('');



    async function handleCountryChange(countryId: string | number | null | undefined) {

        const normalizedCountryId = normalizeLocationId(countryId);



        if (normalizedCountryId === lastFetchedCountryId.value) {

            return;

        }



        lastFetchedCountryId.value = normalizedCountryId;

        lastFetchedStateId.value = '';



        if (props.formData?.state_id || props.formData?.city_id) {

            props.formRef?.update?.({ state_id: '', city_id: '' });

        }



        if (normalizedCountryId) {

            await fetchState(normalizedCountryId);



            return;

        }



        statesdata.value = [];

        citiesdata.value = [];

    }



    async function handleStateChange(stateId: string | number | null | undefined) {

        const normalizedStateId = normalizeLocationId(stateId);

        const normalizedCountryId = normalizeLocationId(selectedCountryId.value);



        if (normalizedStateId === lastFetchedStateId.value) {

            return;

        }



        lastFetchedStateId.value = normalizedStateId;



        if (props.formData?.city_id) {

            props.formRef?.update?.({ city_id: '' });

        }



        if (normalizedStateId && normalizedCountryId) {

            await fetchCity(normalizedCountryId, normalizedStateId);



            return;

        }



        citiesdata.value = [];

    }



    onMounted(async () => {

        await fetchCountry();



        if (selectedCountryId.value) {

            lastFetchedCountryId.value = normalizeLocationId(selectedCountryId.value);

            await fetchState(selectedCountryId.value);

        }



        if (selectedStateId.value && selectedCountryId.value) {

            lastFetchedStateId.value = normalizeLocationId(selectedStateId.value);

            await fetchCity(selectedCountryId.value, selectedStateId.value);

        }



        renderLogoPreview();

    });



    watch(

        () => normalizeLocationId(props.formData?.country_id),

        async (countryId, previousCountryId) => {

            if (countryId === previousCountryId) {

                return;

            }



            await handleCountryChange(countryId || undefined);

        },

    );



    watch(

        () => normalizeLocationId(props.formData?.state_id),

        async (stateId, previousStateId) => {

            if (stateId === previousStateId) {

                return;

            }



            await handleStateChange(stateId || undefined);

        },

    );



    const appUrl = resolvePublicAppBaseUrl();

    const logoInputId = 'CompanySettingLogo';



    function chooseLogo(event: MouseEvent) {

        openLfmImagePicker(event, appUrl);

    }



    function renderLogoPreview() {

        const holder = document.getElementById('company-setting-logo-holder');



        if (!holder) {

            return;

        }



        holder.innerHTML = '';



        if (!props.logoUrl) {

            holder.classList.remove('company-setting-logo-preview--filled');



            return;

        }



        holder.classList.add('company-setting-logo-preview--filled');



        const img = document.createElement('img');

        img.className = 'company-setting-logo-preview__image';

        img.alt = 'Company logo preview';

        img.src = props.logoUrl;

        holder.appendChild(img);

    }



    watch(() => props.logoUrl, renderLogoPreview);

</script>



<template>

    <SelectElement

        v-if="showCompanyFilter"

        name="company_id"

        :native="false"

        :items="companiesdata"

        id="CompanySettingCompanyId"

        field-name="CompanySettingCompanyId"

        placeholder="Select company"

        label="Company"

        :columns="colThird"

        label-prop="text"

        value-prop="id"

        :search="true"

        :floating="false"

        :can-clear="false"

        rules="required"

    />



    <StaticElement v-if="showCompanyFilter" name="company_divider" tag="hr" :columns="colFull" />



    <StaticElement name="section_general" :columns="colFull">

        <div class="company-setting-section-label">General Information</div>

    </StaticElement>



    <TextElement

        name="business_name"

        label="Business Name"

        placeholder="Business name"

        :columns="colThird"

        rules="required|min:3|max:200"

    />



    <PhoneElement
        id="CompanySettingPhone"
        field-name="CompanySettingPhone"
        name="phone"
        label="Business Number"
        placeholder="Business number"
        :columns="colThird"
        :default="defaultDialCode"
        :allow-incomplete="true"
        :unmask="true"
    />



    <DateElement

        name="start_date"

        label="Start Date"

        placeholder="Select start date"

        :columns="colThird"

        :floating="false"

    />



    <TextElement

        name="profit_percent"

        label="Default Profit Percent"

        placeholder="Default profit percent"

        :columns="colThird"

        input-type="number"

        info="Default profit margin used to calculate selling price from purchase price."

    />



    <StaticElement name="section_regional" :columns="colFull">

        <div class="company-setting-section-label">Currency &amp; Regional</div>

    </StaticElement>



    <SelectElement

        name="currency_id"

        :native="false"

        :items="currenciesdata"

        label="Currency"

        placeholder="Select currency"

        :columns="colThird"

        label-prop="text"

        value-prop="id"

        :search="true"

        :floating="false"

        :can-clear="true"

    />



    <SelectElement

        name="currency_placement"

        :native="false"

        :items="currencyPlacementItems"

        label="Currency Symbol Placement"

        placeholder="Select placement"

        :columns="colThird"

        :search="false"

        :floating="false"

        :can-clear="true"

    />



    <SelectElement

        name="timezone_id"

        :native="false"

        :items="timezonesdata"

        label="Timezone"

        placeholder="Select timezone"

        :columns="colThird"

        label-prop="text"

        value-prop="id"

        :search="true"

        :floating="false"

        :can-clear="true"

    />



    <SelectElement

        name="financial_start_month"

        :native="false"

        :items="financialMonthItems"

        label="Financial Month Start"

        placeholder="Select month"

        :columns="colThird"

        :search="false"

        :floating="false"

        :can-clear="true"

    />



    <SelectElement

        name="date_format"

        :native="false"

        :items="dateFormatItems"

        label="Date Format"

        placeholder="Select date format"

        :columns="colThird"

        :search="false"

        :floating="false"

        :can-clear="true"

    />



    <SelectElement

        name="time_format"

        :native="false"

        :items="timeFormatItems"

        label="Time Format"

        placeholder="Select time format"

        :columns="colThird"

        :search="false"

        :floating="false"

        :can-clear="true"

    />



    <SelectElement

        name="accounting_method"

        :native="false"

        :items="accountingMethodItems"

        label="Stock Accounting Method"

        placeholder="Select method"

        :columns="colThird"

        :search="false"

        :floating="false"

        :can-clear="false"

    />



    <SelectElement

        name="search_type"

        :native="false"

        :items="searchTypeItems"

        label="Search Type"

        placeholder="Select search type"

        :columns="colThird"

        :search="false"

        :floating="false"

        :can-clear="false"

        info="Product search control used on sell and purchase screens."

    />



    <TextElement

        name="transaction_edit_days"

        label="Transaction Edit Days"

        placeholder="Number of days"

        :columns="colThird"

        info="Days after transaction date during which edits are allowed."

    />



    <StaticElement name="section_branding" :columns="colFull">

        <div class="company-setting-section-label">Branding</div>

    </StaticElement>



    <TextElement

        :id="logoInputId"

        name="logo"

        label="Upload Logo"

        placeholder="Choose logo file"

        :columns="colHalf"

        readonly

        class="company-setting-logo-field"

    >

        <template #addon-before>

            <button

                :data-input="logoInputId"

                data-field-name="logo"

                data-preview="company-setting-logo-holder"

                type="button"

                class="company-logo-choose"

                @click="chooseLogo"

            >

                Choose

            </button>

        </template>

        <template #after>

            <div id="company-setting-logo-holder" class="company-setting-logo-preview"></div>

        </template>

    </TextElement>



    <StaticElement name="section_contact" :columns="colFull">

        <div class="company-setting-section-label">Contact Details</div>

    </StaticElement>



    <TextElement

        name="email"

        label="Email"

        placeholder="Company email"

        :columns="colThird"

        input-type="email"

    />

    <PhoneElement
        id="CompanySettingCell"
        field-name="CompanySettingCell"
        name="cell"
        label="Cell No"
        placeholder="Cell number"
        :columns="colThird"
        :default="defaultDialCode"
        :allow-incomplete="true"
        :unmask="true"
    />

    <PhoneElement
        id="CompanySettingWhatsappNo"
        field-name="CompanySettingWhatsappNo"
        name="whatsapp_no"
        label="WhatsApp No"
        placeholder="WhatsApp number"
        :columns="colThird"
        :default="defaultDialCode"
        :allow-incomplete="true"
        :unmask="true"
    />



    <TextElement

        name="fb_link"

        label="Facebook Link"

        placeholder="Facebook link"

        :columns="colThird"

    />



    <SelectElement

        name="country_id"

        :native="false"

        :items="countriesdata"

        id="CompanySettingCountryId"

        field-name="CompanySettingCountryId"

        placeholder="Select country"

        label="Country"

        :columns="colThird"

        label-prop="text"

        value-prop="id"

        :search="true"

        :floating="false"

        :can-clear="true"

    />



    <SelectElement

        name="state_id"

        :native="false"

        :items="statesdata"

        id="CompanySettingStateId"

        field-name="CompanySettingStateId"

        placeholder="Select state"

        label="State / Province"

        :columns="colThird"

        label-prop="text"

        value-prop="id"

        :search="true"

        :floating="false"

        :can-clear="true"

        :disabled="!selectedCountryId"

    />



    <SelectElement

        name="city_id"

        :native="false"

        :items="citiesdata"

        id="CompanySettingCityId"

        field-name="CompanySettingCityId"

        placeholder="Select city"

        label="City"

        :columns="colThird"

        label-prop="text"

        value-prop="id"

        :search="true"

        :floating="false"

        :can-clear="true"

        :disabled="!selectedStateId"

    />



    <TextareaElement

        name="address"

        label="Address"

        placeholder="Company address"

        :columns="colFull"

    />



    <StaticElement name="section_options" :columns="colFull">

        <div class="company-setting-section-label">Options</div>

    </StaticElement>



    <ToggleElement

        name="update_packing_qty"

        label="Update Quantity"

        :columns="colHalf"

        :labels="{ 1: 'Yes', 0: 'No' }"

        :true-value="true"

        :false-value="false"

        info="Show or hide the packing quantity field on purchase screens."

    />

</template>


