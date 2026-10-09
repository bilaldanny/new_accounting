<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Serials & Batches',
            subtitle: 'What is in stock by serial number and by batch, with expiry dates',
            breadcrumbs: [
                {
                    title: 'Serials & Batches',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify } = useCommons();
    const { props } = usePage();

    const authUser = computed(() => props.auth?.user as { rolename?: string; company_id?: number | null; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string) => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes(path);

    const tab = ref<'serials' | 'batches'>('serials');
    const companyId = ref<number | string>(authUser.value?.company_id ?? '');
    const companies = ref<Array<Record<string, any>>>([]);
    const branches = ref<Array<Record<string, any>>>([]);
    const products = ref<Array<Record<string, any>>>([]);
    const serials = ref<Array<Record<string, any>>>([]);
    const serialCounts = ref<Record<string, number>>({});
    const batches = ref<Array<Record<string, any>>>([]);
    const filters = reactive({ search: '', branch_id: '' as number | string, status: '', expiry: '' });
    const serialForm = reactive({ open: false, product_id: '' as number | string, branch_id: '' as number | string, serials: '', note: '' });
    const batchForm = reactive({ open: false, product_id: '' as number | string, branch_id: '' as number | string, batch_no: '', expiry_date: '', qty: '' as number | string, note: '' });
    const busy = ref(false);

    const serialProducts = computed(() => products.value.filter((product) => product.tracking_type === 'serial'));
    const batchProducts = computed(() => products.value.filter((product) => product.tracking_type === 'batch'));

    const STATUS_LABELS: Record<string, string> = { in_stock: 'In stock', sold: 'Sold', in_transit: 'In transit', written_off: 'Written off', none: 'Not in stock' };
    const EXPIRY_LABELS: Record<string, string> = { expired: 'Expired', expiring: 'Expiring soon', ok: 'OK', none: 'No expiry date' };

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    const scope = () => ({ company_id: companyId.value || undefined });

    async function loadSerials() {
        if (! companyId.value) {
            serials.value = [];

            return;
        }

        const data = (await window.axios.get('/api/stock-tracking/serials', { params: { ...scope(), search: filters.search, branch_id: filters.branch_id || undefined, status: filters.status || undefined, show_record: 200 } })).data?.data ?? {};
        serials.value = data.data ?? [];
        serialCounts.value = data.counts ?? {};
    }

    async function loadBatches() {
        if (! companyId.value) {
            batches.value = [];

            return;
        }

        batches.value = (await window.axios.get('/api/stock-tracking/batches', { params: { ...scope(), search: filters.search, branch_id: filters.branch_id || undefined, expiry: filters.expiry || undefined } })).data?.data ?? [];
    }

    async function load() {
        try {
            await (tab.value === 'serials' ? loadSerials() : loadBatches());
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    async function loadLookups() {
        if (! companyId.value) {
            branches.value = [];
            products.value = [];

            return;
        }

        const params = { company_id: companyId.value };
        const [branchList, productList] = await Promise.all([window.axios.get('/api/fetchbranches', { params }), window.axios.get('/api/fetchproducts', { params })]);
        branches.value = branchList.data ?? [];
        products.value = productList.data ?? [];
    }

    async function registerSerials() {
        busy.value = true;

        try {
            const response = await window.axios.post('/api/stock-tracking/serials', { ...scope(), product_id: serialForm.product_id, branch_id: serialForm.branch_id, serials: serialForm.serials, note: serialForm.note || null });
            Notify(response.data?.message ?? 'Serials registered', 'success');
            Object.assign(serialForm, { open: false, serials: '', note: '' });
            await loadSerials();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function writeOffSerial(serial: Record<string, any>) {
        const note = window.prompt(`Write off serial ${serial.serial_no}? Say why (optional):`, '');

        if (note === null) {
            return;
        }

        try {
            await window.axios.post(`/api/stock-tracking/serials/${serial.id}/write-off`, { ...scope(), note: note || null });
            Notify('Serial written off', 'success');
            await loadSerials();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    async function registerBatch() {
        busy.value = true;

        try {
            await window.axios.post('/api/stock-tracking/batches', { ...scope(), product_id: batchForm.product_id, branch_id: batchForm.branch_id, batch_no: batchForm.batch_no, expiry_date: batchForm.expiry_date || null, qty: batchForm.qty, note: batchForm.note || null });
            Notify('Batch registered', 'success');
            Object.assign(batchForm, { open: false, batch_no: '', expiry_date: '', qty: '', note: '' });
            await loadBatches();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function writeOffBatch(batch: Record<string, any>) {
        const qty = window.prompt(`How much of batch ${batch.batch_no} (${batch.branch_name}) to write off? It has ${batch.qty}.`, String(batch.qty));

        if (qty === null || qty === '') {
            return;
        }

        const note = window.prompt('Say why (optional):', '');

        try {
            await window.axios.post(`/api/stock-tracking/batches/${batch.batch_id}/write-off`, { ...scope(), branch_id: batch.branch_id, qty, note: note || null });
            Notify('Batch quantity written off', 'success');
            await loadBatches();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    watch(companyId, async () => {
        await loadLookups();
        await load();
    });
    watch(tab, load);

    onMounted(async () => {
        try {
            if (isSuperadmin.value) {
                companies.value = (await window.axios.get('/api/fetchcompanies')).data ?? [];
            }

            await loadLookups();
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    });
</script>

<template>
    <Head title="Serials & Batches" />

    <div v-if="isSuperadmin" class="card mb-3">
        <div class="card-body">
            <label class="form-label">Company</label>
            <select v-model="companyId" class="form-select" style="max-width: 320px">
                <option value="">Choose a company</option>
                <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.text ?? company.name }}</option>
            </select>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'serials' }" @click="tab = 'serials'">Serial / IMEI</button></li>
        <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'batches' }" @click="tab = 'batches'">Batches &amp; expiry</button></li>
    </ul>

    <div class="card">
        <div class="card-body">
            <form class="d-flex flex-wrap gap-2 mb-3" @submit.prevent="load">
                <input v-model="filters.search" type="search" class="form-control" :placeholder="tab === 'serials' ? 'Serial number' : 'Batch number'" style="max-width: 240px">
                <select v-model="filters.branch_id" class="form-select" style="max-width: 200px" @change="load"><option value="">All branches</option><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.text ?? branch.name }}</option></select>
                <select v-if="tab === 'serials'" v-model="filters.status" class="form-select" style="max-width: 180px" @change="load">
                    <option value="">Any status</option><option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
                </select>
                <select v-else v-model="filters.expiry" class="form-select" style="max-width: 180px" @change="load">
                    <option value="">Any expiry</option><option value="expired">Expired</option><option value="expiring">Expiring soon</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <button v-if="tab === 'serials' && can('/stocktracking/add')" type="button" class="btn btn-outline-primary btn-sm ms-auto" @click="serialForm.open = ! serialForm.open">Register serials</button>
                <button v-if="tab === 'batches' && can('/stocktracking/add')" type="button" class="btn btn-outline-primary btn-sm ms-auto" @click="batchForm.open = ! batchForm.open">Register batch</button>
            </form>

            <template v-if="tab === 'serials'">
                <form v-if="serialForm.open" class="row g-3 mb-3 border rounded p-3" @submit.prevent="registerSerials">
                    <p class="text-muted mb-0">Puts serials into stock without a document (opening stock, a return, a found unit). It does not change the stock quantity.</p>
                    <div class="col-md-4"><label class="form-label">Product (serial tracked)</label><select v-model="serialForm.product_id" class="form-select" required><option value="">Choose</option><option v-for="product in serialProducts" :key="product.id" :value="product.id">{{ product.name }}</option></select></div>
                    <div class="col-md-3"><label class="form-label">Branch</label><select v-model="serialForm.branch_id" class="form-select" required><option value="">Choose</option><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.text ?? branch.name }}</option></select></div>
                    <div class="col-md-5"><label class="form-label">Note (optional)</label><input v-model="serialForm.note" class="form-control" maxlength="255"></div>
                    <div class="col-12"><label class="form-label">Serial / IMEI numbers (one per line)</label><textarea v-model="serialForm.serials" class="form-control" rows="4" required /></div>
                    <div class="col-12"><button type="submit" class="btn btn-primary btn-sm" :disabled="busy">Register</button></div>
                </form>

                <p v-if="Object.keys(serialCounts).length" class="text-muted">
                    <span v-for="(count, status) in serialCounts" :key="status" class="me-3">{{ STATUS_LABELS[status] ?? status }}: <strong>{{ count }}</strong></span>
                </p>
                <p v-if="! serials.length" class="text-muted mb-0">No serial numbers yet. Mark a product as serial tracked, then receive it or register serials here.</p>
                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Serial / IMEI</th><th>Product</th><th>Status</th><th>Branch</th><th>Last movement</th><th /></tr></thead>
                        <tbody>
                            <tr v-for="serial in serials" :key="serial.id">
                                <td>{{ serial.serial_no }}</td><td>{{ serial.product_name }}</td><td>{{ STATUS_LABELS[serial.status] ?? serial.status }}</td><td>{{ serial.branch_name ?? '-' }}</td><td>{{ serial.last_moved_at ?? '-' }}</td>
                                <td class="text-nowrap"><button v-if="serial.status === 'in_stock' && can('/stocktracking/edit')" type="button" class="btn btn-outline-danger btn-sm" @click="writeOffSerial(serial)">Write off</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <template v-else>
                <form v-if="batchForm.open" class="row g-3 mb-3 border rounded p-3" @submit.prevent="registerBatch">
                    <p class="text-muted mb-0">Puts a quantity of a batch into a branch without a document (opening stock, a return). It does not change the stock quantity.</p>
                    <div class="col-md-4"><label class="form-label">Product (batch tracked)</label><select v-model="batchForm.product_id" class="form-select" required><option value="">Choose</option><option v-for="product in batchProducts" :key="product.id" :value="product.id">{{ product.name }}</option></select></div>
                    <div class="col-md-3"><label class="form-label">Branch</label><select v-model="batchForm.branch_id" class="form-select" required><option value="">Choose</option><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.text ?? branch.name }}</option></select></div>
                    <div class="col-md-2"><label class="form-label">Batch no</label><input v-model="batchForm.batch_no" class="form-control" maxlength="100" required></div>
                    <div class="col-md-3"><label class="form-label">Expiry date</label><input v-model="batchForm.expiry_date" type="date" class="form-control"></div>
                    <div class="col-md-2"><label class="form-label">Quantity</label><input v-model="batchForm.qty" type="number" step="any" min="0" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label">Note (optional)</label><input v-model="batchForm.note" class="form-control" maxlength="255"></div>
                    <div class="col-12"><button type="submit" class="btn btn-primary btn-sm" :disabled="busy">Register</button></div>
                </form>

                <p v-if="! batches.length" class="text-muted mb-0">No batches in stock. Mark a product as batch tracked, then receive it or register a batch here.</p>
                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Product</th><th>Batch</th><th>Branch</th><th>Expires</th><th class="text-end">In stock</th><th>Status</th><th /></tr></thead>
                        <tbody>
                            <tr v-for="batch in batches" :key="`${batch.batch_id}-${batch.branch_id}`">
                                <td>{{ batch.product_name }}</td><td>{{ batch.batch_no }}</td><td>{{ batch.branch_name }}</td><td>{{ batch.expiry_date ?? '-' }}</td><td class="text-end">{{ batch.qty }}</td>
                                <td><span :class="batch.expiry_state === 'expired' ? 'text-danger' : (batch.expiry_state === 'expiring' ? 'text-warning' : '')">{{ EXPIRY_LABELS[batch.expiry_state] }}</span></td>
                                <td class="text-nowrap"><button v-if="can('/stocktracking/edit')" type="button" class="btn btn-outline-danger btn-sm" @click="writeOffBatch(batch)">Write off</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>
</template>
