<script setup lang="ts">
const props = withDefaults(
    defineProps<{
        title?: string;
        collapseId?: string;
        showFooter?: boolean;
        loading?: boolean;
    }>(),
    {
        title: 'Filter',
        collapseId: 'filterCollapse',
        showFooter: true,
        loading: false,
    },
);

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    clear: [];
    search: [];
}>();

function onPanelEnter(el: Element): void {
    const element = el as HTMLElement;
    element.style.height = '0';
    element.style.opacity = '0';
    element.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        element.style.transition = 'height 0.35s ease, opacity 0.35s ease';
        element.style.height = `${element.scrollHeight}px`;
        element.style.opacity = '1';
    });
}

function onPanelAfterEnter(el: Element): void {
    const element = el as HTMLElement;
    element.style.height = 'auto';
    element.style.overflow = '';
    element.style.transition = '';
}

function onPanelLeave(el: Element): void {
    const element = el as HTMLElement;
    element.style.height = `${element.scrollHeight}px`;
    element.style.opacity = '1';
    element.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        element.style.transition = 'height 0.35s ease, opacity 0.35s ease';
        element.style.height = '0';
        element.style.opacity = '0';
    });
}

function onPanelAfterLeave(el: Element): void {
    const element = el as HTMLElement;
    element.style.height = '';
    element.style.opacity = '';
    element.style.overflow = '';
    element.style.transition = '';
}
</script>

<template>
    <Transition
        @enter="onPanelEnter"
        @after-enter="onPanelAfterEnter"
        @leave="onPanelLeave"
        @after-leave="onPanelAfterLeave"
    >
        <div v-if="open" :id="props.collapseId" class="the-filter-panel modern-filter">
            <div class="the-filter-panel__body">
                <div class="row g-3 align-items-end">
                    <slot />
                </div>
            </div>
            <div v-if="props.showFooter" class="the-filter-panel__footer">
                <button
                    class="btn btn-sm btn-outline-dark d-inline-flex align-items-center"
                    type="button"
                    :disabled="props.loading"
                    @click="emit('clear')"
                >
                    <span
                        v-if="props.loading"
                        class="spinner-border spinner-border-sm me-1"
                        role="status"
                        aria-hidden="true"
                    ></span>
                    Clear
                </button>
                <button
                    class="btn btn-sm btn-outline-success d-inline-flex align-items-center"
                    type="button"
                    :disabled="props.loading"
                    @click="emit('search')"
                >
                    <span
                        v-if="props.loading"
                        class="spinner-border spinner-border-sm me-1"
                        role="status"
                        aria-hidden="true"
                    ></span>
                    Search
                </button>
            </div>
        </div>
    </Transition>
</template>

