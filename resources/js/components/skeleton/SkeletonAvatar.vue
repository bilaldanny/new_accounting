<script setup lang="ts">
import SkeletonShimmer from './SkeletonShimmer.vue';

const props = withDefaults(
    defineProps<{
        size?: number;
        variant?: 'default' | 'inverted';
        durationSec?: number;
        customClass?: string;
    }>(),
    {
        size: 40,
        variant: 'default',
        durationSec: 1.5,
    },
);
</script>

<template>
    <SkeletonShimmer
        :width="props.size"
        :height="props.size"
        rounded="full"
        :variant="props.variant"
        :duration-sec="props.durationSec"
        :custom-class="props.customClass"
    />
</template>
