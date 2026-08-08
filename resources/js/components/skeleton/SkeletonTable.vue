<script setup lang="ts">
import { computed } from 'vue';
import SkeletonShimmer from './SkeletonShimmer.vue';
import SkeletonTableRows from './SkeletonTableRows.vue';

const props = withDefaults(
    defineProps<{
        columns: number;
        rows?: number;
        showHeader?: boolean;
        cellHeight?: number;
        durationSec?: number;
        tableClass?: string;
    }>(),
    {
        rows: 8,
        showHeader: true,
        cellHeight: 14,
        durationSec: 1.5,
        tableClass: 'table table-striped table-bordered mb-0',
    },
);

const safeCols = computed(() => Math.max(1, props.columns));
</script>

<template>
    <div class="w-full overflow-x-auto rounded-xl border border-neutral-200/80 shadow-sm dark:border-zinc-700">
        <table :class="tableClass" aria-hidden="true">
            <thead v-if="showHeader">
                <tr>
                    <th v-for="c in safeCols" :key="'h-' + c" class="px-3 py-3 align-middle">
                        <SkeletonShimmer
                            :height="cellHeight"
                            width="72%"
                            rounded="md"
                            :duration-sec="durationSec"
                        />
                    </th>
                </tr>
            </thead>
            <tbody>
                <SkeletonTableRows
                    :columns="columns"
                    :rows="rows"
                    :cell-height="cellHeight"
                    :duration-sec="durationSec"
                />
            </tbody>
        </table>
    </div>
</template>
