<script setup lang="ts">
    import { computed } from 'vue';
    import { usePage } from '@inertiajs/vue3';
    import AppSiderbarLayout from '@/layouts/app/AppSidebarLayout.vue';
    import AppSwitcher from '@/components/AppSwitcher.vue';
    import InActivity from '@/components/InActivity.vue';
    import InternetDetector from '@/components/InternetDetector.vue';
    import useCommons from '@/composables/common';
    import type { BreadcrumbItem } from '@/types';

    const { breadcrumbs = [], title = '' } = defineProps<{
        breadcrumbs?: BreadcrumbItem[];
        title?: string;
    }>();

    const page = usePage();
    const { formatedText } = useCommons();
    const layoutTitle = computed(() => title || formatedText(String(page.props.routeName ?? '')));
</script>

<template>
    <vue-notification-list position="top-right"></vue-notification-list>

    <!-- Inactivity -->
        <InActivity></InActivity>
    <!-- Inactivity -->
    
    <!--wrapper-->
	<div class="wrapper">
        <AppSiderbarLayout :breadcrumbs="breadcrumbs" :title="layoutTitle">
            <InternetDetector></InternetDetector>
            <slot />
        </AppSiderbarLayout>
	</div>
	<!--end wrapper-->

    <AppSwitcher />
	
</template>