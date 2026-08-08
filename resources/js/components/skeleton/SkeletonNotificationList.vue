<script setup lang="ts">
import SkeletonAvatar from './SkeletonAvatar.vue';
import SkeletonShimmer from './SkeletonShimmer.vue';

const props = withDefaults(
    defineProps<{
        rows?: number;
        durationSec?: number;
    }>(),
    {
        rows: 4,
        durationSec: 1.45,
    },
);
</script>

<template>
    <div class="px-2 py-2" aria-busy="true" role="status" aria-label="Loading notifications">
        <div
            v-for="i in props.rows"
            :key="'noti-sk-' + i"
            class="flex gap-3 border-b border-neutral-200/80 py-3 last:border-b-0 dark:border-zinc-700"
        >
            <SkeletonAvatar :size="36" :duration-sec="props.durationSec" custom-class="mt-0.5 shrink-0 shadow-sm" />
            <div class="min-w-0 flex-1 space-y-2 pt-0.5">
                <SkeletonShimmer
                    height="12px"
                    :width="i % 3 === 0 ? '88%' : i % 3 === 1 ? '72%' : '80%'"
                    rounded="lg"
                    :duration-sec="props.durationSec"
                    custom-class="shadow-sm"
                />
                <SkeletonShimmer
                    height="10px"
                    width="100%"
                    rounded="lg"
                    :duration-sec="props.durationSec"
                    custom-class="shadow-sm"
                />
                <SkeletonShimmer
                    height="9px"
                    width="36%"
                    rounded="md"
                    :duration-sec="props.durationSec"
                    custom-class="shadow-sm"
                />
            </div>
        </div>
    </div>
</template>
