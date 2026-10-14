<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'My Notifications',
            subtitle: 'Reminders, approvals and reports sent to you',
            breadcrumbs: [{ title: 'My Notifications', href: 'NULL' }],
        },
    });

    type Row = { id: number; kind: string; title: string; body: string | null; url: string | null; read_at: string | null; created_at: string };

    const { run } = useCloseout();
    const rows = ref<Row[]>([]);
    const unread = ref(0);

    async function load() {
        const response = await run(() => window.axios.get('/api/my-notifications'));
        rows.value = response?.data?.data ?? [];
        unread.value = response?.data?.unread ?? 0;
    }

    async function markRead(row: Row) {
        await run(() => window.axios.post(`/api/my-notifications/${row.id}/read`));
        await load();
    }

    async function markAll() {
        await run(() => window.axios.post('/api/my-notifications/read-all'));
        await load();
    }

    onMounted(load);
</script>

<template>
    <Head title="My Notifications" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="small">{{ unread }} unread</span>
            <button type="button" class="btn btn-outline-dark btn-sm" :disabled="! unread" @click="markAll">Mark all as read</button>
        </div>
        <div class="list-group">
            <div v-for="row in rows" :key="row.id" class="list-group-item" :class="{ 'fw-semibold': ! row.read_at }" dusk="notification">
                <div class="d-flex justify-content-between">
                    <span>{{ row.title }}</span>
                    <span class="small text-muted">{{ row.created_at.slice(0, 16).replace('T', ' ') }}</span>
                </div>
                <div v-if="row.body" class="small text-muted">{{ row.body }}</div>
                <div class="mt-1">
                    <a v-if="row.url" :href="row.url" class="small me-3">Open</a>
                    <button v-if="! row.read_at" type="button" class="btn btn-link btn-sm p-0" @click="markRead(row)">Mark as read</button>
                </div>
            </div>
            <div v-if="! rows.length" class="list-group-item text-muted text-center">Nothing yet.</div>
        </div>
    </div></div></div>
</template>
