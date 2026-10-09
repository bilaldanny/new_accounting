<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { Layers, Gauge } from '@lucide/vue';
    import { computed } from 'vue';
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
    const isEdit = computed(() => params.type === 'edit');

    const colThird = { container: 4, label: 12, wrapper: 12 };
    const colQuarter = { container: 3, label: 12, wrapper: 12 };
    const colFull = { container: 12, label: 12, wrapper: 12 };
    const cardClasses = {
        ElementLayout: { container: 'product-form-card' },
        GroupElement: { wrapper: 'product-form-card__body' },
    };

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
    } | null);

    const roleName = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, ''));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);

    const { fetchCompany, companiesdata } = useCommons();

    const billingCycles = [
        { value: 'monthly', label: 'Monthly' },
        { value: 'quarterly', label: 'Quarterly' },
        { value: 'annual', label: 'Annual' },
    ];

    if (showCompanyField.value) {
        fetchCompany();
    }
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />

    <GroupElement name="group_plan" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_plan" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Layers class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Plan details</h2>
                    <p class="product-form-section-copy">What you sell your own customers — gym membership, maintenance contract, your own SaaS, ...</p>
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
            label="Company"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            rules="required"
        />

        <TextElement id="Name" field-name="Name" name="name" label="Plan Name" placeholder="e.g. Gold Membership" :columns="colThird" rules="required|min:2|max:150" />
        <TextElement id="Code" field-name="Code" name="code" label="Plan Code" placeholder="e.g. GOLD" :columns="colThird" rules="required|max:50" />
        <SelectElement
            name="billing_cycle"
            :native="false"
            :items="billingCycles"
            id="BillingCycle"
            field-name="BillingCycle"
            label="Billing Cycle"
            :columns="colThird"
            label-prop="label"
            value-prop="value"
            :can-clear="false"
            rules="required"
        />
        <TextElement id="Price" field-name="Price" name="price" label="Price" type="number" :columns="colThird" rules="required|min_value:0" />
        <TextElement id="SetupFee" field-name="SetupFee" name="setup_fee" label="Setup Fee" type="number" :columns="colThird" default="0" info="Charged once, on the customer's first invoice only." />
        <TextElement id="TrialDays" field-name="TrialDays" name="trial_days" label="Trial Days" type="number" :columns="colThird" default="0" />

        <ToggleElement
            :labels="{ 1: 'Active', 0: 'Inactive' }"
            :columns="colThird"
            id="IsActive"
            field-name="IsActive"
            name="is_active"
            label="Plan Status"
            :true-value="true"
            :false-value="false"
            :default="true"
        />
    </GroupElement>

    <GroupElement name="group_metering" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_metering" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Gauge class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Usage-based billing (optional)</h2>
                    <p class="product-form-section-copy">Charge an overage when a customer uses more than their plan includes</p>
                </div>
            </div>
        </StaticElement>

        <ToggleElement
            :columns="colThird"
            id="IsMetered"
            field-name="IsMetered"
            name="is_metered"
            label="Metered Plan"
            :true-value="true"
            :false-value="false"
            :default="false"
        />
        <TextElement id="UnitLabel" field-name="UnitLabel" name="unit_label" label="Unit Label" placeholder="e.g. API calls, hours" :columns="colThird" :conditions="[['is_metered', '==', true]]" />
        <TextElement id="IncludedUnits" field-name="IncludedUnits" name="included_units" label="Included Units" type="number" :columns="colQuarter" :conditions="[['is_metered', '==', true]]" />
        <TextElement id="OverageRate" field-name="OverageRate" name="overage_rate" label="Overage Rate (per unit)" type="number" :columns="colQuarter" :conditions="[['is_metered', '==', true]]" />
    </GroupElement>
</template>
