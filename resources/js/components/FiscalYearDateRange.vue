<script setup lang="ts">
    import type { ActiveFinancialYear } from '@/composables/activeFinancialYear';
    import { computed, onMounted, ref } from 'vue';

    const startDate = defineModel<string>('startDate', { required: true });
    const endDate = defineModel<string>('endDate', { required: true });

    withDefaults(
        defineProps<{
            fiscalYear?: ActiveFinancialYear | null;
            idPrefix?: string;
        }>(),
        {
            fiscalYear: null,
            idPrefix: 'ledger',
        },
    );

    const emit = defineEmits<{
        change: [field: 'start_date' | 'end_date'];
    }>();

    const isMounted = ref(false);
    const dateColumns = { container: 12, label: 12, wrapper: 12 };

    const rangeValue = computed(() => {
        if (!startDate.value || !endDate.value) {
            return [];
        }

        return [startDate.value, endDate.value];
    });

    const formValue = computed({
        get: () => ({
            period: rangeValue.value,
        }),
        set: (value: { period?: Array<string | null> | null }) => {
            const period = Array.isArray(value.period) ? value.period : [];
            const nextStart = String(period[0] ?? '').slice(0, 10);
            const nextEnd = String(period[1] ?? '').slice(0, 10);

            if (!nextStart || !nextEnd) {
                return;
            }

            const startChanged = nextStart !== startDate.value;
            const endChanged = nextEnd !== endDate.value;

            if (startChanged) {
                startDate.value = nextStart;
            }

            if (endChanged) {
                endDate.value = nextEnd;
            }

            if (startChanged || endChanged) {
                emit('change', startChanged ? 'start_date' : 'end_date');
            }
        },
    });

    const rangeOptions = {
        mode: 'range',
        showMonths: 2,
        locale: {
            rangeSeparator: ' → ',
        },
    };

    onMounted(() => {
        isMounted.value = true;
    });
</script>

<template>
    <div class="fiscal-year-date-range">
        <div v-if="!isMounted" class="fiscal-year-date-range__placeholder" aria-hidden="true">
            <span class="fiscal-year-date-range__placeholder-label">Period</span>
            <span class="fiscal-year-date-range__placeholder-input">
                {{ startDate }} → {{ endDate }}
            </span>
        </div>

        <Vueform
            v-else
            v-model="formValue"
            sync
            :endpoint="false"
            size="sm"
            :display-errors="false"
            class="fiscal-year-date-range__form"
            @submit.prevent
        >
            <DatesElement
                :id="`${idPrefix}-period`"
                name="period"
                label="Period"
                placeholder="Select date range"
                mode="range"
                :default="rangeValue"
                :floating="false"
                :min="fiscalYear?.start_date"
                :max="fiscalYear?.end_date"
                value-format="YYYY-MM-DD"
                display-format="MMM D, YYYY"
                :extend-options="rangeOptions"
                :columns="dateColumns"
            />
        </Vueform>
    </div>
</template>

<style scoped>
.fiscal-year-date-range {
    flex: 0 1 auto;
    width: auto;
    min-width: 16.5rem;
    max-width: 100%;
    padding: 0.75rem;
    border-radius: 0.875rem;
    background: #f8fafc;
    border: 1px solid #eef2f7;
}

.fiscal-year-date-range__form :deep(form),
.fiscal-year-date-range__form :deep(.row) {
    --bs-gutter-x: 0;
    --bs-gutter-y: 0;
    margin: 0;
}

.fiscal-year-date-range__form :deep([class*='col-']) {
    padding: 0;
    margin: 0;
}

.fiscal-year-date-range__placeholder-label,
.fiscal-year-date-range :deep(.vf-label),
.fiscal-year-date-range :deep(label) {
    display: block;
    margin-bottom: 0.375rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--app-text-secondary, #64748b);
}

.fiscal-year-date-range__placeholder-input,
.fiscal-year-date-range :deep(.form-control),
.fiscal-year-date-range :deep(.dp__input) {
    display: flex;
    align-items: center;
    min-height: 2.375rem;
    min-width: 16.5rem;
    padding: 0.375rem 0.75rem;
    border: 1px solid var(--app-border, #e5e7eb);
    border-radius: 0.625rem;
    background: #fff;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.03);
    color: var(--app-text, #111827);
    font-size: 0.8125rem;
}

.fiscal-year-date-range :deep(.dp__menu),
.fiscal-year-date-range :deep(.datepicker),
.fiscal-year-date-range :deep(.flatpickr-calendar) {
    z-index: 30;
}

@media (max-width: 767.98px) {
    .fiscal-year-date-range,
    .fiscal-year-date-range__placeholder-input,
    .fiscal-year-date-range :deep(.form-control),
    .fiscal-year-date-range :deep(.dp__input) {
        width: 100%;
        min-width: 0;
    }
}
</style>
