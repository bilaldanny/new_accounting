<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Portal Requests',
            subtitle: 'Quotation and order requests and bills sent in by customers and suppliers',
            breadcrumbs: [
                {
                    title: 'Portal Requests',
                    href: 'NULL',
                },
            ],
        },
    });

    type PortalRequest = {
        id: number;
        reference: string;
        kind: 'quotation' | 'order' | 'bill';
        status: string;
        note: string | null;
        lines: { name: string; quantity: number; unit_price: number }[];
        bill_no: string | null;
        bill_date: string | null;
        amount: number | null;
        file_name: string | null;
        has_file: boolean;
        review_note: string | null;
        transaction_id: number | null;
        contact_name: string | null;
        contact_code: string | null;
        created_at: string | null;
    };

    const page = usePage();
    const { Notify } = useCommons();

    const loading = ref(true);
    const busy = ref<number | null>(null);
    const status = ref('pending');
    const rows = ref<PortalRequest[]>([]);

    const user = computed(() => page.props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(user.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const canApprove = computed(() => isSuperadmin.value || (user.value?.permission_paths ?? []).includes('/portalrequests/approve'));

    const money = (value: number | null): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function errorMessage(error: unknown): string {
        if (! window.axios.isAxiosError(error)) {
            return 'Unexpected error occurred';
        }

        const errors = Object.values(error.response?.data?.errors ?? {}).flat() as string[];

        return errors[0] || error.response?.data?.message || 'The request failed.';
    }

    async function load() {
        loading.value = true;

        try {
            rows.value = (await window.axios.get('/api/portal-requests', { params: { status: status.value } })).data.data;
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function approve(row: PortalRequest) {
        const outcome = row.kind === 'quotation' ? 'create a quotation' : row.kind === 'order' ? 'create a draft sale' : 'accept this bill';

        if (! window.confirm(`Approve ${row.reference}? This will ${outcome}.`)) {
            return;
        }

        busy.value = row.id;

        try {
            await window.axios.post(`/api/portal-requests/${row.id}/approve`, {});
            Notify('Request approved', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            busy.value = null;
        }
    }

    async function reject(row: PortalRequest) {
        const note = window.prompt(`Why is ${row.reference} being rejected? (the customer or supplier will see this)`, '');

        if (note === null || note.trim().length < 3) {
            return;
        }

        busy.value = row.id;

        try {
            await window.axios.post(`/api/portal-requests/${row.id}/reject`, { note: note.trim() });
            Notify('Request rejected', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            busy.value = null;
        }
    }

    async function openDocument(row: PortalRequest) {
        try {
            const response = await window.axios.get(`/api/portal-requests/${row.id}/document`, { responseType: 'blob' });
            const url = URL.createObjectURL(response.data);
            const link = document.createElement('a');
            link.href = url;
            link.download = row.file_name ?? `bill-${row.id}`;
            link.click();
            URL.revokeObjectURL(url);
        } catch {
            Notify('The file could not be downloaded.', 'alert');
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Portal Requests" />
    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">

    <div class="container-fluid py-3">
        <div class="d-flex gap-2 mb-3">
            <select v-model="status" class="form-select form-select-sm" style="width: auto" @change="load">
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>

        <Loader v-if="loading" message="Loading requests…" />

        <div v-else class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr><th>Reference</th><th>From</th><th>Type</th><th>Details</th><th>Date</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.reference }}</td>
                        <td>{{ row.contact_name }} <span class="text-muted small">{{ row.contact_code }}</span></td>
                        <td class="text-capitalize">{{ row.kind }}</td>
                        <td>
                            <template v-if="row.kind === 'bill'">
                                Bill {{ row.bill_no }} dated {{ row.bill_date }} for {{ money(row.amount) }}
                                <button v-if="row.has_file" type="button" class="btn btn-link btn-sm py-0" @click="openDocument(row)">File</button>
                            </template>
                            <template v-else>{{ row.lines.map((line) => `${line.name} x ${line.quantity}`).join(', ') }}</template>
                            <div v-if="row.note" class="text-muted small">{{ row.note }}</div>
                            <div v-if="row.review_note" class="small">Reply: {{ row.review_note }}</div>
                        </td>
                        <td>{{ row.created_at ? row.created_at.slice(0, 10) : '' }}</td>
                        <td class="text-capitalize">
                            {{ row.status }}
                            <Link v-if="row.transaction_id" :href="`/sell/${row.transaction_id}/view`" class="ms-1 small">Open document</Link>
                        </td>
                        <td class="text-end text-nowrap">
                            <template v-if="row.status === 'pending' && canApprove">
                                <button type="button" class="btn btn-dark btn-sm me-1" :disabled="busy === row.id" @click="approve(row)">Approve</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" :disabled="busy === row.id" @click="reject(row)">Reject</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="7" class="text-center text-muted">Nothing here.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    </div></div></div>
</template>
