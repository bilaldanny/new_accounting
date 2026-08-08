<script setup lang="ts">
import PageContentSkeleton from '@/components/skeleton/PageContentSkeleton.vue';
import SkeletonHeaderShell from '@/components/skeleton/SkeletonHeaderShell.vue';
import SkeletonSidebarNav from '@/components/skeleton/SkeletonSidebarNav.vue';
</script>

<template>
    <div id="layout-wrapper" aria-busy="true" aria-live="polite">
        <header id="page-topbar">
            <div class="navbar-header">
                <SkeletonHeaderShell :duration-sec="1.3" />
            </div>
        </header>

        <div class="vertical-menu mm-active">
            <div class="h-100">
                <div class="position-relative h-100 p-2">
                    <SkeletonSidebarNav :rows="11" :duration-sec="1.3" />
                </div>
            </div>
        </div>

        <div class="main-content">
            <div class="page-content">
                <div class="container-fluid">
                    <PageContentSkeleton :card-count="2" :lines-per-card="7" />
                </div>
            </div>
        </div>
    </div>
</template>
