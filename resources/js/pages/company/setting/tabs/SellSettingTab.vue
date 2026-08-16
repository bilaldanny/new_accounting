<script setup lang="ts">
    import { computed } from 'vue';

    const props = defineProps({
        formData: { type: Object, required: true },
        branchesdata: { type: Array, default: () => [] },
        customersdata: { type: Array, default: () => [] },
        showBranchFilter: { type: Boolean, default: false },
    });

    const emit = defineEmits<{
        'branch-change': [value: string | number];
    }>();

    const customerSelectDisabled = computed(() => {
        return !props.formData?.company_id || !props.formData?.branch_id;
    });

    function handleBranchChange(event: Event) {
        const target = event.target as HTMLSelectElement;
        emit('branch-change', target.value);
    }
</script>

<template>
    <div class="company-setting-tab-panel">
        <div v-if="showBranchFilter" class="row g-3 mb-3">
            <div class="col-md-4">
                <label for="SellSettingBranch" class="form-label">Branch</label>
                <select
                    id="SellSettingBranch"
                    v-model="formData.branch_id"
                    class="form-select form-select-sm"
                    @change="handleBranchChange"
                >
                    <option value="">Select branch</option>
                    <option
                        v-for="branch in branchesdata"
                        :key="(branch as { id: string | number }).id"
                        :value="(branch as { id: string | number }).id"
                    >
                        {{ (branch as { text: string }).text }}
                    </option>
                </select>
            </div>
        </div>

        <hr v-if="showBranchFilter" class="company-setting-divider" />

        <div class="row g-3">
            <div class="col-md-4">
                <label for="SellSettingDefaultCustomer" class="form-label">Default Customer</label>
                <select
                    id="SellSettingDefaultCustomer"
                    v-model="formData.default_customer"
                    class="form-select form-select-sm"
                    :disabled="customerSelectDisabled"
                >
                    <option value="">Select default customer</option>
                    <option
                        v-for="customer in customersdata"
                        :key="(customer as { id: string | number }).id"
                        :value="(customer as { id: string | number }).id"
                    >
                        {{ (customer as { text: string }).text }}
                    </option>
                </select>
                <small class="text-muted d-block mt-1">Default customer used on sell screens.</small>
            </div>
        </div>
    </div>
</template>
