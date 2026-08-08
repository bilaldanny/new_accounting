<script setup lang="ts">
import SkeletonCard from '@/components/skeleton/SkeletonCard.vue';
import SkeletonShimmer from '@/components/skeleton/SkeletonShimmer.vue';

withDefaults(
    defineProps<{
        cardCount?: number;
        linesPerCard?: number;
    }>(),
    {
        cardCount: 2,
        linesPerCard: 6,
    },
);
</script>

<template>
    <div class="page-content-skeleton" role="status" aria-live="polite" aria-busy="true">
        <div class="mb-3">
            <SkeletonShimmer width="220px" height="24px" />
            <SkeletonShimmer width="320px" height="14px" class="mt-2" />
        </div>

        <div class="row g-3">
            <div v-for="card in cardCount" :key="card" class="col-12">
                <SkeletonCard :duration-sec="1.35">
                    <div class="d-flex flex-column gap-2 p-2">
                        <SkeletonShimmer width="35%" height="16px" />
                        <SkeletonShimmer
                            v-for="line in linesPerCard"
                            :key="line"
                            :width="line % 2 === 0 ? '100%' : '92%'"
                            height="12px"
                        />
                    </div>
                </SkeletonCard>
            </div>
        </div>
    </div>
</template>
