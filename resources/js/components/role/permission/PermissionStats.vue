<script setup>
defineProps({
    totalModules: {
        type: Number,
        required: true,
    },
    grantedCount: {
        type: Number,
        required: true,
    },
    totalPermissions: {
        type: Number,
        required: true,
    },
    coveragePercent: {
        type: Number,
        required: true,
    },
});
</script>

<template>
    <div class="permission-stats">
        <div class="permission-stats__item">
            <span class="permission-stats__value">{{ totalModules }}</span>
            <span class="permission-stats__label">Modules</span>
        </div>
        <div class="permission-stats__item">
            <span class="permission-stats__value">{{ grantedCount }}</span>
            <span class="permission-stats__label">Granted</span>
        </div>
        <div class="permission-stats__item permission-stats__item--highlight">
            <span class="permission-stats__value">{{ coveragePercent }}%</span>
            <span class="permission-stats__label">Coverage</span>
        </div>
    </div>
</template>

<style scoped>
.permission-stats {
    display: flex;
    gap: 0.625rem;
    flex-shrink: 0;
}

.permission-stats__item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-width: 4.75rem;
    min-height: 4.25rem;
    padding: 0.5rem 0.75rem;
    border-radius: var(--app-radius-md, 9px);
    background: var(--app-background, #f8fafc);
    border: 1px solid var(--app-border, #e2e8f0);
}

.permission-stats__item--highlight {
    background: var(--app-success-soft, #f0fdf4);
    border-color: var(--app-success-border, #bbf7d0);
}

.permission-stats__value {
    font-size: 1.375rem;
    font-weight: 700;
    line-height: 1.1;
    color: var(--app-text, #111827);
}

.permission-stats__item--highlight .permission-stats__value {
    color: var(--app-success, #16a34a);
}

.permission-stats__label {
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--app-text-secondary, #64748b);
    margin-top: 0.25rem;
}

.permission-stats__item--highlight .permission-stats__label {
    color: var(--app-success, #16a34a);
}

@media (max-width: 575.98px) {
    .permission-stats {
        width: 100%;
    }

    .permission-stats__item {
        flex: 1;
    }
}
</style>
