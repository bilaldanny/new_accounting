<script setup lang="ts">
    import { Bell, Cog, HomeCircle, Menu, Power, Search, User } from '@boxicons/vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
    import HeaderTools from '@/components/HeaderTools.vue';
    import OfflineStatus from '@/components/OfflineStatus.vue';
    import useSidebarToggle from '@/composables/useSidebarToggle';
    import { clearShell, offline, stopEngine } from '@/lib/offline/engine';
    import { dashboard, logout } from '@/routes';

    const { openSidebar } = useSidebarToggle();
    const page = usePage();
    const user = computed(() => (page.props as any)?.auth?.user || {});
    const searchQuery = ref('');
    type SearchHit = { group: string; label: string; hint: string; url: string };
    const searchResults = ref<SearchHit[]>([]);
    const searchOpen = ref(false);
    const searchBusy = ref(false);
    let searchTimer: ReturnType<typeof setTimeout> | null = null;

    type StoredNotification = { id: number; kind: string; title: string; body: string | null; url: string | null; read_at: string | null; created_at: string };
    const storedNotifications = ref<StoredNotification[]>([]);
    const unreadStored = ref(0);

    const notificationCounts = ref<Record<string, number>>({});
    const notificationTotal = ref(0);

    /** Where each kind of waiting item is handled (the dashboard's approval list, plus low stock). */
    const notificationLinks: Record<string, { label: string; href: string }> = {
        purchase: { label: 'Purchase orders to approve', href: '/purchase/approval' },
        sell: { label: 'Sell orders to approve', href: '/sell/approval' },
        journal: { label: 'Journal entries to approve', href: '/journalentry/approval' },
        payment: { label: 'Payment vouchers to approve', href: '/acpayment/approval' },
        expense: { label: 'Expense vouchers to approve', href: '/expense/approval' },
        deposit: { label: 'Deposit vouchers to approve', href: '/deposit/approval' },
        fundtransfer: { label: 'Fund transfers to approve', href: '/fundtransfer/approval' },
        purchasereturn: { label: 'Purchase returns to approve', href: '/purchasereturn/approval' },
        stockadjustment: { label: 'Stock adjustments to approve', href: '/stockadjustment/approval' },
        stocktransfer: { label: 'Stock transfers to approve', href: '/stocktransfer/approval' },
        cashcollection: { label: 'Cash collections to review', href: '/cashcollection/approval' },
        pricelist: { label: 'Price lists to approve', href: '/pricelist/approval' },
        creditlimit: { label: 'Credit limit requests', href: '/creditlimit/approval' },
        low_stock: { label: 'Products at or under their alert quantity', href: '/lowstock' },
    };

    const notificationRows = computed(() => Object.entries(notificationCounts.value)
        .filter(([key]) => notificationLinks[key] !== undefined)
        .map(([key, count]) => ({ key, count, ...notificationLinks[key] })));

    async function loadNotifications() {
        try {
            const response = await window.axios.get('/api/notifications/counts-by-type');
            notificationCounts.value = response.data?.counts_by_type ?? {};
            notificationTotal.value = Number(response.data?.total ?? 0);
        } catch {
            notificationCounts.value = {};
            notificationTotal.value = 0;
        }
    }

    async function loadStored() {
        try {
            const response = await window.axios.get('/api/my-notifications', { params: { unread: 1 } });
            storedNotifications.value = (response.data?.data ?? []).slice(0, 6);
            unreadStored.value = Number(response.data?.unread ?? 0);
        } catch {
            storedNotifications.value = [];
            unreadStored.value = 0;
        }
    }

    async function openNotification(row: StoredNotification) {
        try {
            await window.axios.post(`/api/my-notifications/${row.id}/read`);
        } catch {
            // the link still works
        }

        storedNotifications.value = storedNotifications.value.filter((n) => n.id !== row.id);
        unreadStored.value = Math.max(0, unreadStored.value - 1);

        if (row.url) {
            router.visit(row.url);
        }
    }

    async function readAllStored() {
        try {
            await window.axios.post('/api/my-notifications/read-all');
        } catch {
            // nothing to do
        }

        storedNotifications.value = [];
        unreadStored.value = 0;
    }

    function refreshNotifications() {
        void loadNotifications();
        void loadStored();
    }

    async function runSearch() {
        const q = searchQuery.value.trim();

        if (q.length < 2) {
            searchResults.value = [];
            searchBusy.value = false;

            return;
        }

        searchBusy.value = true;

        try {
            const response = await window.axios.get('/api/search', { params: { q } });
            searchResults.value = response.data?.data ?? [];
        } catch {
            searchResults.value = [];
        } finally {
            searchBusy.value = false;
        }
    }

    watch(searchQuery, () => {
        searchOpen.value = true;

        if (searchTimer !== null) {
            clearTimeout(searchTimer);
        }

        searchTimer = setTimeout(runSearch, 250);
    });

    function goToHit(hit: SearchHit) {
        searchOpen.value = false;
        searchQuery.value = '';
        searchResults.value = [];
        router.visit(hit.url);
    }

    const activeHit = ref(-1);

    function moveHit(by: 1 | -1) {
        const count = searchResults.value.length;

        if (count === 0) {
            return;
        }

        activeHit.value = (activeHit.value + by + count) % count;
    }

    function searchEnter() {
        if (searchResults.value.length > 0) {
            goToHit(searchResults.value[activeHit.value >= 0 ? activeHit.value : 0]);
        }
    }

    watch(searchResults, () => {
        activeHit.value = -1;
    });

    const closeSearch = (event: MouseEvent) => {
        if (! (event.target as HTMLElement | null)?.closest('.topbar-search')) {
            searchOpen.value = false;
        }
    };

    onMounted(() => {
        refreshNotifications();
        document.addEventListener('click', closeSearch);
    });

    onBeforeUnmount(() => document.removeEventListener('click', closeSearch));

    /** Signing out with entries still waiting on this device asks first: they stay here, under this login, until it syncs them. */
    const handleLogout = (event?: Event) => {
        const unsent = offline.pending + offline.posPending;

        if (unsent > 0 && ! window.confirm(`${unsent} offline ${unsent === 1 ? 'entry has' : 'entries have'} not been sent to the server yet. They stay on this device and are sent the next time you sign in on it. Sign out anyway?`)) {
            event?.preventDefault();
            event?.stopImmediatePropagation();

            return;
        }

        stopEngine();
        void clearShell();
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

                    <div class="topbar-search" style="position: relative">
                        <Search size="sm" class="topbar-search__icon" aria-hidden="true" />
                        <input
                            type="text"
                            v-model="searchQuery"
                            class="topbar-search__input"
                            placeholder="Search pages, customers, products, invoices..."
                            aria-label="Search"
                            autocomplete="off"
                            @keydown.enter.prevent="searchEnter"
                            @keydown.down.prevent="moveHit(1)"
                            @keydown.up.prevent="moveHit(-1)"
                            @keydown.esc="searchOpen = false"
                            @focus="searchOpen = true"
                        >
                        <ul v-if="searchOpen && searchQuery.trim().length >= 2" class="dropdown-menu show topbar-search__results" style="position: absolute; top: 100%; left: 0; min-width: 22rem; max-height: 22rem; overflow: auto" data-test="search-results">
                            <li v-if="searchBusy" class="dropdown-item-text small text-muted">Searching...</li>
                            <li v-else-if="! searchResults.length" class="dropdown-item-text small text-muted">Nothing found</li>
                            <li v-for="(hit, hitIndex) in searchResults" :key="hit.group + hit.url + hit.label">
                                <a class="dropdown-item d-flex justify-content-between gap-3" :class="{ active: hitIndex === activeHit }" :href="hit.url" @click.prevent="goToHit(hit)">
                                    <span>{{ hit.label }} <small class="text-muted">{{ hit.hint }}</small></span>
                                    <small class="text-muted">{{ hit.group }}</small>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="topbar-nav__right">
                    <span class="erp-synced-badge d-none d-sm-inline-flex">
                        <span class="erp-synced-badge__dot"></span>
                        ERP Synced
                    </span>

                    <OfflineStatus />

                    <HeaderTools />

                    <div class="dropdown">
                        <button
                            type="button"
                            class="topbar-bell"
                            title="Notifications"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            @click="refreshNotifications"
                        >
                            <Bell size="sm" class="topbar-icon" aria-hidden="true" />
                            <span v-if="notificationTotal + unreadStored > 0" class="topbar-bell__dot"></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end topbar-user-menu" style="min-width: 18rem">
                            <li class="topbar-user-menu__header">
                                <p class="topbar-user-menu__name mb-0">Notifications</p>
                                <p class="topbar-user-menu__email mb-0">{{ notificationTotal + unreadStored === 0 ? 'Nothing is waiting for you' : `${notificationTotal} waiting, ${unreadStored} unread` }}</p>
                            </li>
                            <li v-for="row in storedNotifications" :key="`n-${row.id}`">
                                <a class="dropdown-item" href="#" @click.prevent="openNotification(row)">
                                    <span class="d-block">{{ row.title }}</span>
                                    <small v-if="row.body" class="text-muted d-block text-truncate" style="max-width: 16rem">{{ row.body }}</small>
                                </a>
                            </li>
                            <li v-if="unreadStored > 0" class="d-flex justify-content-between px-3 py-1 small">
                                <a href="#" @click.prevent="readAllStored">Mark all read</a>
                                <Link href="/my-notifications">See all</Link>
                            </li>
                            <li v-for="row in notificationRows" :key="row.key">
                                <Link class="dropdown-item d-flex justify-content-between gap-3" :href="row.href">
                                    <span>{{ row.label }}</span>
                                    <strong>{{ row.count }}</strong>
                                </Link>
                            </li>
                        </ul>
                    </div>

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
                                    @click.capture="handleLogout"
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
