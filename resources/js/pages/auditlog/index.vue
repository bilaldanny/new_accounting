<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import { onMounted, reactive, ref } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Activity Log',
            subtitle: 'Who changed what, and when, with the before and after of every field',
            breadcrumbs: [
                {
                    title: 'Activity Log',
                    href: 'NULL',
                },
            ],
        },
    });

    type Change = { field: string; old: unknown; new: unknown };
    type LogRow = {
        id: number;
        event: string;
        model: string;
        auditable_id: number;
        user_name: string | null;
        ip_address: string | null;
        created_at: string;
        changes: Change[];
    };

    const { Notify } = useCommons();

    const rows = ref<LogRow[]>([]);
    const loading = ref(false);
    const page = ref(1);
    const lastPage = ref(1);
    const filters = reactive({ model: '', event: '', auditable_id: '', from: '', to: '' });

    const events = ['created', 'updated', 'deleted', 'restored', 'force_deleted'];

    function show(value: unknown): string {
        if (value === null || value === undefined) {
            return '-';
        }

        return typeof value === 'object' ? JSON.stringify(value) : String(value);
    }

    async function load() {
        loading.value = true;

        try {
            const response = await window.axios.get('/api/audit-logs', { params: { ...filters, page: page.value } });
            rows.value = response.data?.data?.data ?? [];
            lastPage.value = response.data?.data?.last_page ?? 1;
        } catch (error: unknown) {
            rows.value = [];

            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || 'Failed to load the activity log.', 'alert');
            }
        } finally {
            loading.value = false;
        }
    }

    function applyFilters() {
        page.value = 1;
        void load();
    }

    function goTo(next: number) {
        page.value = Math.min(Math.max(next, 1), lastPage.value);
        void load();
    }

    onMounted(load);
</script>

<template>
    <Head title="Activity Log" />
    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">

    <div class="card">
        <div class="card-body">
            <form class="row g-2 mb-3" @submit.prevent="applyFilters">
                <div class="col-md-2">
                    <input v-model="filters.model" class="form-control" placeholder="Model (e.g. Contact)">
                </div>
                <div class="col-md-2">
                    <select v-model="filters.event" class="form-select">
                        <option value="">All events</option>
                        <option v-for="event in events" :key="event" :value="event">{{ event }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input v-model="filters.auditable_id" class="form-control" placeholder="Record ID" inputmode="numeric">
                </div>
                <div class="col-md-2">
                    <input v-model="filters.from" type="date" class="form-control">
                </div>
                <div class="col-md-2">
                    <input v-model="filters.to" type="date" class="form-control">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                </div>
            </form>

            <div v-if="loading" class="text-center py-4">Loading…</div>
            <div v-else-if="rows.length === 0" class="text-center py-4 text-muted">No activity recorded.</div>

            <div v-else class="table-responsive">
                <ClientTable
                    :columns="[{ key: 'c0', label: 'When' }, { key: 'c1', label: 'User' }, { key: 'c2', label: 'Event' }, { key: 'c3', label: 'Record' }, { key: 'c4', label: 'IP' }, { key: 'c5', label: 'Changes', sorting: 'disabled' }]"
                    :rows="rows.map((row) => ({ __s: { row }, c0: row.created_at, c1: row.user_name || 'System', c2: row.event, c3: `${row.model} #${row.auditable_id}`, c4: row.ip_address || '-', c5: '' }))"
                    :page-size="Math.max(rows.length, 10)"
                    export-name="activity-log"
                >
                    <template #cell-c5="{ row: __m }">
                        <template v-for="{ row } in [__m.__s]" :key="row.id">
                            <details v-if="row.changes.length" class="audit-changes">
                                <summary class="btn btn-outline-secondary btn-sm">Changes ({{ row.changes.length }})</summary>
                                <ul class="list-unstyled small mb-0 mt-1">
                                    <li v-for="change in row.changes" :key="change.field"><strong>{{ change.field }}</strong>: <span class="text-danger">{{ show(change.old) }}</span> &rarr; <span class="text-success">{{ show(change.new) }}</span></li>
                                </ul>
                            </details>
                        </template>
                    </template>
                </ClientTable>

                <div v-if="lastPage > 1" class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="page <= 1" @click="goTo(page - 1)">Prev</button>
                    <span>Page {{ page }} / {{ lastPage }}</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="page >= lastPage" @click="goTo(page + 1)">Next</button>
                </div>
            </div>
        </div>
    </div>
    </div></div></div>
</template>
