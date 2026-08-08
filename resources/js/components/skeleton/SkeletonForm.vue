<script setup lang="ts">
import SkeletonShimmer from './SkeletonShimmer.vue';

const props = withDefaults(
    defineProps<{
        /** Number of label + field rows */
        fields?: number;
        /** Two-column grid on sm+ */
        columns?: 1 | 2;
        showActions?: boolean;
        durationSec?: number;
        customClass?: string;
    }>(),
    {
        fields: 4,
        columns: 2,
        showActions: true,
        durationSec: 1.5,
    },
);

const gridClass =
    props.columns === 2
        ? 'grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-x-6 sm:gap-y-5'
        : 'flex flex-col gap-5';
</script>

<template>
    <div
        class="rounded-xl border border-neutral-200/80 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-6"
        :class="props.customClass"
    >
        <div class="mb-6">
            <SkeletonShimmer height="20px" width="40%" rounded="lg" :duration-sec="props.durationSec" custom-class="mb-2" />
            <SkeletonShimmer height="12px" width="65%" rounded="lg" :duration-sec="props.durationSec" />
        </div>

        <div :class="gridClass">
            <div v-for="i in props.fields" :key="i" class="space-y-2">
                <SkeletonShimmer height="11px" width="32%" rounded="md" :duration-sec="props.durationSec" />
                <SkeletonShimmer height="40px" width="100%" rounded="xl" :duration-sec="props.durationSec" />
            </div>
        </div>

        <div v-if="props.showActions" class="mt-8 flex flex-wrap gap-3">
            <SkeletonShimmer height="40px" width="120px" rounded="xl" :duration-sec="props.durationSec" />
            <SkeletonShimmer height="40px" width="96px" rounded="xl" :duration-sec="props.durationSec" />
        </div>
    </div>
</template>
