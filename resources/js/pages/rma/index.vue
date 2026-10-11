<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Supplier Returns (RMA)',
            subtitle: 'Authorisation, inspection and return receipt with a supplier',
            breadcrumbs: [{ title: 'Supplier Returns (RMA)', href: 'NULL' }],
        },
    });

    type Rma = {
        id: number; rma_no: string; supplier_rma_no: string | null; contact_id: number; transaction_id: number | null; status: string; reason: string | null;
        lines?: { id: number; quantity: number; defect: string | null; product?: { name: string } | null }[];
    };

    const flow: Record<string, string[]> = {
        requested: ['authorized', 'rejected'], authorized: ['inspected', 'shipped', 'closed'], inspected: ['shipped', 'closed'],
        shipped: ['received'], received: ['credited', 'closed'], credited: ['closed'], closed: [], rejected: [],
    };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Rma[]>([]);
    const status = ref('all');
    const form = ref({ contact_id: '' as number | '', transaction_id: '' as number | '', reason: '' });
    const lines = ref([{ product_id: '' as number | '', quantity: 1, defect: '' }]);

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/rma', { params: { ...scope(), status: status.value } })))?.data?.data?.data ?? [];
    }

    async function create() {
        const body = {
            ...scope(), ...form.value, transaction_id: form.value.transaction_id === '' ? null : form.value.transaction_id,
            lines: lines.value.filter((line) => line.product_id !== ''),
        };

        if (await run(() => window.axios.post('/api/rma', body), 'Request created')) {
            lines.value = [{ product_id: '', quantity: 1, defect: '' }];
            await load();
        }
    }

    async function move(row: Rma, next: string) {
        const body: Record<string, unknown> = { status: next };

        if (next === 'authorized') {
            const number = window.prompt('The authorisation number the supplier gave', row.supplier_rma_no ?? '');

            if (number === null) {
                return;
            }

            body.supplier_rma_no = number;
        }

        if (next === 'inspected') {
            body.inspection_note = window.prompt('Inspection note', '') ?? '';
        }

        if (next === 'credited' || next === 'received') {
            const returnId = window.prompt('Purchase return id (optional)', '');

            if (returnId) {
                body.purchase_return_id = Number(returnId);
            }
        }

        if (await run(() => window.axios.post(`/api/rma/${row.id}/status`, body), 'Updated')) {
            await load();
        }
    }

    async function remove(row: Rma) {
        if (window.confirm(`Delete ${row.rma_no}?`) && await run(() => window.axios.delete(`/api/rma/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Supplier Returns (RMA)" />

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
            <select v-model="status" class="form-select form-select-sm" style="width: auto" aria-label="Status" @change="load">
                <option value="all">All</option><option v-for="key in Object.keys(flow)" :key="key" :value="key">{{ key }}</option>
            </select>
        </div>

        <div v-if="can('/rma/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="rma-supplier">Supplier id</label><input id="rma-supplier" v-model.number="form.contact_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="rma-po">Purchase id (optional)</label><input id="rma-po" v-model.number="form.transaction_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-8"><label class="form-label small" for="rma-reason">Reason</label><input id="rma-reason" v-model="form.reason" class="form-control form-control-sm" /></div>
            </div>
            <table class="table table-sm align-middle mt-3">
                <thead><tr><th>Product id</th><th style="width: 9rem">Quantity</th><th>Defect</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="(line, index) in lines" :key="index">
                        <td><input v-model.number="line.product_id" type="number" class="form-control form-control-sm" aria-label="Product id" /></td>
                        <td><input v-model.number="line.quantity" type="number" min="0" step="any" class="form-control form-control-sm" aria-label="Quantity" /></td>
                        <td><input v-model="line.defect" class="form-control form-control-sm" aria-label="Defect" /></td>
                        <td class="text-end"><button type="button" class="btn btn-link btn-sm text-danger" @click="lines.splice(index, 1)">Remove</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="btn btn-outline-dark btn-sm me-2" @click="lines.push({ product_id: '', quantity: 1, defect: '' })">Add line</button>
            <button type="button" class="btn btn-dark btn-sm" @click="create">Request return</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>RMA</th><th>Supplier no.</th><th>Supplier</th><th>Items</th><th>Reason</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.rma_no }}</td><td>{{ row.supplier_rma_no }}</td><td>#{{ row.contact_id }}</td>
                        <td>{{ (row.lines ?? []).map((line) => `${line.product?.name ?? ''} x ${line.quantity}`).join(', ') }}</td><td>{{ row.reason }}</td><td class="text-capitalize">{{ row.status }}</td>
                        <td class="text-end text-nowrap">
                            <template v-if="can('/rma/:id/edit')">
                                <button v-for="next in flow[row.status]" :key="next" type="button" class="btn btn-outline-dark btn-sm me-1 text-capitalize" @click="move(row, next)">{{ next }}</button>
                            </template>
                            <button v-if="row.status === 'requested' && can('/rma/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="7" class="text-center text-muted">No return requests.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
