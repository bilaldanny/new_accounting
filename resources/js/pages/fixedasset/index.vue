<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Fixed Assets',
            subtitle: 'The register of everything the business owns and depreciates',
            breadcrumbs: [
                {
                    title: 'Fixed Assets',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify } = useCommons();
    const { props } = usePage();

    const authUser = computed(() => props.auth?.user as { rolename?: string; company_id?: number | null; branch_id?: number | null; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string) => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes(path);

    const methodLabels: Record<string, string> = { straight_line: 'Straight-line', declining_balance: 'Declining balance', wdv: 'WDV' };

    const emptyForm = () => ({
        id: null as number | null,
        company_id: authUser.value?.company_id ?? ('' as number | string),
        branch_id: authUser.value?.branch_id ?? ('' as number | string),
        asset_category_id: '' as number | string,
        cost_center_id: '' as number | string,
        name: '',
        description: '',
        serial_no: '',
        acquired_on: new Date().toISOString().slice(0, 10),
        in_service_on: '',
        cost: '' as number | string,
        salvage_value: '' as number | string,
        accumulated_depreciation: 0 as number | string,
        depreciated_through: '',
        method: '',
        useful_life_months: '' as number | string,
        rate: '' as number | string,
    });

    const assets = ref<Array<Record<string, any>>>([]);
    const categories = ref<Array<Record<string, any>>>([]);
    const branches = ref<Array<Record<string, any>>>([]);
    const companies = ref<Array<Record<string, any>>>([]);
    const form = reactive(emptyForm());
    const editing = ref(false);
    const detailsOnly = ref(false);
    const busy = ref(false);
    const filters = reactive({ search: '', status: '', category_id: '' });

    const accounts = ref<Array<Record<string, any>>>([]);
    const costCenters = ref<Array<Record<string, any>>>([]);
    const acquiring = ref(false);
    const acquire = reactive({ mode: 'asset', reference: '', offset_coa_id: '' as number | string });
    type ActionType = '' | 'cost' | 'capitalise' | 'transfer' | 'revalue' | 'impair' | 'dispose';
    const action = reactive<{ type: ActionType; asset: Record<string, any> | null; date: string; amount: number | string; offset_coa_id: number | string; description: string; salvage_value: number | string; to_branch_id: number | string; new_value: number | string }>({ type: '', asset: null, date: new Date().toISOString().slice(0, 10), amount: '', offset_coa_id: '', description: '', salvage_value: '', to_branch_id: '', new_value: '' });
    const actionTitles: Record<string, string> = { cost: 'Add a construction cost to', capitalise: 'Capitalise', transfer: 'Transfer to another branch:', revalue: 'Revalue', impair: 'Impair', dispose: 'Dispose of' };
    const history = ref<{ asset: Record<string, any>; events: Array<Record<string, any>> } | null>(null);

    const eventLabels: Record<string, string> = { acquisition: 'Acquired', cwip_cost: 'Construction cost', capitalisation: 'Capitalised', depreciation: 'Depreciation', transfer: 'Transferred', revaluation: 'Revaluation', impairment: 'Impairment', disposal: 'Disposal' };

    const money = (value: unknown): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const totals = computed(() => ({
        cost: assets.value.reduce((sum, asset) => sum + Number(asset.cost), 0),
        accumulated: assets.value.reduce((sum, asset) => sum + Number(asset.accumulated_depreciation), 0),
        book: assets.value.reduce((sum, asset) => sum + Number(asset.book_value), 0),
    }));

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function load() {
        assets.value = (await window.axios.get('/api/fixed-assets', { params: { ...filters, show_record: 100 } })).data?.data?.data ?? [];
    }

    async function loadLookups() {
        categories.value = form.company_id ? ((await window.axios.get('/api/asset-categories', { params: { company_id: form.company_id, show_record: 100 } })).data?.data?.data ?? []).filter((category: Record<string, any>) => category.active) : [];
        branches.value = form.company_id ? (await window.axios.get('/api/fetchbranches', { params: { company_id: form.company_id } })).data ?? [] : [];
        accounts.value = form.company_id ? (await window.axios.get('/api/fetchchildaccounts', { params: { company_id: form.company_id } })).data ?? [] : [];
        costCenters.value = form.company_id ? (await window.axios.get('/api/fetchcostcenters', { params: { company_id: form.company_id } })).data ?? [] : [];
    }

    function startNew() {
        Object.assign(form, emptyForm());
        detailsOnly.value = false;
        acquiring.value = false;
        editing.value = true;
    }

    function startAcquire() {
        Object.assign(form, emptyForm());
        Object.assign(acquire, { mode: 'asset', reference: '', offset_coa_id: '' });
        detailsOnly.value = false;
        acquiring.value = true;
        editing.value = true;
    }

    function startAction(type: ActionType, asset: Record<string, any>) {
        Object.assign(action, { type, asset, date: new Date().toISOString().slice(0, 10), amount: '', offset_coa_id: '', description: '', salvage_value: '', to_branch_id: '', new_value: '' });
    }

    async function runAction() {
        busy.value = true;

        try {
            if (action.type === 'cost') {
                await window.axios.post(`/api/fixed-assets/${action.asset?.id}/cwip-cost`, { date: action.date, amount: action.amount, offset_coa_id: action.offset_coa_id, description: action.description });
                Notify('Construction cost added', 'success');
            } else if (action.type === 'transfer') {
                await window.axios.post(`/api/fixed-assets/${action.asset?.id}/transfer`, { to_branch_id: action.to_branch_id, date: action.date, note: action.description });
                Notify('Asset transferred', 'success');
            } else if (action.type === 'revalue') {
                await window.axios.post(`/api/fixed-assets/${action.asset?.id}/revalue`, { date: action.date, new_value: action.new_value, reason: action.description });
                Notify('Revaluation sent for approval', 'success');
            } else if (action.type === 'impair') {
                await window.axios.post(`/api/fixed-assets/${action.asset?.id}/impair`, { date: action.date, amount: action.amount, reason: action.description });
                Notify('Impairment sent for approval', 'success');
            } else if (action.type === 'dispose') {
                await window.axios.post(`/api/fixed-assets/${action.asset?.id}/dispose`, { date: action.date, proceeds: action.amount === '' ? 0 : action.amount, proceeds_coa_id: action.offset_coa_id === '' ? null : action.offset_coa_id, reason: action.description });
                Notify('Disposal sent for approval', 'success');
            } else {
                await window.axios.post(`/api/fixed-assets/${action.asset?.id}/capitalise`, { in_service_on: action.date, salvage_value: action.salvage_value === '' ? null : action.salvage_value });
                Notify('Asset capitalised', 'success');
            }

            action.type = '';
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function showHistory(asset: Record<string, any>) {
        try {
            history.value = (await window.axios.get(`/api/fixed-assets/${asset.id}/history`)).data.data;
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    function edit(asset: Record<string, any>) {
        Object.assign(form, emptyForm(), asset, {
            description: asset.description ?? '',
            serial_no: asset.serial_no ?? '',
            in_service_on: asset.in_service_on ?? '',
            depreciated_through: asset.depreciated_through ?? '',
            cost_center_id: asset.cost_center_id ?? '',
            useful_life_months: asset.useful_life_months ?? '',
            rate: asset.rate ?? '',
        });
        detailsOnly.value = ! asset.deletable;
        editing.value = true;
    }

    function payload() {
        const body: Record<string, any> = { ...form };

        for (const key of ['in_service_on', 'salvage_value', 'method', 'useful_life_months', 'rate', 'depreciated_through', 'cost_center_id']) {
            body[key] = body[key] === '' ? null : body[key];
        }

        return body;
    }

    async function save() {
        busy.value = true;

        try {
            if (acquiring.value) {
                await window.axios.post('/api/fixed-assets/acquire', { ...payload(), ...acquire, accumulated_depreciation: undefined });
            } else if (form.id) {
                await window.axios.put(`/api/fixed-assets/${form.id}`, payload());
            } else {
                await window.axios.post('/api/fixed-assets', payload());
            }

            Notify(acquiring.value ? 'Asset acquired' : 'Asset saved', 'success');
            editing.value = false;
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function remove(asset: Record<string, any>) {
        if (! window.confirm(`Delete ${asset.code} - ${asset.name}?`)) {
            return;
        }

        try {
            await window.axios.delete(`/api/fixed-assets/${asset.id}`);
            Notify('Asset deleted', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    watch(() => form.company_id, loadLookups);

    onMounted(async () => {
        try {
            if (isSuperadmin.value) {
                companies.value = (await window.axios.get('/api/fetchcompanies')).data ?? [];
            }

            await Promise.all([load(), loadLookups()]);
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    });
</script>

<template>
    <Head title="Fixed Assets" />

    <div v-if="editing" class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h6>{{ acquiring ? 'Acquire an asset or start construction' : form.id ? 'Edit asset' : 'New asset carried over from before the register' }}</h6>
                <button type="button" class="btn-close" aria-label="Close" @click="editing = false" />
            </div>
            <p v-if="acquiring" class="text-muted small">This posts a journal voucher: the cost is debited to the category's asset account (or its construction-in-progress account) and credited to the account you choose. It follows your journal approval setting.</p>
            <p v-else-if="! form.id" class="text-muted small">This adds an asset you already own. It posts nothing to the ledger: its cost and accumulated depreciation are already in your opening balances.</p>
            <p v-if="detailsOnly" class="text-muted small">This asset has postings or depreciation, so only its name, description and serial number can change.</p>

            <form class="row g-3" @submit.prevent="save">
                <div v-if="isSuperadmin && ! form.id" class="col-md-4">
                    <label class="form-label">Company</label>
                    <select v-model="form.company_id" class="form-select" required>
                        <option value="">Choose a company</option>
                        <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.text ?? company.name }}</option>
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label">Name</label><input v-model="form.name" class="form-control" maxlength="200" required></div>
                <div class="col-md-4"><label class="form-label">Serial number</label><input v-model="form.serial_no" class="form-control" maxlength="100"></div>
                <div class="col-md-8"><label class="form-label">Description</label><input v-model="form.description" class="form-control" maxlength="500"></div>

                <template v-if="! detailsOnly">
                    <div class="col-md-4">
                        <label class="form-label">Category</label>
                        <select v-model="form.asset_category_id" class="form-select" required>
                            <option value="">Choose a category</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Branch</label>
                        <select v-model="form.branch_id" class="form-select" required>
                            <option value="">Choose a branch</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.text ?? branch.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Cost center (optional)</label>
                        <select v-model="form.cost_center_id" class="form-select"><option value="">None</option><option v-for="center in costCenters" :key="center.id" :value="center.id">{{ center.text }}</option></select>
                    </div>
                    <div class="col-md-2"><label class="form-label">Acquired on</label><input v-model="form.acquired_on" type="date" class="form-control" required></div>
                    <div class="col-md-2"><label class="form-label">In service on</label><input v-model="form.in_service_on" type="date" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">Cost</label><input v-model="form.cost" type="number" step="0.01" min="0.01" class="form-control" required></div>
                    <div class="col-md-3"><label class="form-label">Salvage value (blank: category %)</label><input v-model="form.salvage_value" type="number" step="0.01" min="0" class="form-control"></div>
                    <div v-if="! acquiring" class="col-md-3"><label class="form-label">Accumulated depreciation so far</label><input v-model="form.accumulated_depreciation" type="number" step="0.01" min="0" class="form-control"></div>
                    <div v-if="! acquiring" class="col-md-3"><label class="form-label">Depreciated through (date)</label><input v-model="form.depreciated_through" type="date" class="form-control"></div>
                    <div class="col-md-3">
                        <label class="form-label">Method (blank: category)</label>
                        <select v-model="form.method" class="form-select">
                            <option value="">Category default</option>
                            <option v-for="(label, key) in methodLabels" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label">Useful life (months)</label><input v-model="form.useful_life_months" type="number" min="1" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">Rate / factor</label><input v-model="form.rate" type="number" step="0.01" min="0.01" class="form-control"></div>
                </template>

                <template v-if="acquiring">
                    <div class="col-md-3">
                        <label class="form-label">What are you recording?</label>
                        <select v-model="acquire.mode" class="form-select"><option value="asset">An asset bought and ready to use</option><option value="cwip">Construction in progress</option></select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Paid from / owed to (credit account)</label>
                        <select v-model="acquire.offset_coa_id" class="form-select" required>
                            <option value="">Choose an account</option>
                            <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.text }}</option>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Reference</label><input v-model="acquire.reference" class="form-control" maxlength="100"></div>
                </template>

                <div class="col-12"><button type="submit" class="btn btn-primary btn-sm" :disabled="busy">{{ acquiring ? 'Post and save' : 'Save asset' }}</button></div>
            </form>
        </div>
    </div>

    <div v-if="action.type" class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h6>{{ actionTitles[action.type] }} {{ action.asset?.code }} {{ action.asset?.name }}</h6>
                <button type="button" class="btn-close" aria-label="Close" @click="action.type = ''" />
            </div>
            <form class="row g-3" @submit.prevent="runAction">
                <div class="col-md-3"><label class="form-label">{{ action.type === 'capitalise' ? 'In service on' : 'Date' }}</label><input v-model="action.date" type="date" class="form-control" required></div>
                <template v-if="action.type === 'cost'">
                    <div class="col-md-3"><label class="form-label">Amount</label><input v-model="action.amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
                    <div class="col-md-3">
                        <label class="form-label">Paid from / owed to</label>
                        <select v-model="action.offset_coa_id" class="form-select" required><option value="">Choose an account</option><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.text }}</option></select>
                    </div>
                    <div class="col-md-3"><label class="form-label">What was it for</label><input v-model="action.description" class="form-control" maxlength="255" required></div>
                </template>
                <template v-else-if="action.type === 'transfer'">
                    <div class="col-md-4">
                        <label class="form-label">To branch</label>
                        <select v-model="action.to_branch_id" class="form-select" required><option value="">Choose a branch</option><option v-for="branch in branches.filter((item) => item.id !== action.asset?.branch_id)" :key="branch.id" :value="branch.id">{{ branch.text ?? branch.name }}</option></select>
                    </div>
                    <div class="col-md-5"><label class="form-label">Note</label><input v-model="action.description" class="form-control" maxlength="500"></div>
                    <div class="col-12"><p class="text-muted small mb-0">Posts an entry in each branch moving the cost and accumulated depreciation through the category's inter-branch transfer account. Book value now: {{ money(action.asset?.book_value) }}.</p></div>
                </template>
                <template v-else-if="action.type === 'revalue'">
                    <div class="col-md-3"><label class="form-label">New value (book value now {{ money(action.asset?.book_value) }})</label><input v-model="action.new_value" type="number" step="0.01" min="0.01" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label">Reason</label><input v-model="action.description" class="form-control" maxlength="500" required></div>
                    <div class="col-12"><p class="text-muted small mb-0">A revaluation can only raise the value. It waits in the Approval Center and posts nothing until someone approves it.</p></div>
                </template>
                <template v-else-if="action.type === 'impair'">
                    <div class="col-md-3"><label class="form-label">Amount to write off</label><input v-model="action.amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label">Reason</label><input v-model="action.description" class="form-control" maxlength="500" required></div>
                    <div class="col-12"><p class="text-muted small mb-0">Book value now {{ money(action.asset?.book_value) }}. It waits in the Approval Center and posts nothing until someone approves it.</p></div>
                </template>
                <template v-else-if="action.type === 'dispose'">
                    <div class="col-md-3"><label class="form-label">Sale proceeds (0 if scrapped)</label><input v-model="action.amount" type="number" step="0.01" min="0" class="form-control"></div>
                    <div class="col-md-4">
                        <label class="form-label">Proceeds paid into</label>
                        <select v-model="action.offset_coa_id" class="form-select"><option value="">No proceeds</option><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.text }}</option></select>
                    </div>
                    <div class="col-md-5"><label class="form-label">Reason</label><input v-model="action.description" class="form-control" maxlength="500" required></div>
                    <div class="col-12"><p class="text-muted small mb-0">Depreciation must be booked up to the month before the disposal date. It waits in the Approval Center and posts nothing until someone approves it.</p></div>
                </template>
                <template v-else>
                    <div class="col-md-3"><label class="form-label">Salvage value (blank: category %)</label><input v-model="action.salvage_value" type="number" step="0.01" min="0" class="form-control"></div>
                    <div class="col-12"><p class="text-muted small mb-0">The whole cost ({{ money(action.asset?.cost) }}) moves from construction in progress to the asset account, and depreciation starts from this date.</p></div>
                </template>
                <div class="col-12"><button type="submit" class="btn btn-primary btn-sm" :disabled="busy">{{ { cost: 'Post cost', capitalise: 'Capitalise', transfer: 'Transfer', revalue: 'Send for approval', impair: 'Send for approval', dispose: 'Send for approval' }[action.type] }}</button></div>
            </form>
        </div>
    </div>

    <div v-if="history" class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h6>History of {{ history.asset.code }} {{ history.asset.name }} (book value {{ money(history.asset.book_value) }})</h6>
                <button type="button" class="btn-close" aria-label="Close" @click="history = null" />
            </div>
            <p v-if="! history.events.length" class="text-muted mb-0">Nothing recorded yet.</p>
            <table v-else class="table table-sm">
                <thead><tr><th>Date</th><th>Event</th><th>Detail</th><th class="text-end">Amount</th><th>Voucher</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="event in history.events" :key="event.type + event.id">
                        <td>{{ event.event_date }}</td><td>{{ eventLabels[event.type] ?? event.type }}</td><td>{{ event.description }}</td>
                        <td class="text-end">{{ money(event.amount) }}</td><td>{{ event.voucher_no ?? '-' }}</td><td>{{ event.status }}{{ event.voucher_status && event.voucher_status !== 'approved' ? ' (voucher ' + event.voucher_status + ')' : '' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form class="d-flex flex-wrap gap-2 mb-3" @submit.prevent="load">
                <input v-model="filters.search" type="search" class="form-control" placeholder="Code, name or serial" style="max-width: 260px">
                <select v-model="filters.status" class="form-select" style="max-width: 180px">
                    <option value="">All statuses</option><option value="active">Active</option><option value="cwip">In progress</option><option value="disposed">Disposed</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <button v-if="can('/fixedasset/acquire')" type="button" class="btn btn-primary btn-sm ms-auto" @click="startAcquire">Acquire / build</button>
                <button v-if="can('/fixedasset/add')" type="button" class="btn btn-outline-primary btn-sm" @click="startNew">Add existing asset</button>
            </form>
            <p v-if="! assets.length" class="text-muted mb-0">No assets found.</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Branch</th><th>Status</th><th class="text-end">Cost</th><th class="text-end">Accumulated</th><th class="text-end">Book value</th><th /></tr></thead>
                    <tbody>
                        <tr v-for="asset in assets" :key="asset.id">
                            <td>{{ asset.code }}</td><td>{{ asset.name }}</td><td>{{ asset.category_name }}</td><td>{{ asset.branch_name }}</td><td>{{ asset.status }}</td>
                            <td class="text-end">{{ money(asset.cost) }}</td><td class="text-end">{{ money(asset.accumulated_depreciation) }}</td><td class="text-end fw-bold">{{ money(asset.book_value) }}</td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-outline-secondary btn-sm me-1" @click="showHistory(asset)">History</button>
                                <button v-if="asset.status === 'cwip' && can('/fixedasset/cwip')" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="startAction('cost', asset)">Add cost</button>
                                <button v-if="asset.status === 'cwip' && can('/fixedasset/capitalise')" type="button" class="btn btn-outline-primary btn-sm me-1" @click="startAction('capitalise', asset)">Capitalise</button>
                                <template v-if="asset.status === 'active'">
                                    <button v-if="can('/fixedasset/transfer')" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="startAction('transfer', asset)">Transfer</button>
                                    <button v-if="can('/fixedasset/revalue')" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="startAction('revalue', asset)">Revalue</button>
                                    <button v-if="can('/fixedasset/impair')" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="startAction('impair', asset)">Impair</button>
                                    <button v-if="can('/fixedasset/dispose')" type="button" class="btn btn-outline-danger btn-sm me-1" @click="startAction('dispose', asset)">Dispose</button>
                                </template>
                                <button v-if="can('/fixedasset/:id/edit')" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="edit(asset)">Edit</button>
                                <button v-if="can('/fixedasset/delete') && asset.deletable" type="button" class="btn btn-outline-danger btn-sm" @click="remove(asset)">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot><tr><th colspan="5">Total</th><th class="text-end">{{ money(totals.cost) }}</th><th class="text-end">{{ money(totals.accumulated) }}</th><th class="text-end">{{ money(totals.book) }}</th><th /></tr></tfoot>
                </table>
            </div>
        </div>
    </div>
</template>
