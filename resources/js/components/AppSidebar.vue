<script setup lang="ts">
    import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import simplebar from 'simplebar-vue';
    import 'simplebar-vue/dist/simplebar.min.css';
    import { MetisMenu } from 'metismenujs';
    import 'metismenujs/sass';
    import useNotifications from '@/composables/notifications';
    import { dashboard } from '@/routes';
    import MenuIcon from '@/components/MenuIcon.vue';

    const { countsByType, getNotificationTypeFromRoute, fetchCountsByType } = useNotifications();
    const page = usePage();
    const permission = computed(() =>
        ((page.props.auth.user?.permissions as Array<{ route_name?: string }>) ?? []).filter(
            (item) => item.route_name !== 'dashboard',
        ),
    );
    const sideMenuRef = ref<HTMLElement | null>(null);
    let metisInstance: MetisMenu | null = null;

    const initMetisMenu = async () => {
        await nextTick();
        if (!sideMenuRef.value) return;

        metisInstance?.dispose();
        metisInstance = new MetisMenu(sideMenuRef.value);
    };

    const getBadgeCount = (routePath: string | null): number => {
        const type = getNotificationTypeFromRoute(routePath);
        return type ? (countsByType.value[type] ?? 0) : 0;
    };

    onMounted(() => {
        initMetisMenu();
        fetchCountsByType();
    });

    router.on('finish', () => {
        initMetisMenu();
    });

    onBeforeUnmount(() => {
        metisInstance?.dispose();
        metisInstance = null;
    });
    
</script>

<template>
    <!--sidebar wrapper -->
        <simplebar class="sidebar-wrapper">
            <div>
                <div class="sidebar-header">
                    <div>
                        <img src="assets/images/logo-icon.png" class="logo-icon" alt="logo icon">
                    </div>
                    <div>
                        <h4 class="logo-text">Synadmin</h4>
                    </div>
                    <div class="toggle-icon ms-auto"><i class='bx bx-first-page'></i>
                    </div>
                </div>
                <!--navigation-->
                <ul ref="sideMenuRef" class="metismenu" id="menu">
                    <li :class="page.props.routeName === 'dashboard' ? 'mm-active' : ''">
                        <Link
                            :href="dashboard()"
                            :class="page.props.routeName === 'dashboard' ? 'active' : ''"
                        >
                            <div class="parent-icon"><MenuIcon icon="Home" /></div>
                            <div class="menu-title">Dashboard</div>
                        </Link>
                    </li>
                    <span v-for="item,index in permission" :key="item.id">
                        <li v-if="item?.type === 3 && item?.status === 1" class="menu-label">{{ item?.name }}</li>
                        <li v-if="item.parent_id === '' && item.is_hidden === false && item.type === 1 && item.status === 1" :class="(page.props.routeName === item.route_name)?'mm-active':''">
                            <Link :href="item.my_route" :class="(page.props.routeName === item.route_name)?'active':''" v-if="item.my_route !== null">
                                <div class="parent-icon"><MenuIcon :icon="item.icon" /></div>
                                <div class="menu-title">{{ item.name }}</div>
                                <span class="badge rounded-pill bg-danger float-end" v-if="getBadgeCount(item.my_route) > 0">{{ getBadgeCount(item.my_route) > 99 ? '99+' : getBadgeCount(item.my_route) }}</span>
                            </Link>
                        </li>
                        <li
                            v-if="item.parent_id === '' && item.type === 2 && item.status === 1 && item.is_hidden === false"
                            :class="(page.props.routeName === item.route_name)?'mm-active':''"
                        >
                            <a
                                href="javascript:void(0);"
                                :class="[
                                    (item.children?.length ?? 0) > 0 ? 'has-arrow' : '',
                                    page.props.routeName === item.route_name ? 'waves-effect active' : 'waves-effect'
                                ]"
                            >
                                <div class="parent-icon"><MenuIcon :icon="item.icon" /></div>
                                <div class="menu-title">{{ item.name }}</div>
                            </a>
                            <ul
                                class="sub-menu"
                                aria-expanded="true"
                                v-if="(item.children?.length ?? 0) > 0"
                            >
                                <span v-for="item1,index1 in item.children" :key="item1.id">
                                    <li v-if="(item1.children?.length ?? 0) === 0">
                                        <Link
                                            :href="item1.my_route"
                                            v-if="item1.status === 1 && item1.is_hidden === false"
                                        >
                                            {{ item1.name }}
                                            <span class="badge rounded-pill bg-danger float-end" v-if="getBadgeCount(item1.my_route) > 0">{{ getBadgeCount(item1.my_route) > 99 ? '99+' : getBadgeCount(item1.my_route) }}</span>
                                        </Link>
                                    </li>
                                    <li v-if="item1.children?.length > 0">
                                        <Link
                                            v-if="item1.status === 1 && item1.is_hidden === false && item1.my_route && item1.type === 1"
                                            :href="item1.my_route"
                                        >
                                            {{ item1.name }}
                                            <span class="badge rounded-pill bg-danger float-end" v-if="getBadgeCount(item1.my_route) > 0">{{ getBadgeCount(item1.my_route) > 99 ? '99+' : getBadgeCount(item1.my_route) }}</span>
                                        </Link>
                                        <a
                                            v-else-if="item1.status === 1 && item1.is_hidden === false"
                                            href="javascript:void(0);"
                                            class="has-arrow"
                                        >
                                            {{ item1.name }}
                                        </a>
                                        <ul class="sub-menu" aria-expanded="true">
                                            <li v-for="item2,index2 in item1.children" :key="item2.id" >
                                                <a
                                                    href="javascript: void(0);"
                                                    :class="item2.children?.length > 0 ? 'has-arrow' : ''"
                                                    v-if="item2.status === 1 && item2.is_hidden === false && item2.children?.length > 0"
                                                >
                                                    {{ item2.name }}
                                                </a>
                                                <Link :href="item2.my_route" v-else-if="item2.status === 1 && item2.is_hidden === false">
                                                    {{ item2.name }}
                                                    <span class="badge rounded-pill bg-danger float-end" v-if="getBadgeCount(item2.my_route) > 0">{{ getBadgeCount(item2.my_route) > 99 ? '99+' : getBadgeCount(item2.my_route) }}</span>
                                                </Link>
                                                <ul class="sub-menu" aria-expanded="true" v-if="item2.status === 1 && item2.is_hidden === false && item2.children?.length > 0">
                                                    <li v-for="item3,index3 in item2.children" :key="item3.id">
                                                        <Link :href="item3.my_route" v-if="item3.status === 1 && item3.is_hidden === false">
                                                            {{ item3.name }}
                                                            <span class="badge rounded-pill bg-danger float-end" v-if="getBadgeCount(item3.my_route) > 0">{{ getBadgeCount(item3.my_route) > 99 ? '99+' : getBadgeCount(item3.my_route) }}</span>
                                                        </Link>
                                                    </li>
                                                </ul>
                                            </li>
                                        </ul>
                                    </li>
                                </span>
                            </ul>
                        </li>
                    </span>
                </ul>
                <!--end navigation-->
            </div>
        </simplebar>
    <!--end sidebar wrapper -->
</template>