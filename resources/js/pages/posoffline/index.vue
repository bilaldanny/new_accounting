<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Offline POS Sales',
            subtitle: 'Sales rung up offline and what the server did with them',
            breadcrumbs: [{ title: 'Offline POS Sales', href: 'NULL' }],
        },
    });

    type Row = {
        id: number; client_uuid: string; device_id: string | null; offline_receipt_no: string | null; sold_at: string | null; total: number; status: string;
        conflict_code: string | null; conflict_detail: Record<string, unknown> | null; oversold: unknown[] | null; invoice_no: string | null; error: string | null;
    };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const rows = ref<Row[]>([]);
    const status = ref('');

    const explain: Record<string, string> = {
        price_changed: 'The price changed after the terminal cached its catalog.',
        product_missing: 'A product is no longer sold, or is not this company\'s.',
        period_locked: 'The day of the sale is in a locked period; accepting posts it today.',
        total_mismatch: 'The server worked out another total than the one charged.',
    };

    async function load() {
        const response = await run(() => window.axios.get('/api/pos/offline/sales', { params: { ...scope(), status: status.value || undefined } }));
        rows.value = response?.data?.data ?? [];
    }

    async function resolve(row: Row, action: 'accept' | 'discard') {
        const ask = action === 'accept' ? 'Post this sale as it was sold?' : 'Discard this sale? It will not be posted.';

        if (window.confirm(ask) && await run(() => window.axios.post(`/api/pos/offline/sales/${row.id}/resolve`, { ...scope(), action }), 'Saved')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Offline POS Sales" />

    <div class="container-fluid py-3">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        <div class="mb-3">
            <select v-model="status" class="form-select form-select-sm" style="width: 14rem" aria-label="Status" @change="load">
                <option value="">All</option><option value="conflict">Waiting for review</option><option value="failed">Failed</option><option value="synced">Posted</option><option value="discarded">Discarded</option>
            </select>
        </div>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Receipt</th><th>Device</th><th>Sold</th><th class="text-end">Total</th><th>Status</th><th>Invoice</th><th>Notes</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.offline_receipt_no }}</td><td>{{ row.device_id }}</td><td>{{ (row.sold_at ?? '').slice(0, 16).replace('T', ' ') }}</td><td class="text-end">{{ money(row.total) }}</td>
                        <td class="text-capitalize">{{ row.status }}</td><td>{{ row.invoice_no ?? '-' }}</td>
                        <td class="small">
                            <span v-if="row.conflict_code">{{ explain[row.conflict_code] ?? row.conflict_code }}</span>
                            <span v-if="row.error" class="text-danger">{{ row.error }}</span>
                            <span v-if="row.oversold?.length" class="text-warning">Sold past the stock on hand.</span>
                        </td>
                        <td v-if="row.status === 'conflict' && can('/posoffline/resolve')" class="text-end">
                            <button type="button" class="btn btn-dark btn-sm me-1" @click="resolve(row, 'accept')">Accept</button>
                            <button type="button" class="btn btn-outline-danger btn-sm" @click="resolve(row, 'discard')">Discard</button>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="8" class="text-center text-muted">No offline sales.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
