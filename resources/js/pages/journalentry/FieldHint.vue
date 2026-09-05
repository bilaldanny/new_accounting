<script setup lang="ts">
    import { Info } from '@lucide/vue';
    import { onBeforeUnmount, ref } from 'vue';

    withDefaults(defineProps<{
        label: string;
        required?: boolean;
    }>(), {
        required: false,
    });

    const open = ref(false);
    const trigger = ref<HTMLElement | null>(null);
    const coords = ref({ top: 0, left: 0 });

    function updatePosition(): void {
        if (! trigger.value) {
            return;
        }

        const rect = trigger.value.getBoundingClientRect();
        coords.value = {
            top: rect.top - 8,
            left: rect.left + rect.width / 2,
        };
    }

    function hide(): void {
        open.value = false;
        window.removeEventListener('scroll', hide, true);
        window.removeEventListener('resize', hide);
    }

    function show(): void {
        updatePosition();
        open.value = true;
        window.addEventListener('scroll', hide, true);
        window.addEventListener('resize', hide);
    }

    onBeforeUnmount(hide);
</script>

<template>
    <span class="journal-field__label">
        {{ label }}
        <span v-if="required" class="journal-field__required" aria-hidden="true">*</span>
        <span
            ref="trigger"
            class="journal-field__hint"
            role="button"
            tabindex="0"
            :aria-label="`${label} help`"
            :aria-expanded="open"
            @mouseenter="show"
            @mouseleave="hide"
            @focus="show"
            @blur="hide"
        >
            <Info :size="14" />
        </span>
        <Teleport to="body">
            <div
                v-if="open"
                class="journal-field__tooltip"
                role="tooltip"
                :style="{ top: `${coords.top}px`, left: `${coords.left}px` }"
            >
                <slot />
            </div>
        </Teleport>
    </span>
</template>
