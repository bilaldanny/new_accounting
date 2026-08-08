<script setup lang="ts">
import { computed } from 'vue';
import SkeletonShimmer from './SkeletonShimmer.vue';

const props = withDefaults(
    defineProps<{
        columns: number;
        rows?: number;
        cellHeight?: number;
        durationSec?: number;
    }>(),
    {
        rows: 8,
        cellHeight: 14,
        durationSec: 1.5,
    },
);

const safeCols = computed(() => Math.max(1, props.columns));
const safeRows = computed(() => Math.max(1, Math.min(props.rows, 20)));

function cellWidth(c: number): string {
    const m = c % 3;
    if (m === 0) return '92%';
    if (m === 1) return '68%';
    return '42%';
}
</script>

<template>
    <tr v-for="r in safeRows" :key="'sk-r-' + r">
        <td v-for="c in safeCols" :key="'sk-' + r + '-' + c" class="align-middle px-3 py-3">
            <SkeletonShimmer
                :height="cellHeight"
                :width="cellWidth(c)"
                rounded="md"
                :duration-sec="durationSec"
            />
        </td>
    </tr>
</template>
