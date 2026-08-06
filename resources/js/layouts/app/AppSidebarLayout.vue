<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import AppFooter from '@/components/AppFooter.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <!--sidebar wrapper -->
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppSidebarHeader />
            <AppFooter />
        </AppShell>
        <Toaster />
    <!--end sidebar wrapper -->
</template>
