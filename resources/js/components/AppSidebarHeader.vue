<script setup lang="ts">
    import { Bell, Cog, HomeCircle, Menu, Power, Search, User } from '@boxicons/vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed, ref } from 'vue';
    import useSidebarToggle from '@/composables/useSidebarToggle';
    import { dashboard, logout } from '@/routes';

    const { openSidebar } = useSidebarToggle();
    const page = usePage();
    const user = computed(() => (page.props as any)?.auth?.user || {});
    const searchQuery = ref('');

    const handleLogout = () => {
        router.flushAll();
    };

    const userInitials = computed(() => {
        const name = String(user.value?.fullname || user.value?.name || '').trim();

        if (!name) {
            return 'SA';
        }

        const parts = name.split(/\s+/).filter(Boolean);

        if (parts.length === 1) {
            return parts[0].slice(0, 2).toUpperCase();
        }

        return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase();
    });
</script>

<template>
    <header>
        <div class="topbar topbar--dark d-flex align-items-center">
            <nav class="navbar navbar-expand topbar-nav">
                <div class="topbar-nav__left">
                    <button
                        type="button"
                        class="mobile-toggle-menu d-lg-none"
                        title="Open navigation menu"
                        @click="openSidebar"
                    >
                        <Menu size="sm" class="topbar-icon" aria-hidden="true" />
                    </button>

                    <div class="topbar-search">
                        <Search size="sm" class="topbar-search__icon" aria-hidden="true" />
                        <input
                            type="text"
                            v-model="searchQuery"
                            class="topbar-search__input"
                            placeholder="Search reports, routes, analytics..."
                        >
                    </div>
                </div>

                <div class="topbar-nav__right">
                    <span class="erp-synced-badge d-none d-sm-inline-flex">
                        <span class="erp-synced-badge__dot"></span>
                        ERP Synced
                    </span>

                    <button type="button" class="topbar-bell" title="Notifications">
                        <Bell size="sm" class="topbar-icon" aria-hidden="true" />
                        <span class="topbar-bell__dot"></span>
                    </button>

                    <div class="user-box dropdown">
                        <a
                            class="d-flex align-items-center nav-link dropdown-toggle dropdown-toggle-nocaret topbar-user"
                            href="#"
                            role="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            :title="user?.fullname || 'Account'"
                        >
                            <span class="topbar-avatar" aria-hidden="true">{{ userInitials }}</span>
                            <div class="user-info">
                                <p class="user-name mb-0">{{ user?.fullname }}</p>
                                <p class="designattion mb-0">{{ user?.email }}</p>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end topbar-user-menu">
                            <li class="topbar-user-menu__header">
                                <p class="topbar-user-menu__name mb-0">{{ user?.fullname }}</p>
                                <p class="topbar-user-menu__email mb-0">{{ user?.email }}</p>
                            </li>
                            <li>
                                <a class="dropdown-item" href="javascript:;">
                                    <User size="sm" class="topbar-dropdown-icon" /><span>Profile</span>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="javascript:;">
                                    <Cog size="sm" class="topbar-dropdown-icon" /><span>Settings</span>
                                </a>
                            </li>
                            <li>
                                <Link class="dropdown-item" :href="dashboard()">
                                    <HomeCircle size="sm" class="topbar-dropdown-icon" /><span>Dashboard</span>
                                </Link>
                            </li>
                            <li>
                                <div class="dropdown-divider mb-0"></div>
                            </li>
                            <li>
                                <Link
                                    class="dropdown-item topbar-user-menu__logout"
                                    :href="logout()"
                                    as="button"
                                    data-test="logout-button"
                                    @click="handleLogout"
                                >
                                    <Power size="sm" class="topbar-dropdown-icon" /><span>Logout</span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>
        </div>
    </header>
</template>

<style scoped>
.topbar.topbar--dark {
    background: #161922;
    border-bottom: 1px solid rgba(30, 41, 59, 0.9);
    box-shadow: 0 4px 6px -1px rgba(15, 23, 42, 0.28);
}

.topbar--dark :deep(.navbar.topbar-nav) {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    width: 100%;
    padding-left: 1.5rem;
    padding-right: 1.5rem;
}

.topbar-nav__left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex: 1 1 auto;
    max-width: 28rem;
}

.topbar-nav__right {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    flex-shrink: 0;
}

.topbar--dark :deep(.mobile-toggle-menu) {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    padding: 0;
    border: 0;
    background: transparent;
    color: #94a3b8;
}

.topbar--dark :deep(.mobile-toggle-menu:hover) {
    color: #ffffff;
    background: rgba(30, 41, 59, 0.8);
}

.topbar-search {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
}

.topbar-icon {
    display: block;
    width: 1.15rem;
    height: 1.15rem;
    fill: currentColor;
    flex-shrink: 0;
}

.topbar-search__icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    width: 0.875rem;
    height: 0.875rem;
    color: #94a3b8;
    fill: currentColor;
    pointer-events: none;
}

.topbar-search__input {
    width: 100%;
    background: #1e232d;
    border: 1px solid rgba(51, 65, 85, 0.8);
    border-radius: 8px;
    padding: 6px 12px 6px 32px;
    font-size: 12px;
    color: #e2e8f0;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.topbar-search__input::placeholder {
    color: #64748b;
}

.topbar-search__input:focus {
    outline: none;
    border-color: #14b8a6;
    box-shadow: 0 0 0 1px rgba(20, 184, 166, 0.3);
    background: #1e232d;
}

.erp-synced-badge {
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    background: rgba(6, 78, 59, 0.7);
    border: 1px solid rgba(6, 95, 70, 0.8);
    color: #34d399;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.erp-synced-badge__dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #34d399;
    animation: erp-pulse 1.6s ease-in-out infinite;
}

@keyframes erp-pulse {
    0%,
    100% {
        opacity: 1;
    }

    50% {
        opacity: 0.45;
    }
}

.topbar-bell {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 8px;
    background: transparent;
    border: none;
    color: #94a3b8;
    transition: all 0.15s ease;
}

.topbar-bell:hover {
    background: rgba(30, 41, 59, 0.8);
    color: #ffffff;
}

.topbar-bell .topbar-icon {
    width: 1rem;
    height: 1rem;
}

.topbar-dropdown-icon {
    width: 1rem;
    height: 1rem;
    margin-right: 0.5rem;
    fill: currentColor;
    vertical-align: middle;
}

.topbar-bell__dot {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #2dd4bf;
    box-shadow: 0 0 0 2px #161922;
}

.topbar-user {
    gap: 0.625rem;
    padding: 0.25rem 0.5rem 0.25rem 0.25rem;
    border-radius: 0.5rem;
    border: 0;
    background: transparent;
    text-decoration: none;
}

.topbar-user:hover,
.topbar-user:focus-visible,
.topbar-user.show,
.topbar-user[aria-expanded="true"] {
    background: rgba(30, 41, 59, 0.8);
    border: 0;
    box-shadow: none;
    outline: none;
}

.topbar-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #0d9488;
    color: #ffffff;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: -0.02em;
    box-shadow: 0 0 0 1px rgba(13, 148, 136, 0.3);
}

.topbar--dark :deep(.user-box) {
    display: flex;
    align-items: center;
    height: auto;
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent;
}

.topbar--dark :deep(.user-box:hover) {
    background: transparent;
}

.topbar--dark :deep(.user-info) {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
    max-width: 10.5rem;
    padding-left: 0;
}

.topbar--dark :deep(.user-info .user-name),
.topbar--dark :deep(.user-info .designattion) {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.topbar--dark :deep(.user-info .user-name) {
    color: #ffffff;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.2;
}

.topbar--dark :deep(.user-info .designattion) {
    color: #94a3b8;
    font-size: 10px;
    font-weight: 500;
    line-height: 1.2;
}

.topbar--dark :deep(.user-box .dropdown-menu),
.topbar--dark :deep(.topbar-user-menu) {
    min-width: 15rem;
    margin-top: 0.5rem;
    padding: 0.25rem 0 0.375rem;
    overflow: hidden;
    background: #1e232d;
    border: 1px solid #334155;
    border-radius: 0.75rem;
    box-shadow: 0 16px 40px -12px rgba(0, 0, 0, 0.45);
}

.topbar--dark :deep(.user-box .dropdown-menu::before),
.topbar--dark :deep(.user-box .dropdown-menu::after),
.topbar.topbar--dark :deep(.navbar .dropdown-menu::after) {
    display: none !important;
    content: none !important;
    width: 0 !important;
    height: 0 !important;
    background: transparent !important;
    border: 0 !important;
}

.topbar-user-menu__header {
    padding: 0.75rem 1rem 0.625rem;
    margin-bottom: 0.25rem;
    border-bottom: 1px solid #334155;
}

.topbar-user-menu__name {
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: 700;
    line-height: 1.2;
}

.topbar-user-menu__email {
    color: #94a3b8;
    font-size: 0.6875rem;
    font-weight: 500;
    line-height: 1.3;
}

.topbar--dark :deep(.user-box .dropdown-menu .dropdown-item) {
    display: flex;
    align-items: center;
    gap: 0;
    padding: 0.5rem 1rem;
    color: #cbd5e1;
    font-size: 0.75rem;
    font-weight: 500;
    background: transparent;
}

.topbar--dark :deep(.user-box .dropdown-menu .dropdown-item:hover),
.topbar--dark :deep(.user-box .dropdown-menu .dropdown-item:focus) {
    background: rgba(51, 65, 85, 0.55);
    color: #e2e8f0;
}

.topbar--dark :deep(.topbar-user-menu__logout),
.topbar--dark :deep(.topbar-user-menu__logout:hover),
.topbar--dark :deep(.topbar-user-menu__logout:focus) {
    color: #fb7185;
}

.topbar--dark :deep(.topbar-user-menu__logout:hover),
.topbar--dark :deep(.topbar-user-menu__logout:focus) {
    background: rgba(127, 29, 29, 0.35);
}

.topbar--dark :deep(.user-box .dropdown-divider) {
    border-color: #334155;
    margin: 0.25rem 0;
}

@media (max-width: 991.98px) {
    .topbar-user .user-info {
        display: none;
    }
}
</style>
