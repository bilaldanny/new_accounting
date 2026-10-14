<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Guarantees & Letters of Credit',
            subtitle: 'Register of bank guarantees and LCs with their expiry',
            breadcrumbs: [{ title: 'Guarantees & Letters of Credit', href: 'NULL' }],
        },
    });

    type Instrument = {
        id: number; kind: string; reference_no: string; bank_name: string | null; contact_name: string | null; amount: number; currency: string | null;
        margin_percent: number; charges: number; issued_on: string | null; expires_on: string | null; status: string; state: string; days_to_expiry: number | null; purpose: string | null;
    };

    const statuses = ['open', 'utilised', 'released', 'expired', 'cancelled'];
    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const rows = ref<Instrument[]>([]);
    const filter = ref({ kind: '', status: 'all', expiring: false });
    const editing = ref<number | null>(null);
    const blank = () => ({ kind: 'guarantee', reference_no: '', bank_name: '', contact_id: '' as number | '', amount: '' as number | '', currency: '', margin_percent: 0, charges: 0, issued_on: '', expires_on: '', status: 'open', purpose: '' });
    const form = ref(blank());

    async function load() {
        const params: Record<string, unknown> = { ...scope(), status: filter.value.status };

        if (filter.value.kind) {
            params.kind = filter.value.kind;
        }

        if (filter.value.expiring) {
            params.expiring_within = 30;
        }

        rows.value = (await run(() => window.axios.get('/api/bank-instruments', { params })))?.data?.data?.data ?? [];
    }

    function edit(row: Instrument) {
        editing.value = row.id;
        form.value = {
            kind: row.kind, reference_no: row.reference_no, bank_name: row.bank_name ?? '', contact_id: '', amount: row.amount, currency: row.currency ?? '',
            margin_percent: row.margin_percent, charges: row.charges, issued_on: row.issued_on?.slice(0, 10) ?? '', expires_on: row.expires_on?.slice(0, 10) ?? '',
            status: row.status, purpose: row.purpose ?? '',
        };
    }

    async function save() {
        const body: Record<string, unknown> = { ...scope(), ...form.value };

        for (const key of ['contact_id', 'issued_on', 'expires_on', 'currency', 'bank_name', 'purpose']) {
            if (body[key] === '') {
                delete body[key];
            }
        }

        const request = editing.value === null ? () => window.axios.post('/api/bank-instruments', body) : () => window.axios.put(`/api/bank-instruments/${editing.value}`, body);

        if (await run(request, 'Saved')) {
            editing.value = null;
            form.value = blank();
            await load();
        }
    }

    async function remove(row: Instrument) {
        if (window.confirm(`Delete ${row.reference_no}?`) && await run(() => window.axios.delete(`/api/bank-instruments/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Guarantees & Letters of Credit" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <select v-model="filter.kind" class="form-select form-select-sm" style="width: auto" aria-label="Kind" @change="load"><option value="">All kinds</option><option value="guarantee">Guarantees</option><option value="lc">Letters of credit</option></select>
            <select v-model="filter.status" class="form-select form-select-sm" style="width: auto" aria-label="Status" @change="load"><option value="all">All statuses</option><option v-for="s in statuses" :key="s" :value="s">{{ s }}</option></select>
            <label class="form-check-label small"><input v-model="filter.expiring" type="checkbox" class="form-check-input me-1" @change="load" />Expiring in 30 days</label>
        </div>

        <div v-if="can('/bankinstrument/add') || (editing !== null && can('/bankinstrument/:id/edit'))" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" for="bi-kind">Kind</label>
                    <select id="bi-kind" v-model="form.kind" class="form-select form-select-sm"><option value="guarantee">Bank guarantee</option><option value="lc">Letter of credit</option></select>
                </div>
                <div class="col-md-2"><label class="form-label small" for="bi-ref">Reference</label><input id="bi-ref" v-model="form.reference_no" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="bi-bank">Bank</label><input id="bi-bank" v-model="form.bank_name" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="bi-amount">Amount</label><input id="bi-amount" v-model.number="form.amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-1"><label class="form-label small" for="bi-cur">Cur.</label><input id="bi-cur" v-model="form.currency" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="bi-status">Status</label>
                    <select id="bi-status" v-model="form.status" class="form-select form-select-sm"><option v-for="s in statuses" :key="s" :value="s">{{ s }}</option></select>
                </div>
                <div class="col-md-2"><label class="form-label small" for="bi-issued">Issued</label><input id="bi-issued" v-model="form.issued_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="bi-expires">Expires</label><input id="bi-expires" v-model="form.expires_on" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="bi-margin">Margin %</label><input id="bi-margin" v-model.number="form.margin_percent" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="bi-charges">Charges</label><input id="bi-charges" v-model.number="form.charges" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="bi-party">Party id</label><input id="bi-party" v-model.number="form.contact_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-12"><label class="form-label small" for="bi-purpose">Purpose</label><input id="bi-purpose" v-model="form.purpose" class="form-control form-control-sm" /></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3 me-2" @click="save">{{ editing === null ? 'Add' : 'Save' }}</button>
            <button v-if="editing !== null" type="button" class="btn btn-outline-dark btn-sm mt-3" @click="editing = null; form = blank()">Cancel</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Kind</th><th>Reference</th><th>Bank</th><th>Party</th><th class="text-end">Amount</th><th>Expires</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td class="text-capitalize">{{ row.kind === 'lc' ? 'LC' : 'Guarantee' }}</td><td>{{ row.reference_no }}</td><td>{{ row.bank_name }}</td><td>{{ row.contact_name }}</td>
                        <td class="text-end">{{ money(row.amount) }} <small class="text-muted">{{ row.currency }}</small></td>
                        <td>{{ row.expires_on?.slice(0, 10) }}<small v-if="row.days_to_expiry !== null && row.state === 'open'" class="text-muted ms-1">({{ row.days_to_expiry }} d)</small></td>
                        <td><span class="badge" :class="{ 'text-bg-success': row.state === 'open', 'text-bg-danger': row.state === 'expired', 'text-bg-secondary': ! ['open', 'expired'].includes(row.state) }">{{ row.state }}</span></td>
                        <td class="text-end text-nowrap">
                            <button v-if="can('/bankinstrument/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(row)">Edit</button>
                            <button v-if="can('/bankinstrument/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="! rows.length"><td colspan="8" class="text-center text-muted">Nothing registered.</td></tr>
                </tbody>
            </table>
        </div>
    </div></div></div>
</template>
