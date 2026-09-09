<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import BackToTop from '@/components/BackToTop.vue';
import TheBreadcurm from '@/components/theBreadcurm.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
    title?: string;
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    title: () => '',
});
</script>

<template>
    <!--sidebar wrapper -->
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppSidebarHeader />
            <div class="page-wrapper">
                <div class="page-content" :class="{ 'page-content--flush': $page.props.routeName === 'dashboard' }">
                    <!-- start Breadcurm -->
                        <TheBreadcurm :breadcrumbs="breadcrumbs" :title="title" v-if="$page.props.routeName !== 'dashboard'"/>
                    <!-- end Breadcurm -->
                    <slot />
                </div>
            </div>
            <BackToTop />
            <AppFooter />
        </AppShell>
        <Toaster />
    <!--end sidebar wrapper -->
</template>
