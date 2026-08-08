<script setup lang="ts">
import SkeletonShimmer from './SkeletonShimmer.vue';

const props = withDefaults(
    defineProps<{
        rows?: number;
        durationSec?: number;
    }>(),
    {
        rows: 10,
        durationSec: 1.55,
    },
);
</script>

<template>
    <div class="mm-active px-3 py-3" aria-hidden="true">
        <ul class="list-unstyled mb-0">
            <li v-for="n in props.rows" :key="n" class="mb-3">
                <SkeletonShimmer
                    v-if="n === 1 || n === 5"
                    height="10px"
                    width="38%"
                    rounded="md"
                    :duration-sec="props.durationSec"
                    custom-class="mb-2 ms-1 opacity-75"
                />
                <div class="d-flex align-items-center ps-1">
                    <SkeletonShimmer
                        :height="14"
                        :width="14"
                        rounded="md"
                        :duration-sec="props.durationSec"
                        custom-class="me-2 flex-shrink-0"
                    />
                    <SkeletonShimmer
                        height="14px"
                        :width="n % 4 === 0 ? '72%' : n % 4 === 1 ? '58%' : '64%'"
                        rounded="lg"
                        :duration-sec="props.durationSec"
                    />
                </div>
            </li>
        </ul>
    </div>
</template>
