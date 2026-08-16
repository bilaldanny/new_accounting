<script setup lang="ts">
    import { computed } from 'vue';
    import { accountOptionsForKey, type AccountSetupItem } from './constants';

    const props = defineProps({
        formData: { type: Object, required: true },
        branchesdata: { type: Array, default: () => [] },
        parentaccountdata: { type: Array, default: () => [] },
        parentsaleaccountdata: { type: Array, default: () => [] },
        parentpurchaseaccountdata: { type: Array, default: () => [] },
        showBranchFilter: { type: Boolean, default: false },
    });

    const emit = defineEmits<{
        'branch-change': [value: string | number];
    }>();

    const accountSetup = computed(() => {
        const items = props.formData?.account_setup;

        return Array.isArray(items) ? (items as AccountSetupItem[]) : [];
    });

    function handleBranchChange(event: Event) {
        const target = event.target as HTMLSelectElement;
        emit('branch-change', target.value);
    }

    function optionsForItem(item: AccountSetupItem) {
        return accountOptionsForKey(
            item.key,
            props.parentaccountdata as Array<{ id: string | number; text: string }>,
            props.parentsaleaccountdata as Array<{ id: string | number; text: string }>,
            props.parentpurchaseaccountdata as Array<{ id: string | number; text: string }>,
        );
    }
</script>

<template>
    <div class="company-setting-tab-panel">
        <p class="company-setting-section-help mb-3">
            Map default chart of accounts for each transaction type.
        </p>

        <div v-if="showBranchFilter" class="row g-3 mb-3">
            <div class="col-md-4">
                <label for="LinkAccountsBranch" class="form-label">Branch</label>
                <select
                    id="LinkAccountsBranch"
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

        <div v-if="accountSetup.length === 0" class="company-setting-empty-state">
            Select a branch to load account setup options.
        </div>

        <div v-else class="row g-3">
            <div
                v-for="(item, index) in accountSetup"
                :key="`${item.key ?? item.name}-${index}`"
                class="col-md-4"
            >
                <label :for="`account-setup-${index}`" class="form-label">{{ item.name }}</label>
                <select
                    :id="`account-setup-${index}`"
                    v-model="item.value"
                    class="form-select form-select-sm"
                >
                    <option value="">Select account</option>
                    <option
                        v-for="option in optionsForItem(item)"
                        :key="(option as { id: string | number }).id"
                        :value="(option as { id: string | number }).id"
                    >
                        {{ (option as { text: string }).text }}
                    </option>
                </select>
            </div>
        </div>
    </div>
</template>
