<script setup lang="ts">
import SkeletonShimmer from './SkeletonShimmer.vue';

const props = withDefaults(
    defineProps<{
        /** Shimmer loop duration (1.2–1.8s recommended) */
        durationSec?: number;
    }>(),
    {
        durationSec: 1.45,
    },
);
</script>

<template>
    <div
        class="flex w-full min-h-[70px] max-h-[70px] flex-wrap items-center justify-between gap-3 px-1 sm:gap-4"
        aria-hidden="true"
        role="presentation"
    >
        <!-- Left: brand + menu toggle (matches .navbar-brand-box + vertical-menu-btn) -->
        <div class="flex min-w-0 flex-1 items-center gap-3 sm:gap-4">
            <div
                class="flex shrink-0 items-center gap-3 ps-3 sm:ps-4"
                style="width: min(100%, 255px)"
            >
                <SkeletonShimmer
                    :width="40"
                    :height="40"
                    rounded="xl"
                    :duration-sec="props.durationSec"
                    custom-class="shadow-sm"
                />
                <SkeletonShimmer
                    :width="128"
                    :height="26"
                    rounded="xl"
                    :duration-sec="props.durationSec"
                    custom-class="hidden shadow-sm sm:block"
                />
            </div>
            <SkeletonShimmer
                :width="44"
                :height="44"
                rounded="xl"
                :duration-sec="props.durationSec"
                custom-class="shrink-0 shadow-sm"
            />
        </div>

        <!-- Right: utility icons + user block -->
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 sm:gap-3">
            <SkeletonShimmer
                :width="42"
                :height="42"
                rounded="xl"
                :duration-sec="props.durationSec"
                custom-class="hidden shadow-sm lg:block"
            />
            <SkeletonShimmer
                :width="42"
                :height="42"
                rounded="xl"
                :duration-sec="props.durationSec"
                custom-class="shadow-sm"
            />
            <SkeletonShimmer
                :width="42"
                :height="42"
                rounded="xl"
                :duration-sec="props.durationSec"
                custom-class="shadow-sm"
            />
            <div class="ms-1 flex items-center gap-3">
                <SkeletonShimmer
                    :width="40"
                    :height="40"
                    rounded="full"
                    :duration-sec="props.durationSec"
                    custom-class="shadow-sm"
                />
                <div class="hidden min-w-[140px] max-w-[200px] flex-col gap-2 md:flex">
                    <SkeletonShimmer
                        height="13px"
                        width="100%"
                        rounded="lg"
                        :duration-sec="props.durationSec"
                        custom-class="max-w-[7.5rem] shadow-sm"
                    />
                    <SkeletonShimmer
                        height="11px"
                        width="100%"
                        rounded="lg"
                        :duration-sec="props.durationSec"
                        custom-class="max-w-[10rem] shadow-sm"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
