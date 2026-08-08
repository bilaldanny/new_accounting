<script setup lang="ts">
import SkeletonShimmer from './SkeletonShimmer.vue';

const props = withDefaults(
    defineProps<{
        items?: number;
        showAvatar?: boolean;
        durationSec?: number;
        customClass?: string;
    }>(),
    {
        items: 5,
        showAvatar: true,
        durationSec: 1.5,
    },
);
</script>

<template>
    <ul class="space-y-3" :class="props.customClass" role="presentation">
        <li
            v-for="i in props.items"
            :key="i"
            class="flex items-center gap-3 rounded-xl border border-neutral-200/70 p-3 shadow-sm dark:border-zinc-700"
        >
            <SkeletonShimmer
                v-if="props.showAvatar"
                :width="40"
                :height="40"
                rounded="full"
                :duration-sec="props.durationSec"
            />
            <div class="min-w-0 flex-1 space-y-2">
                <SkeletonShimmer height="13px" width="70%" rounded="lg" :duration-sec="props.durationSec" />
                <SkeletonShimmer height="11px" width="45%" rounded="lg" :duration-sec="props.durationSec" />
            </div>
        </li>
    </ul>
</template>
