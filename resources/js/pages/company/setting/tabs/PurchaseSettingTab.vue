<script setup lang="ts">
    import { computed } from 'vue';

    const props = defineProps({
        formData: { type: Object, required: true },
    });

    const purchaseColumns = computed(() => {
        const columns = props.formData?.purchase_column;

        return Array.isArray(columns) ? columns : [];
    });
</script>

<template>
    <div class="company-setting-tab-panel">
        <p class="company-setting-section-help mb-3">
            Control which columns appear on the purchase entry screen.
        </p>

        <div
            v-for="(item, index) in purchaseColumns"
            :key="index"
            class="row g-3 align-items-end mb-3"
        >
            <div class="col-md-4">
                <label :for="`purchase-column-name-${index}`" class="form-label">
                    Column {{ index }} Name
                </label>
                <input
                    :id="`purchase-column-name-${index}`"
                    v-model="item.name"
                    type="text"
                    class="form-control form-control-sm"
                    :name="`column[${index}][name]`"
                    placeholder="Column name"
                />
            </div>

            <div class="col-md-4">
                <label class="form-label d-block" :for="`purchase-column-show-${index}`">
                    Column {{ index }} Show / Hide
                </label>
                <div class="form-check form-switch">
                    <input
                        :id="`purchase-column-show-${index}`"
                        v-model="item.show"
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                    />
                    <label class="form-check-label" :for="`purchase-column-show-${index}`">
                        {{ item.show ? 'Yes' : 'No' }}
                    </label>
                </div>
                <small class="text-muted">Enable or disable this column on the purchase table.</small>
            </div>
        </div>
    </div>
</template>
