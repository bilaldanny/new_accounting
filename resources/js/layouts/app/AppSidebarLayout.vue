<script setup lang="ts">
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import AppFooter from '@/components/AppFooter.vue';
import BackToTop from '@/components/BackToTop.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';
import TheBreadcurm from '@/components/theBreadcurm.vue';

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
                <div class="page-content">
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
