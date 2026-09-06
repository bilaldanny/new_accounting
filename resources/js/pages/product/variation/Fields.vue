<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { computed, onMounted, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';
    import { Folder, Palette, SliderAlt } from '@boxicons/vue';
    import VariationValuesEditor from './VariationValuesEditor.vue';

    type VariationValue = {
        name: string;
        active: boolean | number;
    };

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
    const isEdit = computed(() => params.type === 'edit');

    const {
        fetchCompany,
        fetchCategory,
        fetchSubCategory,
        fetchItemType,
        companiesdata,
        categoriesdata,
        subcategoriesdata,
        itemtypesdata,
    } = useCommons();

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedCategoryId = computed(() => params.formData?.category_id ?? '');

    const lastFetchedCompanyId = ref('');
    const lastFetchedCategoryKey = ref('');
    const valueRows = ref<VariationValue[]>([{ name: '', active: true }]);
    const valuesHydrated = ref(false);

    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const nameRules = 'required|min:2|max:200';
    const categoryRules = 'required';
    const itemtypeRules = 'required';

    const scopeDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);
    const subcategoryDisabled = computed(() => scopeDisabled.value || ! selectedCategoryId.value);

    function emptyValue(): VariationValue {
        return { name: '', active: true };
    }

    function normalizeActiveForSubmit(active: unknown): 0 | 1 {
        return active === true || active === 1 || active === '1' || active === 'true' ? 1 : 0;
    }

    function persistValues(values: VariationValue[]) {
        const normalized = (Array.isArray(values) && values.length > 0 ? values : [emptyValue()]).map((value) => ({
            name: String(value.name ?? ''),
            active: normalizeActiveForSubmit(value.active),
        }));

        valueRows.value = normalized;

        if (params.formData) {
            params.formData.values = normalized;
        }

        params.formRef?.update?.({ values: normalized });
    }

    function applyScopedDefaults() {
        if (isSuperadmin.value) {
            return;
        }

        if (authUser.value?.company_id) {
            params.formRef?.update?.({ company_id: authUser.value.company_id });
        }
    }

    function ensureDefaultValues() {
        const existing = Array.isArray(params.formData?.values) ? params.formData.values : [];
        const namedValues = existing.filter((value: VariationValue) => String(value?.name ?? '').trim() !== '');

        persistValues(namedValues.length > 0 ? namedValues : existing.length > 0 ? existing : [emptyValue()]);
        valuesHydrated.value = true;
    }

    function syncValues(values: VariationValue[]) {
        persistValues(values);
    }

    async function loadScopeOptions(companyId: string | number | null | undefined) {
        const normalizedCompanyId = normalizeId(companyId);

        if (! normalizedCompanyId) {
            categoriesdata.value = [];
            subcategoriesdata.value = [];
            itemtypesdata.value = [];

            return;
        }

        if (normalizedCompanyId === lastFetchedCompanyId.value) {
            return;
        }

        lastFetchedCompanyId.value = normalizedCompanyId;
        await Promise.all([
            fetchCategory(normalizedCompanyId),
            fetchItemType(normalizedCompanyId),
        ]);
    }

    async function loadSubcategoryOptions(
        companyId: string | number | null | undefined,
        categoryId: string | number | null | undefined,
    ) {
        const normalizedCompanyId = normalizeId(companyId);
        const normalizedCategoryId = normalizeId(categoryId);
        const cacheKey = `${normalizedCompanyId}:${normalizedCategoryId}`;

        if (! normalizedCompanyId || ! normalizedCategoryId) {
            subcategoriesdata.value = [];

            return;
        }

        if (cacheKey === lastFetchedCategoryKey.value) {
            return;
        }

        lastFetchedCategoryKey.value = cacheKey;
        await fetchSubCategory(normalizedCompanyId, normalizedCategoryId);
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        const normalizedCompanyId = normalizeId(companyId);

        params.formRef?.update?.({
            category_id: '',
            subcategory_id: '',
            itemtype_id: '',
        });

        lastFetchedCompanyId.value = '';
        lastFetchedCategoryKey.value = '';
        await loadScopeOptions(normalizedCompanyId || undefined);
    }

    async function handleCategoryChange(
        companyId: string | number | null | undefined,
        categoryId: string | number | null | undefined,
    ) {
        if (! isEdit.value) {
            params.formRef?.update?.({ subcategory_id: '' });
        }

        lastFetchedCategoryKey.value = '';
        await loadSubcategoryOptions(companyId, normalizeId(categoryId) || undefined);
    }

    onMounted(async () => {
        applyScopedDefaults();
        ensureDefaultValues();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        const companyId = isSuperadmin.value
            ? selectedCompanyId.value
            : authUser.value?.company_id;

        if (companyId) {
            await loadScopeOptions(companyId);

            if (selectedCategoryId.value) {
                await loadSubcategoryOptions(companyId, selectedCategoryId.value);
            }
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

    watch(
        () => `${normalizeId(params.formData?.company_id)}:${normalizeId(params.formData?.category_id)}`,
        async (key, previousKey) => {
            if (key === previousKey) {
                return;
            }

            const [companyId, categoryId] = key.split(':');

            await handleCategoryChange(companyId || undefined, categoryId || undefined);
        },
    );

    watch(
        () => params.formData?.values,
        (values) => {
            if (! valuesHydrated.value) {
                return;
            }

            if (! Array.isArray(values) || values.length === 0) {
                if (params.formData && valueRows.value.length > 0) {
                    params.formData.values = valueRows.value;
                }

                return;
            }

            const incomingNames = values.map((value: VariationValue) => value.name).join('|');
            const currentNames = valueRows.value.map((value) => value.name).join('|');

            if (incomingNames !== currentNames && incomingNames.replaceAll('|', '') !== '') {
                persistValues(values);
            }
        },
        { deep: true },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <TextElement
        v-if="showHiddenCompanyField"
        name="company_id"
        hidden="true"
    />

    <TextElement name="priority" default="0" hidden="true" />

    <StaticElement name="section_scope" :columns="colFull">
        <div class="company-section-header company-section-header-primary">
            <span class="company-section-icon company-section-icon-primary">
                <Folder size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Product Scope</h6>
                <p class="company-section-subtitle mb-0">Link this variation set to a category, subcategory, and item type</p>
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
        info="Required. Variations are scoped to a single company."
    />

    <SelectElement
        name="category_id"
        :native="false"
        :items="categoriesdata"
        id="CategoryId"
        field-name="CategoryId"
        placeholder="Select category"
        label="Category"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="scopeDisabled"
        :rules="categoryRules"
        info="Required. Top-level category for this variation set."
    />

    <SelectElement
        name="subcategory_id"
        :native="false"
        :items="subcategoriesdata"
        id="SubcategoryId"
        field-name="SubcategoryId"
        placeholder="Select subcategory"
        label="Sub Category"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="true"
        :disabled="subcategoryDisabled"
        info="Optional. Narrow the variation to a subcategory when applicable."
    />

    <SelectElement
        name="itemtype_id"
        :native="false"
        :items="itemtypesdata"
        id="ItemtypeId"
        field-name="ItemtypeId"
        placeholder="Select item type"
        label="Item Type"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="scopeDisabled"
        :rules="itemtypeRules"
        info="Required. Item type this variation applies to."
    />

    <TextElement
        id="VariationName"
        field-name="VariationName"
        name="name"
        label="Variation name"
        placeholder="e.g. RAM, Color, Size"
        :columns="colThird"
        autocomplete="off"
        :rules="nameRules"
        info="Required. The attribute this set represents, such as RAM, color, or size."
    />

    <StaticElement name="section_values" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <Palette size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Variation Values</h6>
                <p class="company-section-subtitle mb-0">Define options such as size, color, or material — each can be toggled active or inactive</p>
            </div>
        </div>
    </StaticElement>

    <StaticElement name="values_editor" :columns="colFull">
        <VariationValuesEditor
            :values="valueRows"
            @update:values="syncValues"
        />
    </StaticElement>

    <StaticElement name="section_settings" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Status</h6>
                <p class="company-section-subtitle mb-0">Control whether this variation set is available in product forms</p>
            </div>
        </div>
    </StaticElement>

    <ToggleElement
        :labels="{ 1: 'Active', 0: 'Inactive' }"
        :columns="colThird"
        id="Active"
        field-name="Active"
        name="active"
        label="Variation Status"
        :true-value="1"
        :false-value="0"
        :default="1"
        info="Inactive variation sets are hidden from product creation and editing."
    />
</template>
