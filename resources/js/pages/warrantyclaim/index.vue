<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Warranty Claims',
            subtitle: 'After-sales claims judged against the warranty of the product',
            breadcrumbs: [{ title: 'Warranty Claims', href: 'NULL' }],
        },
    });

    type Claim = {
        id: number; claim_no: string; serial_no: string | null; issue: string; claimed_on: string; in_warranty: boolean; warranty_expires_on: string | null;
        status: string; resolution: string | null; resolution_note: string | null; product?: { name: string } | null; contact?: { business_name: string | null; first_name: string | null } | null;
    };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Claim[]>([]);
    const status = ref('all');
    const form = ref({ product_id: '' as number | '', transaction_id: '' as number | '', serial_no: '', issue: '', claimed_on: new Date().toISOString().slice(0, 10) });

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/warranty-claims', { params: { ...scope(), status: status.value } })))?.data?.data?.data ?? [];
    }

    async function create() {
        const body = { ...scope(), ...form.value, transaction_id: form.value.transaction_id === '' ? null : form.value.transaction_id };

        if (await run(() => window.axios.post('/api/warranty-claims', body), 'Claim recorded')) {
            form.value.issue = '';
            form.value.serial_no = '';
            await load();
        }
    }

    async function move(row: Claim, next: string) {
        let resolution: string | null = null;

        if (next === 'resolved') {
            resolution = window.prompt('How was it resolved? repair, replace, refund or none', 'repair');

            if (resolution === null) {
                return;
            }
        }

        const note = next === 'rejected' || ! row.in_warranty ? window.prompt('Reason / note (required for a claim out of warranty or a rejection)', '') : '';

        if (note === null) {
            return;
        }

        if (await run(() => window.axios.post(`/api/warranty-claims/${row.id}/status`, { status: next, resolution, resolution_note: note }), 'Updated')) {
            await load();
        }
    }

    async function remove(row: Claim) {
        if (window.confirm(`Delete ${row.claim_no}?`) && await run(() => window.axios.delete(`/api/warranty-claims/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Warranty Claims" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <select v-model="status" class="form-select form-select-sm" style="width: auto" aria-label="Status" @change="load">
                <option value="all">All</option><option value="open">Open</option><option value="in_review">In review</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="resolved">Resolved</option>
            </select>
        </div>

        <div v-if="can('/warrantyclaim/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="claim-product">Product id</label><input id="claim-product" v-model.number="form.product_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="claim-sale">Sale id (optional)</label><input id="claim-sale" v-model.number="form.transaction_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="claim-serial">Serial</label><input id="claim-serial" v-model="form.serial_no" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="claim-date">Claimed on</label><input id="claim-date" v-model="form.claimed_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-4"><label class="form-label small" for="claim-issue">Issue</label><input id="claim-issue" v-model="form.issue" class="form-control form-control-sm" /></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3" @click="create">Record claim</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Claim</th><th>Product</th><th>Customer</th><th>Issue</th><th>Claimed</th><th>Warranty</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.claim_no }}<div v-if="row.serial_no" class="text-muted small">{{ row.serial_no }}</div></td>
                        <td>{{ row.product?.name }}</td>
                        <td>{{ row.contact?.business_name || row.contact?.first_name }}</td>
                        <td>{{ row.issue }}</td>
                        <td>{{ row.claimed_on }}</td>
                        <td>{{ row.in_warranty ? 'In warranty' : 'Expired' }}<div v-if="row.warranty_expires_on" class="text-muted small">{{ row.warranty_expires_on }}</div></td>
                        <td class="text-capitalize">{{ row.status.replace('_', ' ') }}<div v-if="row.resolution" class="text-muted small">{{ row.resolution }}</div></td>
                        <td class="text-end text-nowrap">
                            <template v-if="can('/warrantyclaim/:id/edit')">
                                <button v-if="row.status === 'open'" type="button" class="btn btn-outline-dark btn-sm me-1" @click="move(row, 'in_review')">Review</button>
                                <button v-if="['open', 'in_review'].includes(row.status)" type="button" class="btn btn-dark btn-sm me-1" @click="move(row, 'approved')">Approve</button>
                                <button v-if="['open', 'in_review'].includes(row.status)" type="button" class="btn btn-outline-danger btn-sm me-1" @click="move(row, 'rejected')">Reject</button>
                                <button v-if="row.status === 'approved'" type="button" class="btn btn-dark btn-sm me-1" @click="move(row, 'resolved')">Resolve</button>
                            </template>
                            <button v-if="can('/warrantyclaim/delete')" type="button" class="btn btn-link btn-sm text-danger" @click="remove(row)">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="8" class="text-center text-muted">No claims.</td></tr>
                </tbody>
            </table>
        </div>
    </div></div></div>
</template>
