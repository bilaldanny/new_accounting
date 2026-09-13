<script setup lang="ts">
    import { Info } from '@lucide/vue';
    import { computed, onBeforeUnmount, ref, useSlots } from 'vue';

    withDefaults(defineProps<{
        label: string;
        required?: boolean;
    }>(), {
        required: false,
    });

    const slots = useSlots();
    const hasHint = computed(() => Boolean(slots.default));
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
        if (! hasHint.value) {
            return;
        }

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
            v-if="hasHint"
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
                v-if="open && hasHint"
                class="journal-field__tooltip"
                role="tooltip"
                :style="{ top: `${coords.top}px`, left: `${coords.left}px` }"
            >
                <slot />
            </div>
        </Teleport>
    </span>
</template>
