<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { Percent, ShieldCheck } from '@lucide/vue';
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

    const isEdit = computed(() => params.type === 'edit');

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));

    const { fetchCompany, companiesdata } = useCommons();

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
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />

    <GroupElement name="group_discount" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_discount" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Percent class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Discount rule</h2>
                    <p class="product-form-section-copy">The rule, its coupon code and when it applies</p>
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
            info="Assign this discount to a company."
        />

        <TextElement
            id="Name"
            field-name="Name"
            name="name"
            label="Discount Name"
            placeholder="e.g. Eid Sale 10%"
            :columns="colThird"
            autocomplete="off"
            rules="required|min:3|max:200"
        />

        <TextElement
            id="Code"
            field-name="Code"
            name="code"
            label="Coupon Code"
            placeholder="e.g. EID10 (optional)"
            :columns="colThird"
            autocomplete="off"
            rules="nullable|max:50|regex:/^[A-Za-z0-9_-]+$/"
            info="Letters, numbers, dash and underscore. Leave empty for an automatic discount."
        />

        <SelectElement
            id="DiscountType"
            field-name="DiscountType"
            name="discount_type"
            label="Discount Type"
            :native="false"
            :items="[{ value: 'percentage', label: 'Percentage (%)' }, { value: 'fixed', label: 'Fixed amount' }]"
            :columns="colThird"
            :search="false"
            :floating="false"
            :can-clear="false"
            :default="'percentage'"
            rules="required"
        />

        <TextElement
            id="Value"
            field-name="Value"
            name="value"
            input-type="number"
            label="Value"
            placeholder="e.g. 10"
            :columns="colThird"
            rules="required|numeric|min:0.01"
            info="A percentage (1-100) or an amount, depending on the type."
        />

        <TextElement
            id="MinPurchaseAmount"
            field-name="MinPurchaseAmount"
            name="min_purchase_amount"
            input-type="number"
            label="Minimum Purchase"
            placeholder="0"
            :columns="colThird"
            rules="nullable|numeric|min:0"
            info="The sale must be at least this much for the discount to apply."
        />

        <TextElement
            id="MaxDiscountAmount"
            field-name="MaxDiscountAmount"
            name="max_discount_amount"
            input-type="number"
            label="Maximum Discount"
            placeholder="No cap"
            :columns="colThird"
            rules="nullable|numeric|min:0.01"
            info="Caps a percentage discount. Ignored for a fixed amount."
        />

        <DateElement
            id="StartsAt"
            field-name="StartsAt"
            name="starts_at"
            label="Valid From"
            placeholder="Select start date"
            :columns="colThird"
            :floating="false"
        />

        <DateElement
            id="ExpiresAt"
            field-name="ExpiresAt"
            name="expires_at"
            label="Expires On"
            placeholder="No expiry"
            :columns="colThird"
            :floating="false"
            rules="nullable|after_or_equal:starts_at"
        />
    </GroupElement>

    <GroupElement name="group_settings" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_settings" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <ShieldCheck class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Status</h2>
                    <p class="product-form-section-copy">Control whether this discount can be used</p>
                </div>
            </div>
        </StaticElement>

        <ToggleElement
            :labels="{ 1: 'Active', 0: 'Inactive' }"
            :columns="colThird"
            id="IsActive"
            field-name="IsActive"
            name="is_active"
            label="Discount Status"
            :true-value="true"
            :false-value="false"
            :default="true"
            info="Inactive discounts cannot be applied."
        />
    </GroupElement>
</template>
