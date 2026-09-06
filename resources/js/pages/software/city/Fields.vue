<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { computed, onMounted, ref, watch } from 'vue';

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

    const {
        fetchCountry,
        fetchState,
        countriesdata,
        statesdata,
    } = useCommons();

    const selectedCountryId = computed(() => params.formData?.country_id ?? '');

    const normalizeLocationId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const lastFetchedCountryId = ref('');

    const nameRules = computed(() => {
        if (params.recordId) {
            return `required|city_name_unique:${params.recordId}`;
        }

        return 'required|city_name_unique';
    });

    async function handleCountryChange(countryId: string | number | null | undefined) {
        const normalizedCountryId = normalizeLocationId(countryId);

        if (normalizedCountryId === lastFetchedCountryId.value) {
            return;
        }

        lastFetchedCountryId.value = normalizedCountryId;

        if (params.formData?.state_id) {
            params.formRef?.update?.({ state_id: '' });
        }

        if (normalizedCountryId) {
            await fetchState(normalizedCountryId);

            return;
        }

        statesdata.value = [];
    }

    onMounted(async () => {
        await fetchCountry();

        if (selectedCountryId.value) {
            lastFetchedCountryId.value = normalizeLocationId(selectedCountryId.value);
            await fetchState(selectedCountryId.value);
        }
    });

    watch(
        () => normalizeLocationId(params.formData?.country_id),
        async (countryId, previousCountryId) => {
            if (countryId === previousCountryId) {
                return;
            }

            await handleCountryChange(countryId || undefined);
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <SelectElement
        name="country_id"
        :native="false"
        :items="countriesdata"
        id="CountryId"
        field-name="CountryId"
        placeholder="Select country"
        label="Country"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        rules="required"
    />

    <SelectElement
        name="state_id"
        :native="false"
        :items="statesdata"
        id="StateId"
        field-name="StateId"
        placeholder="Select state"
        label="State"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="!selectedCountryId"
        rules="required"
    />

    <TextElement
        id="CityName"
        field-name="CityName"
        name="name"
        label="Name"
        placeholder="Enter city name"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        :rules="nameRules"
    />

    <StaticElement tag="br" name="element" />

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        id="Flag"
        field-name="Flag"
        name="flag"
        label="Is Active"
        :true-value="true"
        :false-value="false"
        :default="true"
    />
</template>
