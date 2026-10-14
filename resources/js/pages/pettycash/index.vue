<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Petty Cash',
            subtitle: 'Imprest funds: spend from the float and replenish it back to its set amount',
            breadcrumbs: [{ title: 'Petty Cash', href: 'NULL' }],
        },
    });

    type Fund = { id: number; name: string; imprest_amount: number; balance: number; to_replenish: number; cash_account: string | null; cash_account_id: number; is_active: boolean };
    type Entry = { id: number; entry_type: string; entry_date: string; payee: string | null; description: string | null; receipt_no: string | null; amount: number; account: string | null; voucher_status: string | null };

    const today = new Date().toISOString().slice(0, 10);
    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const funds = ref<Fund[]>([]);
    const selected = ref<Fund | null>(null);
    const entries = ref<Entry[]>([]);
    const fundForm = ref({ name: '', imprest_amount: '' as number | '', cash_account_id: '' as number | '' });
    const expense = ref({ entry_date: today, amount: '' as number | '', expense_account_id: '' as number | '', payee: '', description: '', receipt_no: '' });
    const replenish = ref({ entry_date: today, amount: '' as number | '', source_account_id: '' as number | '', description: '' });

    async function load() {
        funds.value = (await run(() => window.axios.get('/api/petty-cash/funds', { params: scope() })))?.data?.data ?? [];

        if (selected.value) {
            selected.value = funds.value.find((fund) => fund.id === selected.value?.id) ?? null;
        }
    }

    async function open(fund: Fund) {
        selected.value = fund;
        entries.value = (await run(() => window.axios.get(`/api/petty-cash/funds/${fund.id}/entries`)))?.data?.data ?? [];
    }

    async function createFund() {
        if (await run(() => window.axios.post('/api/petty-cash/funds', { ...scope(), ...fundForm.value }), 'Fund created')) {
            fundForm.value = { name: '', imprest_amount: '', cash_account_id: '' };
            await load();
        }
    }

    async function spend() {
        if (! selected.value) {
            return;
        }

        if (await run(() => window.axios.post(`/api/petty-cash/funds/${selected.value?.id}/expense`, { ...expense.value }), 'Expense recorded')) {
            expense.value = { ...expense.value, amount: '', payee: '', description: '', receipt_no: '' };
            await load();
            await open(selected.value as Fund);
        }
    }

    async function topUp() {
        if (! selected.value) {
            return;
        }

        const body: Record<string, unknown> = { ...replenish.value };

        if (body.amount === '') {
            delete body.amount;
        }

        if (await run(() => window.axios.post(`/api/petty-cash/funds/${selected.value?.id}/replenish`, body), 'Fund replenished')) {
            await load();
            await open(selected.value as Fund);
        }
    }

    async function remove(entry: Entry) {
        if (window.confirm('Delete this entry and its voucher?') && await run(() => window.axios.delete(`/api/petty-cash/entries/${entry.id}`), 'Deleted')) {
            await load();

            if (selected.value) {
                await open(selected.value);
            }
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Petty Cash" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div v-if="can('/pettycash/add')" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label small" for="pc-name">Fund name</label><input id="pc-name" v-model="fundForm.name" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="pc-imprest">Imprest amount</label><input id="pc-imprest" v-model.number="fundForm.imprest_amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-3"><label class="form-label small" for="pc-cash">Cash account id</label><input id="pc-cash" v-model.number="fundForm.cash_account_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-dark btn-sm" @click="createFund">Add fund</button></div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-5">
                <ClientTable empty-text="No petty cash funds."  :columns="[{ key: 'c0', label: 'Fund' }, { key: 'c1', label: 'Imprest' }, { key: 'c2', label: 'Balance' }, { key: 'c3', label: 'To replenish' }, { key: 'c4', label: '' }]" :rows="(funds).map((fund) => ({ __s: { fund }, c0: '', c1: (money(fund.imprest_amount)), c2: (money(fund.balance)), c3: (money(fund.to_replenish)), c4: '' }))" export-name="fund">
<template #cell-c0="{ row: __m }"><template v-for="{ fund } in [__m.__s]" :key="0">{{ fund.name }}<span v-if="! fund.is_active" class="badge text-bg-secondary ms-1">off</span></template></template>
<template #cell-c4="{ row: __m }"><template v-for="{ fund } in [__m.__s]" :key="0"><button type="button" class="btn btn-outline-dark btn-sm" @click="open(fund)">Open</button></template></template>
</ClientTable>
            </div>

            <div v-if="selected" class="col-lg-7">
                <h6>{{ selected.name }} <span class="text-muted">- cash account {{ selected.cash_account ?? '#' + selected.cash_account_id }}</span></h6>

                <div v-if="can('/pettycash/expense')" class="border rounded p-2 mb-3">
                    <div class="row g-2">
                        <div class="col-md-3"><label class="form-label small" for="pe-date">Date</label><input id="pe-date" v-model="expense.entry_date" type="date" class="form-control form-control-sm" /></div>
                        <div class="col-md-3"><label class="form-label small" for="pe-amount">Amount</label><input id="pe-amount" v-model.number="expense.amount" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                        <div class="col-md-3"><label class="form-label small" for="pe-account">Expense account id</label><input id="pe-account" v-model.number="expense.expense_account_id" type="number" class="form-control form-control-sm" /></div>
                        <div class="col-md-3"><label class="form-label small" for="pe-receipt">Receipt no.</label><input id="pe-receipt" v-model="expense.receipt_no" class="form-control form-control-sm" /></div>
                        <div class="col-md-4"><label class="form-label small" for="pe-payee">Paid to</label><input id="pe-payee" v-model="expense.payee" class="form-control form-control-sm" /></div>
                        <div class="col-md-6"><label class="form-label small" for="pe-desc">What for</label><input id="pe-desc" v-model="expense.description" class="form-control form-control-sm" /></div>
                        <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-dark btn-sm" @click="spend">Spend</button></div>
                    </div>
                </div>

                <div v-if="can('/pettycash/replenish')" class="border rounded p-2 mb-3">
                    <div class="row g-2">
                        <div class="col-md-3"><label class="form-label small" for="pr-date">Date</label><input id="pr-date" v-model="replenish.entry_date" type="date" class="form-control form-control-sm" /></div>
                        <div class="col-md-3"><label class="form-label small" for="pr-amount">Amount</label><input id="pr-amount" v-model.number="replenish.amount" type="number" min="0" step="any" class="form-control form-control-sm" :placeholder="money(selected.to_replenish)" /></div>
                        <div class="col-md-3"><label class="form-label small" for="pr-source">From account id</label><input id="pr-source" v-model.number="replenish.source_account_id" type="number" class="form-control form-control-sm" /></div>
                        <div class="col-md-3 d-flex align-items-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="topUp">Replenish</button></div>
                    </div>
                </div>

                <ClientTable empty-text="No entries."  :columns="[{ key: 'c0', label: 'Date' }, { key: 'c1', label: 'Type' }, { key: 'c2', label: 'Detail' }, { key: 'c3', label: 'Amount' }, { key: 'c4', label: 'Voucher' }, { key: 'c5', label: '' }]" :rows="(entries).map((entry) => ({ __s: { entry }, c0: (entry.entry_date?.slice(0, 10)), c1: (entry.entry_type), c2: '', c3: (money(entry.amount)), c4: (entry.voucher_status), c5: '' }))" export-name="date">
<template #cell-c2="{ row: __m }"><template v-for="{ entry } in [__m.__s]" :key="0">{{ entry.payee }} {{ entry.description }} <small class="text-muted">{{ entry.receipt_no }} {{ entry.account }}</small></template></template>
<template #cell-c5="{ row: __m }"><template v-for="{ entry } in [__m.__s]" :key="0"><button v-if="can('/pettycash/expense')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(entry)">Delete</button></template></template>
</ClientTable>
            </div>
        </div>
    </div></div></div>
</template>
