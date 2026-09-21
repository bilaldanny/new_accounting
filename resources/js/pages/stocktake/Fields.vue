<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { ClipboardList, Filter } from '@lucide/vue';
    import { computed, onMounted } from 'vue';
    import useCommons from '@/composables/common';

    const params = defineProps({
        type: String,
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
    const cardClasses = {
        ElementLayout: {
            container: 'product-form-card',
        },
        GroupElement: {
            wrapper: 'product-form-card__body',
        },
    };

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const ownBranchOnly = computed(() => ! isSuperadmin.value && ! isCompanyadmin.value && Boolean(authUser.value?.branch_id));

    const {
        fetchCompany,
        fetchBranch,
        fetchCategory,
        fetchItemType,
        companiesdata,
        branchesdata,
        categoriesdata,
        itemtypesdata,
    } = useCommons();

    async function loadScope(companyId: unknown) {
        await Promise.all([fetchBranch(companyId as string), fetchCategory(companyId as string), fetchItemType(companyId as string)]);

        if (ownBranchOnly.value && authUser.value?.branch_id) {
            params.formRef?.update?.({ branch_id: authUser.value.branch_id });
        } else if (branchesdata.value.length === 1) {
            params.formRef?.update?.({ branch_id: branchesdata.value[0].id });
        }
    }

    function applyScopedDefaults() {
        if (isSuperadmin.value) {
            return;
        }

        if (params.formData && authUser.value?.company_id) {
            Object.assign(params.formData, { company_id: authUser.value.company_id });
            params.formRef?.update?.({ company_id: authUser.value.company_id });
        }
    }

    onMounted(async () => {
        applyScopedDefaults();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        await loadScope(isSuperadmin.value ? params.formData?.company_id : authUser.value?.company_id);
    });
</script>

<template>
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />

    <GroupElement name="group_stocktake" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_stocktake" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <ClipboardList class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Count sheet</h2>
                    <p class="product-form-section-copy">The system stock of the branch is frozen into a sheet you then count against</p>
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
            @change="loadScope"
        />

        <SelectElement
            name="branch_id"
            :native="false"
            :items="branchesdata"
            id="BranchId"
            field-name="BranchId"
            placeholder="Select branch"
            label="Branch"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :disabled="ownBranchOnly"
            rules="required"
        />

        <DateElement
            id="CountDate"
            field-name="CountDate"
            name="count_date"
            label="Count Date"
            placeholder="Select date"
            :columns="colThird"
            :floating="false"
            :default="new Date().toISOString().slice(0, 10)"
            rules="required"
        />

        <TextElement
            id="Note"
            field-name="Note"
            name="note"
            label="Note"
            placeholder="e.g. Monthly count"
            :columns="colFull"
            autocomplete="off"
            rules="nullable|max:500"
        />
    </GroupElement>

    <GroupElement name="group_filters" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_filters" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Filter class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Products to count</h2>
                    <p class="product-form-section-copy">Leave everything empty to count every active product</p>
                </div>
            </div>
        </StaticElement>

        <SelectElement
            name="category_id"
            :native="false"
            :items="categoriesdata"
            id="CategoryId"
            field-name="CategoryId"
            placeholder="All categories"
            label="Category"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="true"
        />

        <SelectElement
            name="itemtype_id"
            :native="false"
            :items="itemtypesdata"
            id="ItemTypeId"
            field-name="ItemTypeId"
            placeholder="All item types"
            label="Item Type"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="true"
        />

        <ToggleElement
            :labels="{ 1: 'Yes', 0: 'No' }"
            :columns="colThird"
            id="OnlyInStock"
            field-name="OnlyInStock"
            name="only_in_stock"
            label="Only products in stock"
            :true-value="true"
            :false-value="false"
            :default="false"
            info="Skip products the system shows no stock for."
        />
    </GroupElement>
</template>
