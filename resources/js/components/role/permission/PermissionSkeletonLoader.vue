<script setup>
import SkeletonShimmer from '@/components/skeleton/SkeletonShimmer.vue';

defineProps({
    count: {
        type: Number,
        default: 12,
    },
});
</script>

<template>
    <div class="permission-skeleton" aria-busy="true" aria-label="Loading permissions">
        <div class="permission-skeleton__header card custom-card">
            <div class="card-body permission-skeleton__header-body">
                <div class="permission-skeleton__inputs-wrap">
                    <div class="permission-skeleton__inputs">
                        <SkeletonShimmer height="40px" rounded="lg" />
                        <SkeletonShimmer height="40px" rounded="lg" />
                    </div>
                </div>
                <div class="permission-skeleton__stats">
                    <SkeletonShimmer width="88px" height="72px" rounded="lg" />
                    <SkeletonShimmer width="88px" height="72px" rounded="lg" />
                </div>
            </div>
        </div>

        <div class="row g-3 my-grid permission-skeleton__grid">
            <div
                v-for="n in count"
                :key="n"
                class="col-xxl-3 col-xl-3 col-lg-4 col-md-6 col-sm-12 my-card"
            >
                <div class="permission-skeleton__card card custom-card h-100">
                    <div class="card-body">
                        <div class="permission-skeleton__card-header">
                            <SkeletonShimmer width="18px" height="18px" rounded="md" />
                            <SkeletonShimmer height="14px" width="52%" rounded="lg" />
                            <SkeletonShimmer height="20px" width="32px" rounded="full" custom-class="ms-auto" />
                            <SkeletonShimmer height="24px" width="56px" rounded="lg" />
                        </div>
                        <div class="permission-skeleton__card-lines">
                            <SkeletonShimmer height="12px" width="88%" rounded="lg" />
                            <SkeletonShimmer height="12px" width="76%" rounded="lg" />
                            <SkeletonShimmer height="12px" width="82%" rounded="lg" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.permission-skeleton__header {
    border-radius: 0.75rem;
    margin-bottom: 1.25rem;
    border: 1px solid rgba(226, 227, 231, 0.8);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
}

.permission-skeleton__header-body {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1rem 1.25rem;
    padding: 1rem 1.25rem;
}

.permission-skeleton__inputs-wrap {
    flex: 1 1 320px;
    min-width: 0;
    padding: 0.875rem 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.625rem;
    background: #fafbfc;
}

.permission-skeleton__inputs {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
}

.permission-skeleton__stats {
    display: flex;
    gap: 0.75rem;
    flex-shrink: 0;
}

.permission-skeleton__card {
    border-radius: 0.75rem;
    border: 1px solid rgba(25, 150, 131, 0.1);
    min-height: 7.5rem;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
}

.permission-skeleton__card-header {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    margin-bottom: 0.875rem;
}

.permission-skeleton__card-lines {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-left: 0.35rem;
    padding-left: 0.875rem;
    border-left: 2px solid rgba(226, 227, 231, 0.9);
}

@media (max-width: 991.98px) {
    .permission-skeleton__stats {
        width: 100%;
        justify-content: stretch;
    }

    .permission-skeleton__stats > * {
        flex: 1;
    }
}
</style>
