<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Home } from '@boxicons/vue';

    const breadcurmProps = defineProps({
        breadcrumbs: Array,
        title: String,
    });

</script>

<template>
    <!-- Breadcrum -->
        <div class="page-breadcrumb d-sm-flex align-items-center mb-3">
            <div class="breadcrumb-title pe-3">{{ breadcurmProps.title ?? '' }}</div>
            <div class="ps-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0 p-0">
                        <li>
                            <Link :href="route('dashboard')">
                                <Home size="sm" class="breadcrumb-home-icon" style="width:16.5px;height:24px;"/>
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
                            <Link :href="item.href" v-if="item.href !== 'NULL'">
                                {{ item?.title }}
                            </Link>
                            <span v-else>{{ item?.title }}</span>
                        </li>
                    </ol>
                </nav>
            </div>
        </div>
    <!-- Breadcrum -->
</template>

<style scoped>
.breadcrumb-home-icon {
    display: block;
    fill: currentColor;
}
</style>
