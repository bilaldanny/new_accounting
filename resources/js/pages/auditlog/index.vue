<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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
    const expanded = ref<number | null>(null);
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
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>User</th>
                            <th>Event</th>
                            <th>Record</th>
                            <th>IP</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in rows" :key="row.id">
                            <tr>
                                <td>{{ row.created_at }}</td>
                                <td>{{ row.user_name || 'System' }}</td>
                                <td>{{ row.event }}</td>
                                <td>{{ row.model }} #{{ row.auditable_id }}</td>
                                <td>{{ row.ip_address || '-' }}</td>
                                <td>
                                    <button
                                        v-if="row.changes.length"
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm"
                                        @click="expanded = expanded === row.id ? null : row.id"
                                    >
                                        {{ expanded === row.id ? 'Hide' : 'Changes' }} ({{ row.changes.length }})
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="expanded === row.id">
                                <td colspan="6">
                                    <table class="table table-sm mb-0">
                                        <thead>
                                            <tr><th>Field</th><th>Before</th><th>After</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="change in row.changes" :key="change.field">
                                                <td>{{ change.field }}</td>
                                                <td class="text-danger">{{ show(change.old) }}</td>
                                                <td class="text-success">{{ show(change.new) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div v-if="lastPage > 1" class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="page <= 1" @click="goTo(page - 1)">Prev</button>
                    <span>Page {{ page }} / {{ lastPage }}</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="page >= lastPage" @click="goTo(page + 1)">Next</button>
                </div>
            </div>
        </div>
    </div>
</template>
