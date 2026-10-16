<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
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
                    <ClientTable  :columns="[{ key: 'c0', label: 'Method' }, { key: 'c1', label: 'Path' }, { key: 'c2', label: 'Status' }, { key: 'c3', label: 'User' }, { key: 'c4', label: 'IP' }, { key: 'c5', label: 'Duration' }, { key: 'c6', label: 'When' }]" :rows="(logs).map((log) => ({ __s: { log }, c0: '', c1: (log.path), c2: '', c3: (log.user ? `${log.user.first_name} ${log.user.last_name}` : '—'), c4: (log.ip || '—'), c5: `${log.duration_ms} ms`, c6: (log.created_at || '—') }))" export-name="method">
<template #cell-c0="{ row: __m }"><template v-for="{ log } in [__m.__s]" :key="0"><span class="badge bg-secondary-subtle text-secondary">{{ log.method }}</span></template></template>
<template #cell-c2="{ row: __m }"><template v-for="{ log } in [__m.__s]" :key="0"><span :class="statusClass(log.status_code)">{{ log.status_code }}</span></template></template>
</ClientTable>
                </div>
            </div>
        </div>
    </div>
</template>
