<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Home } from '@boxicons/vue';
import { dashboard } from '@/routes';

    const breadcurmProps = defineProps({
        breadcrumbs: Array,
        title: String,
    });

</script>

<template>
    <div class="page-breadcrumb modern-page-header d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
        <h1 class="modern-page-header__title">{{ breadcurmProps.title ?? '' }}</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb modern-page-header__crumb mb-0 p-0">
                <li class="breadcrumb-item">
                    <Link :href="dashboard()" class="modern-page-header__home">
                        <Home size="sm" class="breadcrumb-home-icon" />
                        <span>Home</span>
                    </Link>
                </li>
                <li
                    v-for="(item,index) in breadcurmProps.breadcrumbs"
                    :class="[
                        'breadcrumb-item',
                        (item.href === 'NULL' || item.href === '')?'active':''
                    ]"
                    :key="index"
                >
                    <ChevronRight size="sm" class="modern-page-header__sep" aria-hidden="true" />
                    <Link :href="item.href" v-if="item.href !== 'NULL'">
                        {{ item?.title }}
                    </Link>
                    <span v-else>{{ item?.title }}</span>
                </li>
            </ol>
        </nav>
    </div>
</template>

<style scoped>
.breadcrumb-home-icon {
    display: block;
    width: 0.875rem;
    height: 0.875rem;
    fill: currentColor;
}

.modern-page-header__crumb {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.25rem;
    list-style: none;
}

.modern-page-header__crumb .breadcrumb-item {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.modern-page-header__crumb .breadcrumb-item + .breadcrumb-item::before {
    display: none;
    content: none;
}

.modern-page-header__home {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    color: #0d9488;
    font-size: 0.75rem;
    font-weight: 500;
    text-decoration: none;
}

.modern-page-header__home:hover {
    color: #0f766e;
}

.modern-page-header__sep {
    width: 0.75rem;
    height: 0.75rem;
    fill: #94a3b8;
}

.modern-page-header__crumb .breadcrumb-item,
.modern-page-header__crumb .breadcrumb-item span {
    color: #334155;
    font-size: 0.75rem;
    font-weight: 600;
}
</style>
