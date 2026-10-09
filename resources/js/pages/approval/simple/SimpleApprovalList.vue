<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import useCommons from '@/composables/common';

    /**
     * Pending list with approve (and optional reject) actions for the Approval Center pages whose
     * records are not t_accounts vouchers (purchase returns, stock adjustments/transfers, cash collections,
     * price lists, credit limit requests). `endpoint` is the API base, e.g. `stock-adjustment-approvals`.
     */
    const props = defineProps<{
        endpoint: string;
        approvePath: string;
        rejectPath?: string;
        columns: Array<{ key: string; label: string }>;
    }>();

    const { Notify } = useCommons();
    const { props: pageProps } = usePage();

    type Row = Record<string, string | number | null>;

    const rows = ref<Row[]>([]);
    const loading = ref(false);
    const busyId = ref<number | null>(null);
    const search = ref('');
    const page = ref(1);
    const lastPage = ref(1);

    const authUser = computed(() => pageProps.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const canApprove = computed(() => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes(props.approvePath));
    const canReject = computed(() => Boolean(props.rejectPath) && (isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes(props.rejectPath as string)));

    async function load() {
        loading.value = true;

        try {
            const response = await window.axios.get(`/api/${props.endpoint}`, { params: { search: search.value, page: page.value } });
            rows.value = response.data?.data?.data ?? [];
            lastPage.value = response.data?.data?.last_page ?? 1;
        } catch (error: unknown) {
            rows.value = [];

            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || 'Failed to load records.', 'alert');
            }
        } finally {
            loading.value = false;
        }
    }

    async function act(row: Row, action: 'approve' | 'reject') {
        const id = Number(row.id);
        let reason: string | null = null;

        if (action === 'reject') {
            reason = window.prompt('Reason for rejection (optional)');

            if (reason === null) {
                return;
            }
        } else if (! window.confirm('Approve this record?')) {
            return;
        }

        busyId.value = id;

        try {
            const response = await window.axios.post(`/api/${props.endpoint}/${id}/${action}`, action === 'reject' ? { reason } : {});
            Notify(response.data?.message || 'Done', 'success');
            await load();
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                const errors = error.response?.data?.errors;
                Notify((errors && Object.values(errors).flat()[0] as string) || error.response?.data?.message || 'Action failed.', 'alert');
            }
        } finally {
            busyId.value = null;
        }
    }

    function goTo(next: number) {
        page.value = Math.min(Math.max(next, 1), lastPage.value);
        void load();
    }

    onMounted(load);
</script>

<template>
    <Head title="Approval" />

    <div class="card">
        <div class="card-body">
            <form class="mb-3 d-flex gap-2" @submit.prevent="page = 1; load()">
                <input v-model="search" type="search" class="form-control" placeholder="Search" style="max-width: 320px">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
            </form>

            <div v-if="loading" class="text-center py-4">Loading…</div>
            <div v-else-if="rows.length === 0" class="text-center py-4 text-muted">No pending records.</div>

            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th v-for="column in columns" :key="column.key">{{ column.label }}</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="Number(row.id)">
                            <td v-for="column in columns" :key="column.key">{{ row[column.key] ?? '-' }}</td>
                            <td class="text-nowrap">
                                <button
                                    v-if="canApprove"
                                    type="button"
                                    class="btn btn-success btn-sm me-1"
                                    :disabled="busyId === Number(row.id)"
                                    @click="act(row, 'approve')"
                                >
                                    Approve
                                </button>
                                <button
                                    v-if="canReject"
                                    type="button"
                                    class="btn btn-outline-danger btn-sm"
                                    :disabled="busyId === Number(row.id)"
                                    @click="act(row, 'reject')"
                                >
                                    Reject
                                </button>
                            </td>
                        </tr>
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
