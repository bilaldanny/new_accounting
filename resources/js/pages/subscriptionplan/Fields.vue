<script setup lang="ts">
    import { Layers, Gauge } from '@lucide/vue';
    import { computed } from 'vue';

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

    const isEdit = computed(() => params.type === 'edit');

    const colThird = { container: 4, label: 12, wrapper: 12 };
    const colQuarter = { container: 3, label: 12, wrapper: 12 };
    const colFull = { container: 12, label: 12, wrapper: 12 };
    const cardClasses = {
        ElementLayout: {
            container: 'product-form-card',
        },
        GroupElement: {
            wrapper: 'product-form-card__body',
        },
    };

    const billingCycles = [
        { value: 'monthly', label: 'Monthly' },
        { value: 'quarterly', label: 'Quarterly' },
        { value: 'annual', label: 'Annual' },
    ];
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />

    <GroupElement name="group_plan" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_plan" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Layers class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Plan details</h2>
                    <p class="product-form-section-copy">The name, code and price tenants subscribe to</p>
                </div>
            </div>
        </StaticElement>

        <TextElement id="Name" field-name="Name" name="name" label="Plan Name" placeholder="e.g. Standard" :columns="colThird" rules="required|min:2|max:150" />
        <TextElement id="Code" field-name="Code" name="code" label="Plan Code" placeholder="e.g. STANDARD" :columns="colThird" rules="required|max:50" />
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
        <TextElement id="TrialDays" field-name="TrialDays" name="trial_days" label="Trial Days" type="number" :columns="colThird" default="14" />
        <TextElement id="SortOrder" field-name="SortOrder" name="sort_order" label="Sort Order" type="number" :columns="colThird" default="0" />

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
            info="Inactive plans cannot be assigned to a new tenant."
        />
    </GroupElement>

    <GroupElement name="group_limits" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_limits" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Gauge class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Limits</h2>
                    <p class="product-form-section-copy">What a tenant on this plan is allowed</p>
                </div>
            </div>
        </StaticElement>

        <TextElement id="MaxUsers" field-name="MaxUsers" name="max_users" label="Max Users" type="number" :columns="colQuarter" default="10" />
        <TextElement id="MaxBranches" field-name="MaxBranches" name="max_branches" label="Max Branches" type="number" :columns="colQuarter" default="2" />
        <TextElement id="MaxWarehouses" field-name="MaxWarehouses" name="max_warehouses" label="Max Warehouses" type="number" :columns="colQuarter" default="2" />
        <TextElement id="MaxProducts" field-name="MaxProducts" name="max_products" label="Max Products" type="number" :columns="colQuarter" default="500" />
        <TextElement id="MaxInvoicesPerMonth" field-name="MaxInvoicesPerMonth" name="max_invoices_per_month" label="Max Invoices / Month" type="number" :columns="colQuarter" default="200" />
        <TextElement id="MaxStorageMb" field-name="MaxStorageMb" name="max_storage_mb" label="Max Storage (MB)" type="number" :columns="colQuarter" default="500" />
    </GroupElement>
</template>
