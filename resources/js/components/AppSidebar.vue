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

    type SidebarMenuItem = {
        id?: number;
        name?: string;
        parent_id?: string | number | null;
        type?: number | string;
        status?: number | boolean;
        is_hidden?: boolean | number;
        route_name?: string;
        my_route?: string | null;
        icon?: string;
        children?: SidebarMenuItem[];
    };

    const isRootMenu = (item: SidebarMenuItem): boolean =>
        item.parent_id === '' || item.parent_id === null || item.parent_id === undefined;

    const isMenuActive = (item: SidebarMenuItem): boolean => Number(item.status) === 1;

    const isMenuVisible = (item: SidebarMenuItem): boolean => !item.is_hidden;

    const isRenderableMenuItem = (item: SidebarMenuItem): boolean =>
        isMenuActive(item) && isMenuVisible(item);

    const shouldRenderMenuBranch = (item: SidebarMenuItem): boolean => {
        if (! isRenderableMenuItem(item)) {
            return false;
        }

        const children = item.children ?? [];

        if (children.length === 0) {
            return true;
        }

        return children.some(shouldRenderMenuBranch) || Boolean(item.my_route);
    };

    const shouldShowAsDropdown = (item: SidebarMenuItem): boolean => {
        if (! isRenderableMenuItem(item)) {
            return false;
        }

        return (item.children ?? []).some((child) => shouldRenderMenuBranch(child));
    };

    const renderableMenuChildren = (item: SidebarMenuItem): SidebarMenuItem[] =>
        (item.children ?? []).filter(shouldRenderMenuBranch);

    const currentRouteName = computed(() => String(page.props.routeName ?? ''));

    const currentPath = computed(() => {
        const url = page.url ?? '';
        const path = url.split('?')[0].split('#')[0];

        return path.replace(/\/+$/, '') || '/';
    });

    const normalizeMenuPath = (path: string): string => {
        const normalized = path.split('?')[0].split('#')[0].replace(/\/+$/, '');

        return normalized || '/';
    };

    const isMenuPathActive = (item: SidebarMenuItem): boolean => {
        if (! item.my_route) {
            return false;
        }

        const menuPath = normalizeMenuPath(String(item.my_route));
        const activePath = currentPath.value;

        if (activePath === menuPath) {
            return true;
        }

        return menuPath !== '/' && activePath.startsWith(`${menuPath}/`);
    };

    const isMenuRouteActive = (item: SidebarMenuItem): boolean => {
        if (item.route_name) {
            const current = currentRouteName.value;

            if (current === item.route_name) {
                return true;
            }

            if (current.startsWith(`${item.route_name}.`)) {
                return true;
            }
        }

        return isMenuPathActive(item);
    };

    const isMenuBranchActive = (item: SidebarMenuItem): boolean => {
        if (isMenuRouteActive(item)) {
            return true;
        }

        return renderableMenuChildren(item).some((child) => isMenuBranchActive(child));
    };

    const menuType = (item: SidebarMenuItem): number => Number(item.type);

    const permission = computed(() =>
        ((page.props.auth.user?.permissions as SidebarMenuItem[]) ?? []).filter(
            (item) => item.route_name !== 'dashboard',
        ),
    );
    const sideMenuRef = ref<HTMLElement | null>(null);
    let metisInstance: MetisMenu | null = null;

    const disposeMetisMenu = () => {
        if (!metisInstance) {
            return;
        }

        try {
            metisInstance.dispose();
        } catch {
            // Inertia may replace menu nodes before dispose runs.
        }

        metisInstance = null;
    };

    const initMetisMenu = async () => {
        await nextTick();

        if (! sideMenuRef.value) {
            return;
        }

        disposeMetisMenu();
        metisInstance = new MetisMenu(sideMenuRef.value);
    };

    const getBadgeCount = (routePath: string | null): number => {
        const type = getNotificationTypeFromRoute(routePath);
        return type ? (countsByType.value[type] ?? 0) : 0;
    };

    let removeFinishListener: (() => void) | undefined;

    onMounted(() => {
        initMetisMenu();
        fetchCountsByType();

        removeFinishListener = router.on('finish', initMetisMenu);
    });

    onBeforeUnmount(() => {
        removeFinishListener?.();
        disposeMetisMenu();
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
                    <li :class="isMenuRouteActive({ route_name: 'dashboard' }) ? 'mm-active' : ''">
                        <Link
                            :href="dashboard()"
                            :class="isMenuRouteActive({ route_name: 'dashboard' }) ? 'active' : ''"
                        >
                            <div class="parent-icon"><MenuIcon icon="Home" /></div>
                            <div class="menu-title">Dashboard</div>
                        </Link>
                    </li>
                    <span v-for="item in permission" :key="item.id">
                        <li v-if="menuType(item) === 3 && isMenuActive(item)" class="menu-label">{{ item?.name }}</li>
                        <li v-if="isRootMenu(item) && isMenuVisible(item) && menuType(item) === 1 && isMenuActive(item)" :class="isMenuBranchActive(item) ? 'mm-active' : ''">
                            <Link :href="item.my_route" :class="isMenuRouteActive(item) ? 'active' : ''" v-if="item.my_route !== null">
                                <div class="parent-icon"><MenuIcon :icon="item.icon" /></div>
                                <div class="menu-title">{{ item.name }}</div>
                                <span class="badge rounded-pill bg-danger float-end" v-if="getBadgeCount(item.my_route) > 0">{{ getBadgeCount(item.my_route) > 99 ? '99+' : getBadgeCount(item.my_route) }}</span>
                            </Link>
                        </li>
                        <li
                            v-if="isRootMenu(item) && menuType(item) === 2 && isMenuActive(item) && isMenuVisible(item) && shouldRenderMenuBranch(item)"
                            :class="isMenuBranchActive(item) ? 'mm-active' : ''"
                        >
                            <Link
                                v-if="! shouldShowAsDropdown(item) && item.my_route"
                                :href="item.my_route"
                                :class="isMenuRouteActive(item) ? 'active' : ''"
                            >
                                <div class="parent-icon"><MenuIcon :icon="item.icon" /></div>
                                <div class="menu-title">{{ item.name }}</div>
                                <span class="badge rounded-pill bg-danger float-end" v-if="getBadgeCount(item.my_route) > 0">{{ getBadgeCount(item.my_route) > 99 ? '99+' : getBadgeCount(item.my_route) }}</span>
                            </Link>
                            <a
                                v-else
                                href="javascript:void(0);"
                                :class="[
                                    shouldShowAsDropdown(item) ? 'has-arrow' : '',
                                    isMenuBranchActive(item) ? 'waves-effect active' : 'waves-effect',
                                ]"
                            >
                                <div class="parent-icon"><MenuIcon :icon="item.icon" /></div>
                                <div class="menu-title">{{ item.name }}</div>
                            </a>
                            <ul
                                class="sub-menu"
                                :class="{ 'mm-show': isMenuBranchActive(item) }"
                                aria-expanded="true"
                                v-if="shouldShowAsDropdown(item)"
                            >
                                <span v-for="item1 in renderableMenuChildren(item)" :key="item1.id">
                                    <li v-if="! shouldShowAsDropdown(item1)" :class="isMenuRouteActive(item1) ? 'mm-active' : ''">
                                        <Link :href="item1.my_route" :class="isMenuRouteActive(item1) ? 'active' : ''">
                                            {{ item1.name }}
                                            <span class="badge rounded-pill bg-danger float-end" v-if="getBadgeCount(item1.my_route) > 0">{{ getBadgeCount(item1.my_route) > 99 ? '99+' : getBadgeCount(item1.my_route) }}</span>
                                        </Link>
                                    </li>
                                    <li v-else :class="isMenuBranchActive(item1) ? 'mm-active' : ''">
                                        <a
                                            href="javascript:void(0);"
                                            :class="['has-arrow', isMenuBranchActive(item1) ? 'active' : '']"
                                        >
                                            {{ item1.name }}
                                        </a>
                                        <ul
                                            class="sub-menu"
                                            :class="{ 'mm-show': isMenuBranchActive(item1) }"
                                            aria-expanded="true"
                                        >
                                            <li
                                                v-for="item2 in renderableMenuChildren(item1)"
                                                :key="item2.id"
                                                :class="isMenuBranchActive(item2) ? 'mm-active' : ''"
                                            >
                                                <Link
                                                    v-if="! shouldShowAsDropdown(item2)"
                                                    :href="item2.my_route"
                                                    :class="isMenuRouteActive(item2) ? 'active' : ''"
                                                >
                                                    {{ item2.name }}
                                                    <span class="badge rounded-pill bg-danger float-end" v-if="getBadgeCount(item2.my_route) > 0">{{ getBadgeCount(item2.my_route) > 99 ? '99+' : getBadgeCount(item2.my_route) }}</span>
                                                </Link>
                                                <template v-else>
                                                    <a
                                                        href="javascript:void(0);"
                                                        :class="['has-arrow', isMenuBranchActive(item2) ? 'active' : '']"
                                                    >
                                                        {{ item2.name }}
                                                    </a>
                                                    <ul
                                                        class="sub-menu"
                                                        :class="{ 'mm-show': isMenuBranchActive(item2) }"
                                                        aria-expanded="true"
                                                    >
                                                        <li
                                                            v-for="item3 in renderableMenuChildren(item2)"
                                                            :key="item3.id"
                                                            :class="isMenuRouteActive(item3) ? 'mm-active' : ''"
                                                        >
                                                            <Link :href="item3.my_route" :class="isMenuRouteActive(item3) ? 'active' : ''">
                                                                {{ item3.name }}
                                                                <span class="badge rounded-pill bg-danger float-end" v-if="getBadgeCount(item3.my_route) > 0">{{ getBadgeCount(item3.my_route) > 99 ? '99+' : getBadgeCount(item3.my_route) }}</span>
                                                            </Link>
                                                        </li>
                                                    </ul>
                                                </template>
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