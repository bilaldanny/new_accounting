<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Inter-Branch Transfers',
            subtitle: 'Transfers between the branches of one company and their elimination on consolidation',
            breadcrumbs: [{ title: 'Inter-Branch Transfers', href: 'NULL' }],
        },
    });

    type Transfer = { id: number; from_branch_id: number; to_branch_id: number; amount: number; transaction_date: string; description: string | null; status: string };
    type BranchRow = { branch_id: number; branch_name: string; due_from_branches: number; due_to_branches: number; net: number };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const rows = ref<Transfer[]>([]);
    const branches = ref<BranchRow[]>([]);
    const consolidated = ref<Record<string, number> | null>(null);
    const asOf = ref(new Date().toISOString().slice(0, 10));
    const settings = ref({ receivable_account_id: '' as number | '', payable_account_id: '' as number | '' });
    const form = ref({ from_branch_id: '' as number | '', to_branch_id: '' as number | '', amount: 0, transaction_date: new Date().toISOString().slice(0, 10), from_account_id: '' as number | '', to_account_id: '' as number | '', description: '' });

    async function load() {
        const [list, report] = await Promise.all([
            run(() => window.axios.get('/api/interbranch', { params: scope() })),
            run(() => window.axios.get('/api/interbranch/consolidation', { params: { ...scope(), as_of: asOf.value } })),
        ]);
        rows.value = list?.data?.data ?? [];
        settings.value = { receivable_account_id: list?.data?.settings?.receivable_account_id ?? '', payable_account_id: list?.data?.settings?.payable_account_id ?? '' };
        branches.value = report?.data?.branches ?? [];
        consolidated.value = report?.data?.consolidated ?? null;
    }

    async function saveSettings() {
        if (await run(() => window.axios.put('/api/interbranch/settings', { ...scope(), receivable_account_id: settings.value.receivable_account_id || null, payable_account_id: settings.value.payable_account_id || null }), 'Saved')) {
            await load();
        }
    }

    async function save() {
        if (await run(() => window.axios.post('/api/interbranch', { ...scope(), ...form.value }), 'Transfer saved')) {
            await load();
        }
    }

    async function reverse(row: Transfer) {
        if (window.confirm('Reverse this transfer? Both vouchers are removed.') && await run(() => window.axios.post(`/api/interbranch/${row.id}/reverse`, scope()), 'Reversed')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Inter-Branch Transfers" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div class="row g-3 mb-4">
            <div v-if="can('/interbranch/settings')" class="col-md-4">
                <div class="border rounded p-3">
                    <h6>Clearing accounts</h6>
                    <input v-model.number="settings.receivable_account_id" type="number" class="form-control form-control-sm mb-2" placeholder="Due from branches account id" aria-label="Due from branches account" />
                    <input v-model.number="settings.payable_account_id" type="number" class="form-control form-control-sm mb-2" placeholder="Due to branches account id" aria-label="Due to branches account" />
                    <button type="button" class="btn btn-dark btn-sm" @click="saveSettings">Save</button>
                </div>
            </div>
            <div v-if="can('/interbranch/add')" class="col-md-8">
                <div class="border rounded p-3">
                    <h6>New transfer</h6>
                    <div class="row g-2">
                        <div class="col-3"><input v-model.number="form.from_branch_id" type="number" class="form-control form-control-sm" placeholder="From branch id" aria-label="From branch" /></div>
                        <div class="col-3"><input v-model.number="form.to_branch_id" type="number" class="form-control form-control-sm" placeholder="To branch id" aria-label="To branch" /></div>
                        <div class="col-3"><input v-model.number="form.amount" type="number" class="form-control form-control-sm" placeholder="Amount" aria-label="Amount" /></div>
                        <div class="col-3"><input v-model="form.transaction_date" type="date" class="form-control form-control-sm" aria-label="Date" /></div>
                        <div class="col-3"><input v-model.number="form.from_account_id" type="number" class="form-control form-control-sm" placeholder="Giving branch account id" aria-label="Giving branch account" /></div>
                        <div class="col-3"><input v-model.number="form.to_account_id" type="number" class="form-control form-control-sm" placeholder="Receiving branch account id" aria-label="Receiving branch account" /></div>
                        <div class="col-6"><input v-model="form.description" class="form-control form-control-sm" placeholder="Description" aria-label="Description" /></div>
                        <div class="col-12"><button type="button" class="btn btn-dark btn-sm" @click="save">Save transfer</button></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="border rounded p-3 mb-4">
            <div class="row g-2 align-items-end mb-2">
                <div class="col-auto"><label class="form-label small" for="ib-asof">Consolidated as of</label><input id="ib-asof" v-model="asOf" type="date" class="form-control form-control-sm" @change="load" /></div>
            </div>
            <ClientTable  :columns="[{ key: 'c0', label: 'Branch' }, { key: 'c1', label: 'Due from branches' }, { key: 'c2', label: 'Due to branches' }, { key: 'c3', label: 'Net' }]" :rows="(branches).map((branch) => ({ __s: { branch }, c0: (branch.branch_name), c1: (money(branch.due_from_branches)), c2: (money(branch.due_to_branches)), c3: (money(branch.net)) }))" export-name="branch">
</ClientTable>
            <div v-if="consolidated" class="small">
                Eliminated on consolidation: <strong>{{ money(consolidated.eliminated) }}</strong>.
                Unmatched: <strong>{{ money(consolidated.unmatched) }}</strong>
                (a voucher still waiting for approval, or an entry posted by hand on a clearing account).
            </div>
        </div>

        <div class="table-responsive">
            <ClientTable empty-text="No transfers yet."  :columns="[{ key: 'c0', label: 'Date' }, { key: 'c1', label: 'From' }, { key: 'c2', label: 'To' }, { key: 'c3', label: 'Amount' }, { key: 'c4', label: 'Description' }, { key: 'c5', label: 'Status' }, { key: 'c6', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.transaction_date), c1: (row.from_branch_id), c2: (row.to_branch_id), c3: (money(row.amount)), c4: (row.description), c5: (row.status), c6: '' }))" export-name="date">
<template #cell-c6="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0"><button v-if="row.status === 'posted' && can('/interbranch/reverse')" type="button" class="btn btn-outline-danger btn-sm" @click="reverse(row)">Reverse</button></template></template>
</ClientTable>
        </div>
    </div></div></div>
</template>
