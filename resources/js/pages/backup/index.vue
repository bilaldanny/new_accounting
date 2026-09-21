<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { Download, Trash2 } from '@lucide/vue';
    import { onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Database Backup',
            subtitle: 'Make and download a backup of the whole database',
            breadcrumbs: [
                {
                    title: 'Database Backup',
                    href: 'NULL',
                },
            ],
        },
    });

    type Backup = {
        id: number;
        file_name: string;
        status: 'running' | 'completed' | 'failed';
        size_bytes: number | null;
        error: string | null;
        created_at: string | null;
        file_exists: boolean;
    };

    const { Notify } = useCommons();

    const loading = ref(true);
    const creating = ref(false);
    const backups = ref<Backup[]>([]);

    function formatSize(bytes: number | null): string {
        if (! bytes) {
            return '—';
        }

        return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    function errorMessage(error: unknown): string {
        return window.axios.isAxiosError(error)
            ? (error.response?.data?.errormessage || error.response?.data?.message || 'The request failed.')
            : 'Unexpected error occurred';
    }

    async function load() {
        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.backups, { params: { show_record: 50 } });
            backups.value = response.data.data.data;
        } catch {
            Notify('Unable to load the backups.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function createBackup() {
        creating.value = true;

        try {
            const response = await window.axios.post(API_ENDPOINTS.backups);
            Notify(response.data?.message || 'Backup created', 'success');
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            creating.value = false;
            await load();
        }
    }

    async function removeBackup(backup: Backup) {
        if (! window.confirm(`Delete ${backup.file_name}?`)) {
            return;
        }

        try {
            await window.axios.delete(`${API_ENDPOINTS.backups}/${backup.id}`);
            Notify('Successfully Deleted', 'success');
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        }

        await load();
    }

    onMounted(load);
</script>

<template>
    <Head title="Database Backup" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <p class="text-muted small mb-0">
                    A backup contains the data of every company. To restore one, unzip it and load it with the mysql client on the server.
                </p>
                <button type="button" class="btn btn-primary btn-sm" :disabled="creating" @click="createBackup">
                    {{ creating ? 'Backing up…' : 'Create backup' }}
                </button>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading backups…" />

                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Created</th>
                                <th class="text-end">Size</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="backup in backups" :key="backup.id">
                                <td>{{ backup.file_name }}</td>
                                <td>{{ backup.created_at || '—' }}</td>
                                <td class="text-end">{{ formatSize(backup.size_bytes) }}</td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="{
                                            'bg-success-subtle text-success': backup.status === 'completed',
                                            'bg-danger-subtle text-danger': backup.status === 'failed',
                                            'bg-warning-subtle text-warning': backup.status === 'running',
                                        }"
                                        :title="backup.error ?? ''"
                                    >
                                        {{ backup.status }}
                                    </span>
                                    <span v-if="backup.status === 'completed' && ! backup.file_exists" class="text-danger small ms-1">file missing</span>
                                </td>
                                <td class="text-end">
                                    <a
                                        v-if="backup.file_exists"
                                        :href="`${API_ENDPOINTS.backups}/${backup.id}/download`"
                                        class="btn btn-outline-primary btn-sm me-1"
                                        title="Download"
                                    >
                                        <Download class="h-4 w-4" />
                                    </a>
                                    <button type="button" class="btn btn-outline-danger btn-sm" title="Delete" @click="removeBackup(backup)">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="! backups.length">
                                <td colspan="5" class="text-center text-muted">No backups yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
