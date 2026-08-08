<script setup lang="ts">
import SkeletonShimmer from './SkeletonShimmer.vue';
import SkeletonAvatar from './SkeletonAvatar.vue';

const props = withDefaults(
    defineProps<{
        showHeader?: boolean;
        lines?: number;
        showMedia?: boolean;
        mediaHeight?: string | number;
        variant?: 'default' | 'inverted';
        durationSec?: number;
        customClass?: string;
    }>(),
    {
        showHeader: true,
        lines: 3,
        showMedia: false,
        mediaHeight: 140,
        variant: 'default',
        durationSec: 1.5,
    },
);

const lineWidths = ['w-full', 'max-w-[92%]', 'max-w-[80%]', 'w-full', 'max-w-[70%]'] as const;
</script>

<template>
    <div
        class="rounded-xl border border-neutral-200/80 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
        :class="props.customClass"
    >
        <SkeletonShimmer
            v-if="props.showMedia"
            :height="props.mediaHeight"
            width="100%"
            rounded="xl"
            :variant="props.variant"
            :duration-sec="props.durationSec"
            custom-class="mb-4"
        />

        <div v-if="props.showHeader" class="mb-4 flex items-center gap-3">
            <SkeletonAvatar :size="44" :variant="props.variant" :duration-sec="props.durationSec" />
            <div class="min-w-0 flex-1 space-y-2">
                <SkeletonShimmer height="14px" width="55%" rounded="lg" :variant="props.variant" :duration-sec="props.durationSec" />
                <SkeletonShimmer height="10px" width="35%" rounded="lg" :variant="props.variant" :duration-sec="props.durationSec" />
            </div>
        </div>

        <div class="space-y-3">
            <SkeletonShimmer
                v-for="i in props.lines"
                :key="i"
                height="12px"
                rounded="lg"
                :variant="props.variant"
                :duration-sec="props.durationSec"
                :custom-class="lineWidths[(i - 1) % lineWidths.length]"
            />
        </div>
    </div>
</template>
