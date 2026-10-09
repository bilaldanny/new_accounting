<script setup lang="ts">
    import { Ticket } from '@lucide/vue';
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
    const colFull = { container: 12, label: 12, wrapper: 12 };
    const cardClasses = {
        ElementLayout: {
            container: 'product-form-card',
        },
        GroupElement: {
            wrapper: 'product-form-card__body',
        },
    };

    const types = [
        { value: 'percent', label: 'Percent off' },
        { value: 'fixed', label: 'Fixed amount off' },
    ];
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />

    <GroupElement name="group_coupon" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_coupon" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Ticket class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Coupon details</h2>
                    <p class="product-form-section-copy">A discount code applicable to a subscription invoice</p>
                </div>
            </div>
        </StaticElement>

        <TextElement id="Code" field-name="Code" name="code" label="Coupon Code" placeholder="e.g. SAVE10" :columns="colThird" rules="required|max:50" />
        <SelectElement
            name="type"
            :native="false"
            :items="types"
            id="Type"
            field-name="Type"
            label="Discount Type"
            :columns="colThird"
            label-prop="label"
            value-prop="value"
            :can-clear="false"
            rules="required"
        />
        <TextElement id="Value" field-name="Value" name="value" label="Discount Value" type="number" :columns="colThird" rules="required|min_value:0" />
        <TextElement id="MaxRedemptions" field-name="MaxRedemptions" name="max_redemptions" label="Max Redemptions" type="number" :columns="colThird" info="Leave empty for unlimited." />
        <DateElement id="ValidFrom" field-name="ValidFrom" name="valid_from" label="Valid From" :columns="colThird" />
        <DateElement id="ValidUntil" field-name="ValidUntil" name="valid_until" label="Valid Until" :columns="colThird" />

        <ToggleElement
            :labels="{ 1: 'Active', 0: 'Inactive' }"
            :columns="colThird"
            id="IsActive"
            field-name="IsActive"
            name="is_active"
            label="Coupon Status"
            :true-value="true"
            :false-value="false"
            :default="true"
        />
    </GroupElement>
</template>
