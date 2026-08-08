<script setup lang="ts">
withDefaults(
    defineProps<{
        /** Accessible loading message */
        message?: string;
        /** Skeleton field placeholders (3-column grid) */
        fields?: number;
    }>(),
    {
        message: 'Loading form…',
        fields: 9,
    },
);
</script>

<template>
    <div class="modal-form-loader" role="status" aria-live="polite" aria-busy="true">
        <div class="modal-form-loader__status">
            <span class="modal-form-loader__spinner" aria-hidden="true"></span>
            <span class="modal-form-loader__message">{{ message }}</span>
        </div>

        <div class="modal-form-loader__grid">
            <div v-for="i in fields" :key="i" class="modal-form-loader__field">
                <div class="modal-form-loader__shimmer modal-form-loader__label"></div>
                <div class="modal-form-loader__shimmer modal-form-loader__input"></div>
            </div>
        </div>
    </div>
</template>

<style lang="css" scoped>
.modal-form-loader {
    width: 100%;
    min-height: 280px;
    padding: 0.25rem 0 0.5rem;
}

.modal-form-loader__status {
    display: inline-flex;
    align-items: center;
    gap: 0.625rem;
    margin-bottom: 1.25rem;
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
}

.modal-form-loader__spinner {
    width: 1rem;
    height: 1rem;
    border: 2px solid #e2e8f0;
    border-top-color: #6366f1;
    border-radius: 50%;
    animation: modal-form-loader-spin 0.7s linear infinite;
    flex-shrink: 0;
}

.modal-form-loader__message {
    font-size: 0.8125rem;
    font-weight: 500;
    color: #64748b;
    letter-spacing: 0.01em;
}

.modal-form-loader__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem 1.25rem;
}

.modal-form-loader__field {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    min-width: 0;
}

.modal-form-loader__shimmer {
    position: relative;
    overflow: hidden;
    background: #f1f5f9;
    border-radius: 8px;
}

.modal-form-loader__shimmer::after {
    content: '';
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(
        90deg,
        rgba(255, 255, 255, 0) 0%,
        rgba(255, 255, 255, 0.65) 50%,
        rgba(255, 255, 255, 0) 100%
    );
    animation: modal-form-loader-shimmer 1.4s ease-in-out infinite;
}

.modal-form-loader__label {
    height: 0.6875rem;
    width: 42%;
    border-radius: 6px;
}

.modal-form-loader__input {
    height: 2.375rem;
    width: 100%;
    border-radius: 8px;
    border: 1px solid #eef2f7;
}

.modal-form-loader__field:nth-child(3n + 2) .modal-form-loader__label {
    width: 55%;
}

.modal-form-loader__field:nth-child(3n) .modal-form-loader__label {
    width: 38%;
}

@keyframes modal-form-loader-spin {
    from {
        transform: rotate(0deg);
    }

    to {
        transform: rotate(360deg);
    }
}

@keyframes modal-form-loader-shimmer {
    100% {
        transform: translateX(100%);
    }
}

@media (max-width: 767.98px) {
    .modal-form-loader__grid {
        grid-template-columns: 1fr;
    }
}

@media (prefers-reduced-motion: reduce) {
    .modal-form-loader__spinner,
    .modal-form-loader__shimmer::after {
        animation: none;
    }
}
</style>
