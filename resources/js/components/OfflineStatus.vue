<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { computed, onMounted } from 'vue';
    import { heartbeat, offline, registerShell, startEngine, syncAll } from '@/lib/offline/engine';

    /**
     * The header chip of offline mode: Online / Offline / Syncing n / Conflicts n, and the "Sync now" button. It starts the offline
     * engine for the signed-in user (once) and links to the /offline screen where the waiting entries are listed.
     */
    const page = usePage();
    const user = computed(() => (page.props as any)?.auth?.user ?? null);
    const allowed = computed(() => {
        const paths: string[] = user.value?.permission_paths ?? [];

        return user.value !== null && Number(user.value.company_id ?? 0) > 0 && (paths.includes('/offline') || String(user.value.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    });

    const waiting = computed(() => offline.pending + offline.posPending);
    const conflicts = computed(() => offline.conflicts + offline.posConflicts);

    const state = computed(() => {
        if (! offline.online) {
            return { key: 'offline', label: waiting.value > 0 ? `Offline - ${waiting.value} waiting` : 'Offline' };
        }

        if (offline.needsLogin) {
            return { key: 'login', label: 'Sign in to sync' };
        }

        if (offline.syncing) {
            return { key: 'syncing', label: `Syncing ${waiting.value}` };
        }

        if (conflicts.value > 0) {
            return { key: 'conflict', label: `Conflicts ${conflicts.value}` };
        }

        if (waiting.value > 0) {
            return { key: 'pending', label: `${waiting.value} waiting` };
        }

        return { key: 'online', label: 'Online' };
    });

    async function syncNow() {
        await heartbeat();
        await syncAll();
    }

    onMounted(() => {
        if (! allowed.value || offline.started) {
            return;
        }

        startEngine({ userId: Number(user.value.id), companyId: Number(user.value.company_id), branchId: user.value.branch_id ? Number(user.value.branch_id) : null });
        void registerShell();
    });
</script>

<template>
    <span v-if="allowed" class="offline-chip-wrap d-inline-flex align-items-center gap-1">
        <a href="/offline" class="offline-chip" :class="`offline-chip--${state.key}`" data-test="offline-chip" :title="offline.error || 'Offline mode: entries made without internet and what happened to them'" :aria-label="`Offline status: ${state.label}`">
            <span class="offline-chip__dot"></span>{{ state.label }}
        </a>
        <button v-if="waiting > 0 || conflicts > 0" type="button" class="offline-chip-sync" data-test="offline-sync-now" :disabled="offline.syncing || ! offline.online" @click="syncNow">Sync now</button>
    </span>
</template>

<style scoped>
.offline-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    text-decoration: none;
    border: 1px solid #334155;
    color: #94a3b8;
}

.offline-chip__dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.offline-chip--online { color: #34d399; border-color: rgba(6, 95, 70, 0.8); }
.offline-chip--offline { color: #fbbf24; border-color: rgba(180, 83, 9, 0.8); }
.offline-chip--syncing { color: #60a5fa; border-color: rgba(30, 64, 175, 0.8); }
.offline-chip--conflict,
.offline-chip--login { color: #fb7185; border-color: rgba(159, 18, 57, 0.8); }
.offline-chip--pending { color: #fbbf24; border-color: rgba(180, 83, 9, 0.8); }

.offline-chip-sync {
    border: 1px solid #334155;
    background: transparent;
    color: #e2e8f0;
    font-size: 11px;
    border-radius: 6px;
    padding: 3px 8px;
}

.offline-chip-sync:disabled { opacity: 0.5; }
</style>
