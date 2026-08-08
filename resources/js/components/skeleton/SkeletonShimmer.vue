<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** CSS width, e.g. `100%`, `12rem`, or number (px) */
        width?: string | number;
        /** CSS height */
        height?: string | number;
        rounded?: 'none' | 'md' | 'lg' | 'xl' | 'full';
        /** `inverted` = light shimmer on dark/colored backgrounds (e.g. primary cards) */
        variant?: 'default' | 'inverted';
        /** Animation loop duration in seconds (1.2–1.8 recommended) */
        durationSec?: number;
        customClass?: string;
    }>(),
    {
        width: '100%',
        height: '1rem',
        rounded: 'xl',
        variant: 'default',
        durationSec: 1.5,
    },
);

const roundedClass = computed(() => {
    const map = {
        none: 'rounded-none',
        md: 'rounded-md',
        lg: 'rounded-lg',
        xl: 'rounded-xl',
        full: 'rounded-full',
    } as const;
    return map[props.rounded];
});

const sizeStyle = computed(() => {
    const w = typeof props.width === 'number' ? `${props.width}px` : props.width;
    const h = typeof props.height === 'number' ? `${props.height}px` : props.height;
    return { width: w, height: h, '--tui-sk-duration': `${props.durationSec}s` } as Record<string, string>;
});

const variantClass = computed(() =>
    props.variant === 'inverted' ? 'tui-skeleton--inverted' : 'tui-skeleton--default',
);
</script>

<template>
    <div
        role="presentation"
        aria-hidden="true"
        class="tui-skeleton block min-h-[0.5rem] min-w-[0.5rem] shrink-0 shadow-sm"
        :class="[roundedClass, variantClass, props.customClass]"
        :style="sizeStyle"
    />
</template>
