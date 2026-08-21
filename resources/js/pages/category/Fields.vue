<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { computed, onMounted, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';
    import { Folder, SliderAlt, Store } from '@boxicons/vue';

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

    const { fetchCompany, fetchCategory, companiesdata, categoriesdata } = useCommons();

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');

    const lastFetchedCompanyId = ref('');

    const nameRules = computed(() => {
        if (params.recordId) {
            return `required|min:3|max:200|category_name_unique:${params.recordId}`;
        }

        return 'required|min:3|max:200|category_name_unique';
    });

    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));

    const parentDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);

    const hierarchySectionSubtitle = computed(() => (
        showCompanyField.value
            ? 'Company scope and optional parent category for subcategories'
            : 'Optional parent category for subcategories'
    ));

    function applyScopedDefaults() {
        if (isSuperadmin.value) {
            return;
        }

        if (authUser.value?.company_id) {
            params.formRef?.update?.({ company_id: authUser.value.company_id });
        }
    }

    async function loadCategoryOptions(companyId: string | number | null | undefined) {
        const normalizedCompanyId = normalizeId(companyId);

        if (! normalizedCompanyId) {
            categoriesdata.value = [];

            return;
        }

        if (normalizedCompanyId === lastFetchedCompanyId.value) {
            return;
        }

        lastFetchedCompanyId.value = normalizedCompanyId;
        await fetchCategory(normalizedCompanyId, params.recordId ?? undefined);
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
        await loadCategoryOptions(normalizedCompanyId || undefined);
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
            await loadCategoryOptions(companyId);
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
                <Folder size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Category Hierarchy</h6>
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
        info="Required. Categories belong to a single company."
    />

    <SelectElement
        name="parent_id"
        :native="false"
        :items="categoriesdata"
        id="ParentId"
        field-name="ParentId"
        placeholder="Select parent category"
        label="Parent Category"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="parentDisabled"
        info="Optional. Choose a top-level category to create a subcategory."
    />

    <StaticElement name="section_details" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <Store size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Category Details</h6>
                <p class="company-section-subtitle mb-0">Display name shown in product lists and filters</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Category Name"
        placeholder="e.g. Electronics"
        :columns="colThird"
        autocomplete="off"
        :rules="nameRules"
        info="Use a clear, descriptive name for grouping products."
    />

    <StaticElement name="section_settings" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Status</h6>
                <p class="company-section-subtitle mb-0">Control whether this category appears in product forms</p>
            </div>
        </div>
    </StaticElement>

    <ToggleElement
        :labels="{ 1: 'Active', 0: 'Inactive' }"
        :columns="colThird"
        id="Active"
        field-name="Active"
        name="active"
        label="Category Status"
        :true-value="true"
        :false-value="false"
        :default="true"
        info="Inactive categories are hidden from product and variation forms."
    />
</template>
