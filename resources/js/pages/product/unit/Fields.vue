<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { computed, onMounted, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';
    import { Cube, Layers, SliderAlt } from '@boxicons/vue';

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

    const { fetchCompany, fetchUnit, companiesdata, unitsdata } = useCommons();

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');

    const lastFetchedCompanyId = ref('');

    const nameRules = computed(() => {
        if (params.recordId) {
            return `required|unit_name_unique:${params.recordId}`;
        }

        return 'required|unit_name_unique';
    });

    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));

    const parentDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);

    const hierarchySectionSubtitle = computed(() => (
        showCompanyField.value
            ? 'Company scope and optional parent unit for conversions'
            : 'Optional parent unit for conversions'
    ));

    const typeOptions = [
        { id: 'large', text: 'Large' },
        { id: 'small', text: 'Small' },
    ];

    function applyScopedDefaults() {
        if (isSuperadmin.value) {
            return;
        }

        if (authUser.value?.company_id) {
            params.formRef?.update?.({ company_id: authUser.value.company_id });
        }
    }

    async function loadUnitOptions(companyId: string | number | null | undefined) {
        const normalizedCompanyId = normalizeId(companyId);

        if (! normalizedCompanyId) {
            unitsdata.value = [];

            return;
        }

        if (normalizedCompanyId === lastFetchedCompanyId.value) {
            return;
        }

        lastFetchedCompanyId.value = normalizedCompanyId;
        await fetchUnit(normalizedCompanyId, params.recordId ?? undefined);
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        const normalizedCompanyId = normalizeId(companyId);

        if (params.formData?.parent_id) {
            params.formRef?.update?.({ parent_id: '' });
        }

        lastFetchedCompanyId.value = '';
        await loadUnitOptions(normalizedCompanyId || undefined);
    }

    onMounted(async () => {
        applyScopedDefaults();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        const companyId = isSuperadmin.value
            ? selectedCompanyId.value
            : authUser.value?.company_id;

        if (companyId) {
            await loadUnitOptions(companyId);
        }
    });

    watch(
        () => normalizeId(params.formData?.company_id),
        async (companyId, previousCompanyId) => {
            if (companyId === previousCompanyId) {
                return;
            }

            await handleCompanyChange(companyId || undefined);
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <TextElement
        v-if="showHiddenCompanyField"
        name="company_id"
        hidden="true"
    />

    <StaticElement name="section_hierarchy" :columns="colFull">
        <div class="company-section-header company-section-header-primary">
            <span class="company-section-icon company-section-icon-primary">
                <Layers size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Unit Hierarchy</h6>
                <p class="company-section-subtitle mb-0">{{ hierarchySectionSubtitle }}</p>
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
        info="Required. Units belong to a single company."
    />

    <SelectElement
        name="parent_id"
        :native="false"
        :items="unitsdata"
        id="ParentId"
        field-name="ParentId"
        placeholder="Select parent unit"
        label="Parent Unit"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="parentDisabled"
        info="Optional. Link to a larger base unit when this is a derived or smaller unit."
    />

    <StaticElement name="section_details" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <Cube size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Unit Details</h6>
                <p class="company-section-subtitle mb-0">Display name, abbreviation, and measurement type</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Unit Name"
        placeholder="e.g. Kilogram"
        :columns="colThird"
        autocomplete="off"
        :rules="nameRules"
        info="Full name shown in lists and reports."
    />

    <TextElement
        id="ShortName"
        field-name="ShortName"
        name="short_name"
        label="Short Name"
        placeholder="e.g. KG"
        :columns="colThird"
        autocomplete="off"
        rules="required|max:20"
        info="Abbreviation used on invoices, labels, and compact views."
    />

    <SelectElement
        name="type"
        :native="false"
        :items="typeOptions"
        id="Type"
        field-name="Type"
        placeholder="Select type"
        label="Unit Type"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="false"
        :floating="false"
        :can-clear="false"
        :default="'large'"
        info="Large units are base measurements; small units are typically derived from a parent."
    />

    <StaticElement name="section_settings" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Status &amp; Settings</h6>
                <p class="company-section-subtitle mb-0">Availability and inventory adjustment behavior</p>
            </div>
        </div>
    </StaticElement>

    <ToggleElement
        :labels="{ 1: 'Yes', 0: 'No' }"
        :columns="colThird"
        id="AutoAdjustment"
        field-name="AutoAdjustment"
        name="auto_adjustment"
        label="Auto Adjustment"
        :true-value="true"
        :false-value="false"
        :default="false"
        info="Automatically adjust stock when converting between this unit and its parent."
    />

    <ToggleElement
        :labels="{ 1: 'Active', 0: 'Inactive' }"
        :columns="colThird"
        id="Active"
        field-name="Active"
        name="active"
        label="Unit Status"
        :true-value="true"
        :false-value="false"
        :default="true"
        info="Inactive units are hidden from product and transaction forms."
    />
</template>
