<script setup lang="ts">
    import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
    import AppSwitcher from '@/components/AppSwitcher.vue';
    import InActivity from '@/components/InActivity.vue';
    import InternetDetector from '@/components/InternetDetector.vue';
    import type { BreadcrumbItem } from '@/types';
    const { breadcrumbs = [] } = defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>();
</script>

<template>
    <vue-notification-list position="top-right"></vue-notification-list>

    <!-- Inactivity -->
        <InActivity></InActivity>
    <!-- Inactivity -->
    
    <!--wrapper-->
	<div class="wrapper">
        <AppLayout :breadcrumbs="breadcrumbs">
            
            <InternetDetector></InternetDetector>

            <slot />
        </AppLayout>
	</div>
	<!--end wrapper-->

    <AppSwitcher />
	
</template>