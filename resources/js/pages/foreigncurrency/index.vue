<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Foreign Currency',
            subtitle: 'Optional foreign-currency vouchers, dated rates and settlement gains and losses',
            breadcrumbs: [{ title: 'Foreign Currency', href: 'NULL' }],
        },
    });

    type Open = { id: number; voucher_no: string; voucher_date: string; side: string; currency_code: string; exchange_rate: number; foreign_amount: number; open_foreign: number; base_amount: number; contact: string };
    type Rate = { id: number; code: string; effective_on: string; rate: number };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const installed = ref(true);
    const enabled = ref(false);
    const fxAccount = ref<number | ''>('');
    const rates = ref<Rate[]>([]);
    const open = ref<Open[]>([]);
    const rate = ref({ currency_id: '' as number | '', effective_on: new Date().toISOString().slice(0, 10), rate: 0 });
    const voucher = ref({ branch_id: '' as number | '', date: new Date().toISOString().slice(0, 10), contact_id: '' as number | '', party_account_id: '' as number | '', counter_account_id: '' as number | '', currency_id: '' as number | '', foreign_amount: 0, exchange_rate: 0, side: 'debit' });
    const baseAmount = computed(() => (voucher.value.exchange_rate > 0 ? Math.round((voucher.value.foreign_amount / voucher.value.exchange_rate) * 100) / 100 : 0));

    async function load() {
        const response = await run(() => window.axios.get('/api/foreign-currency', { params: scope() }));
        const data = response?.data;
        installed.value = data?.installed ?? false;
        enabled.value = data?.enabled ?? false;
        fxAccount.value = data?.fx_gain_loss_account_id ?? '';
        rates.value = data?.rates ?? [];
        open.value = data?.open_lines ?? [];
    }

    async function saveSettings() {
        if (await run(() => window.axios.put('/api/foreign-currency/settings', { ...scope(), enabled: enabled.value, fx_gain_loss_account_id: fxAccount.value || null }), 'Saved')) {
            await load();
        }
    }

    async function addRate() {
        if (await run(() => window.axios.post('/api/foreign-currency/rates', { ...scope(), ...rate.value }), 'Rate saved')) {
            await load();
        }
    }

    async function postVoucher() {
        const v = voucher.value;
        const party = { account_id: Number(v.party_account_id), contact_id: Number(v.contact_id), currency_id: Number(v.currency_id), foreign_amount: v.foreign_amount, exchange_rate: v.exchange_rate };
        const lines = v.side === 'debit'
            ? [{ ...party, debit: baseAmount.value, credit: 0 }, { account_id: Number(v.counter_account_id), debit: 0, credit: baseAmount.value }]
            : [{ account_id: Number(v.counter_account_id), debit: baseAmount.value, credit: 0 }, { ...party, debit: 0, credit: baseAmount.value }];
        const body = { ...scope(), voucher_type: 'JV', voucher_date: v.date, comments: 'Foreign currency voucher', branch_id: v.branch_id, taccountdetails: lines };

        if (await run(() => window.axios.post('/api/journal-entries', body), 'Voucher saved')) {
            await load();
        }
    }

    async function settle(line: Open) {
        const settleRate = window.prompt(`Rate it settled at (${line.currency_code} per one unit of base currency; entered at ${line.exchange_rate})`, String(line.exchange_rate));
        const amount = window.prompt(`Foreign amount settled (up to ${line.open_foreign})`, String(line.open_foreign));

        if (! settleRate || ! amount) {
            return;
        }

        const body = { ...scope(), detail_id: line.id, foreign_amount: Number(amount), settlement_rate: Number(settleRate), settled_on: new Date().toISOString().slice(0, 10) };

        if (await run(() => window.axios.post('/api/foreign-currency/settle', body), 'Settlement recorded')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Foreign Currency" />

    <div class="container-fluid py-3">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        <div v-if="! installed" class="alert alert-warning small">The multi-currency tables are not installed on this database yet.</div>

        <div v-if="can('/foreigncurrency/settings')" class="border rounded p-3 mb-4">
            <h6>Foreign-currency vouchers</h6>
            <p class="small text-muted">Off by default. When on, a voucher line can carry a currency, its rate and the foreign amount; the base amount stays what is posted. A gain or loss is posted only when a line is settled.</p>
            <div class="row g-2 align-items-end">
                <div class="col-auto"><label class="small"><input v-model="enabled" type="checkbox" class="me-1" />Switched on</label></div>
                <div class="col-md-3"><label class="form-label small" for="fx-acc">Exchange gain / loss account id</label><input id="fx-acc" v-model.number="fxAccount" type="number" class="form-control form-control-sm" /></div>
                <div class="col-auto"><button type="button" class="btn btn-dark btn-sm" @click="saveSettings">Save</button></div>
            </div>
        </div>

        <div v-if="enabled" class="row g-3 mb-4">
            <div v-if="can('/foreigncurrency/rates')" class="col-md-5">
                <div class="border rounded p-3">
                    <h6>Dated exchange rate</h6>
                    <div class="row g-2">
                        <div class="col-4"><input v-model.number="rate.currency_id" type="number" class="form-control form-control-sm" placeholder="Currency id" aria-label="Currency id" /></div>
                        <div class="col-4"><input v-model="rate.effective_on" type="date" class="form-control form-control-sm" aria-label="Effective on" /></div>
                        <div class="col-4"><input v-model.number="rate.rate" type="number" step="0.000001" class="form-control form-control-sm" placeholder="Foreign per base" aria-label="Rate" /></div>
                        <div class="col-12"><button type="button" class="btn btn-outline-dark btn-sm" @click="addRate">Save rate</button></div>
                    </div>
                    <table class="table table-sm mt-3 mb-0">
                        <tbody><tr v-for="item in rates" :key="item.id"><td>{{ item.effective_on }}</td><td>{{ item.code }}</td><td class="text-end">{{ item.rate }}</td></tr></tbody>
                    </table>
                </div>
            </div>
            <div class="col-md-7">
                <div class="border rounded p-3">
                    <h6>Foreign-currency voucher</h6>
                    <div class="row g-2">
                        <div class="col-3"><input v-model.number="voucher.branch_id" type="number" class="form-control form-control-sm" placeholder="Branch id" aria-label="Branch id" /></div>
                        <div class="col-3"><input v-model="voucher.date" type="date" class="form-control form-control-sm" aria-label="Date" /></div>
                        <div class="col-3"><input v-model.number="voucher.contact_id" type="number" class="form-control form-control-sm" placeholder="Customer / supplier id" aria-label="Party id" /></div>
                        <div class="col-3"><input v-model.number="voucher.party_account_id" type="number" class="form-control form-control-sm" placeholder="Party account id" aria-label="Party account id" /></div>
                        <div class="col-3"><input v-model.number="voucher.counter_account_id" type="number" class="form-control form-control-sm" placeholder="Counter account id" aria-label="Counter account id" /></div>
                        <div class="col-3"><input v-model.number="voucher.currency_id" type="number" class="form-control form-control-sm" placeholder="Currency id" aria-label="Currency id" /></div>
                        <div class="col-3"><input v-model.number="voucher.foreign_amount" type="number" class="form-control form-control-sm" placeholder="Foreign amount" aria-label="Foreign amount" /></div>
                        <div class="col-3"><input v-model.number="voucher.exchange_rate" type="number" step="0.000001" class="form-control form-control-sm" placeholder="Rate" aria-label="Exchange rate" /></div>
                        <div class="col-3"><select v-model="voucher.side" class="form-select form-select-sm" aria-label="Side"><option value="debit">Receivable (debit)</option><option value="credit">Payable (credit)</option></select></div>
                        <div class="col-12 small">Base amount: <strong>{{ money(baseAmount) }}</strong></div>
                        <div class="col-12"><button type="button" class="btn btn-dark btn-sm" @click="postVoucher">Save voucher</button></div>
                    </div>
                </div>
            </div>
        </div>

        <h6>Open foreign-currency lines</h6>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Voucher</th><th>Date</th><th>Party</th><th>Side</th><th class="text-end">Foreign</th><th class="text-end">Open</th><th class="text-end">Rate</th><th class="text-end">Base</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="line in open" :key="line.id">
                        <td>{{ line.voucher_no }}</td><td>{{ line.voucher_date }}</td><td>{{ line.contact }}</td><td class="text-capitalize">{{ line.side }}</td>
                        <td class="text-end">{{ money(line.foreign_amount) }} {{ line.currency_code }}</td><td class="text-end">{{ money(line.open_foreign) }}</td><td class="text-end">{{ line.exchange_rate }}</td><td class="text-end">{{ money(line.base_amount) }}</td>
                        <td v-if="can('/foreigncurrency/settle')" class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="settle(line)">Settle</button></td>
                    </tr>
                    <tr v-if="! open.length"><td colspan="9" class="text-center text-muted">Nothing open in a foreign currency.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
