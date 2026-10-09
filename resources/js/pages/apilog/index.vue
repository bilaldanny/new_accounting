<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'API Logs',
            subtitle: 'Requests made to the public API (api/v1/*)',
            breadcrumbs: [
                { title: 'API Logs', href: 'NULL' },
            ],
        },
    });

    type ApiLogRow = {
        id: number;
        method: string;
        path: string;
        status_code: number;
        ip: string | null;
        duration_ms: number;
        created_at: string | null;
        user: { first_name: string; last_name: string } | null;
    };

    const { Notify } = useCommons();
    const loading = ref(true);
    const logs = ref<ApiLogRow[]>([]);

    function statusClass(code: number): string {
        if (code >= 500) {
return 'badge bg-danger-subtle text-danger';
}

        if (code >= 400) {
return 'badge bg-warning-subtle text-warning';
}

        return 'badge bg-success-subtle text-success';
    }

    async function load() {
        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.apiLogs);
            logs.value = response.data?.data?.data ?? response.data?.data ?? [];
        } catch {
            Notify('Unable to load API logs.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="API Logs" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading API logs…" />

                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Path</th>
                                <th>Status</th>
                                <th>User</th>
                                <th>IP</th>
                                <th>Duration</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in logs" :key="log.id">
                                <td><span class="badge bg-secondary-subtle text-secondary">{{ log.method }}</span></td>
                                <td class="font-monospace small">{{ log.path }}</td>
                                <td><span :class="statusClass(log.status_code)">{{ log.status_code }}</span></td>
                                <td>{{ log.user ? `${log.user.first_name} ${log.user.last_name}` : '—' }}</td>
                                <td>{{ log.ip || '—' }}</td>
                                <td>{{ log.duration_ms }} ms</td>
                                <td>{{ log.created_at || '—' }}</td>
                            </tr>
                            <tr v-if="! logs.length">
                                <td colspan="7" class="text-center text-muted">No API requests logged yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
