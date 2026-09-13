<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';
    import AppSwitcher from '@/components/AppSwitcher.vue';
    import InActivity from '@/components/InActivity.vue';
    import InternetDetector from '@/components/InternetDetector.vue';
    import useCommons from '@/composables/common';
    import useSidebarToggle from '@/composables/useSidebarToggle';
    import AppSiderbarLayout from '@/layouts/app/AppSidebarLayout.vue';
    import type { BreadcrumbItem } from '@/types';

    const { breadcrumbs = [], title = '' } = defineProps<{
        breadcrumbs?: BreadcrumbItem[];
        title?: string;
    }>();

    const page = usePage();
    const { formatedText } = useCommons();
    const layoutTitle = computed(() => title || formatedText(String(page.props.routeName ?? '')));
    const { isSidebarToggled, isSidebarHovered } = useSidebarToggle();
</script>

<template>
    <vue-notification-list position="top-right"></vue-notification-list>

    <!-- Inactivity -->
        <InActivity></InActivity>
    <!-- Inactivity -->

    <!--wrapper-->
	<div class="wrapper" :class="{ toggled: isSidebarToggled, 'sidebar-hovered': isSidebarHovered }">
        <AppSiderbarLayout :breadcrumbs="breadcrumbs" :title="layoutTitle">
            <InternetDetector></InternetDetector>
            <slot />
        </AppSiderbarLayout>
	</div>
	<!--end wrapper-->

    <AppSwitcher />
	
</template>