<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, reactive, ref } from 'vue';
    import LookupSelect from '@/components/LookupSelect.vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Expense Claims',
            subtitle: 'File a claim with its receipts, approval, then payment through an expense voucher',
            breadcrumbs: [{ title: 'Expense Claims', href: 'NULL' }],
        },
    });

    type Claim = { id: number; claim_no: string; title: string; claim_date: string; status: string; total: number; paid_on: string | null; first_name: string | null; last_name: string | null };
    type Line = { expense_date: string; account_id: number | ''; description: string; amount: number };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const claims = ref<Claim[]>([]);
    const detail = ref<Record<string, any> | null>(null);
    const form = reactive<{ title: string; claim_date: string; lines: Line[] }>({ title: '', claim_date: new Date().toISOString().slice(0, 10), lines: [{ expense_date: new Date().toISOString().slice(0, 10), account_id: '', description: '', amount: 0 }] });
    const pay = reactive({ payment_account_id: '' as number | '', paid_on: '' });
    const canAdd = computed(() => can('/expenseclaim/add'));
    const canApprove = computed(() => can('/expenseclaim/approve'));
    const canPay = computed(() => can('/expenseclaim/pay'));

    async function load() {
        claims.value = (await run(() => window.axios.get('/api/expense-claims', { params: scope() })))?.data?.data ?? [];
    }

    async function show(id: number) {
        detail.value = (await run(() => window.axios.get(`/api/expense-claims/${id}`, { params: scope() })))?.data?.data ?? null;
    }

    async function save() {
        const result = await run(() => window.axios.post('/api/expense-claims', { ...scope(), ...form }), 'Claim saved');

        if (result) {
            await load();
            await show(result.data.data.id);
        }
    }

    async function act(path: string, body: Record<string, unknown>, ok: string) {
        if (detail.value && (await run(() => window.axios.post(`/api/expense-claims/${detail.value!.id}${path}`, { ...scope(), ...body }), ok))) {
            await load();
            await show(detail.value.id);
        }
    }

    async function receipt(event: Event) {
        const file = (event.target as HTMLInputElement).files?.[0];

        if (! file || ! detail.value) {
            return;
        }

        const body = new FormData();
        body.append('file', file);
        Object.entries(scope()).forEach(([k, v]) => body.append(k, String(v)));

        if (await run(() => window.axios.post(`/api/expense-claims/${detail.value!.id}/receipts`, body), 'Receipt uploaded')) {
            await show(detail.value.id);
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Expense Claims" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div class="row g-3">
            <div class="col-lg-6">
                <div v-if="canAdd" class="border rounded p-3 mb-3">
                    <h6>New claim</h6>
                    <div class="row g-2 mb-2">
                        <div class="col-8"><input v-model="form.title" class="form-control form-control-sm" placeholder="What is it for" aria-label="Claim title" /></div>
                        <div class="col-4"><input v-model="form.claim_date" type="date" class="form-control form-control-sm" aria-label="Claim date" /></div>
                    </div>
                    <div v-for="(line, index) in form.lines" :key="index" class="row g-2 mb-1">
                        <div class="col-3"><input v-model="line.expense_date" type="date" class="form-control form-control-sm" aria-label="Expense date" /></div>
                        <div class="col-2"><LookupSelect v-model="line.account_id" endpoint="/api/fetchchildaccounts" :params="scope()" placeholder="Expense account" aria-label="Expense account" /></div>
                        <div class="col-4"><input v-model="line.description" class="form-control form-control-sm" placeholder="Description" aria-label="Description" /></div>
                        <div class="col-2"><input v-model.number="line.amount" type="number" class="form-control form-control-sm" aria-label="Amount" /></div>
                        <div class="col-1"><button type="button" class="btn btn-outline-danger btn-sm" @click="form.lines.splice(index, 1)">x</button></div>
                    </div>
                    <button type="button" class="btn btn-outline-dark btn-sm" @click="form.lines.push({ expense_date: form.claim_date, account_id: '', description: '', amount: 0 })">Add line</button>
                    <button type="button" class="btn btn-dark btn-sm ms-2" :disabled="! form.title || ! form.lines.length" @click="save">Save claim</button>
                </div>

                <table class="table table-sm">
                    <thead><tr><th>Claim</th><th>Title</th><th>Date</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="c in claims" :key="c.id" role="button" @click="show(c.id)"><td>{{ c.claim_no }}</td><td>{{ c.title }}</td><td>{{ String(c.claim_date).slice(0, 10) }}</td><td class="text-end">{{ money(c.total) }}</td><td class="text-capitalize">{{ c.status }}</td></tr>
                        <tr v-if="! claims.length"><td colspan="5" class="text-center text-muted">No claims.</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-if="detail" class="col-lg-6 border rounded p-3">
                <h6>{{ detail.claim_no }} - {{ detail.title }} <span class="badge text-bg-secondary text-capitalize">{{ detail.status }}</span></h6>
                <table class="table table-sm">
                    <thead><tr><th>Date</th><th>Account</th><th>Description</th><th class="text-end">Amount</th></tr></thead>
                    <tbody><tr v-for="l in detail.lines" :key="l.id"><td>{{ String(l.expense_date).slice(0, 10) }}</td><td>{{ l.account }}</td><td>{{ l.description }}</td><td class="text-end">{{ money(l.amount) }}</td></tr></tbody>
                    <tfoot><tr><th colspan="3">Total</th><th class="text-end">{{ money(detail.total) }}</th></tr></tfoot>
                </table>
                <div class="small mb-2">Receipts: <span v-for="r in detail.receipts" :key="r.id" class="me-2"><a :href="`/api/expense-claims/${detail.id}/receipts/${r.id}`" target="_blank" rel="noopener">{{ r.file_name }}</a></span><span v-if="! detail.receipts.length" class="text-muted">none</span></div>
                <div v-if="['draft', 'submitted'].includes(detail.status) && canAdd" class="mb-3"><input type="file" accept="application/pdf,image/*" class="form-control form-control-sm" aria-label="Receipt file" @change="receipt" /></div>
                <div class="d-flex flex-wrap gap-2">
                    <button v-if="detail.status === 'draft' && canAdd" type="button" class="btn btn-dark btn-sm" @click="act('/submit', {}, 'Claim submitted')">Submit</button>
                    <button v-if="['draft', 'submitted'].includes(detail.status) && canAdd" type="button" class="btn btn-outline-danger btn-sm" @click="act('/cancel', {}, 'Claim cancelled')">Cancel</button>
                    <template v-if="detail.status === 'submitted' && canApprove">
                        <button type="button" class="btn btn-success btn-sm" @click="act('/approve', {}, 'Claim approved')">Approve</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" @click="act('/reject', {}, 'Claim rejected')">Reject</button>
                    </template>
                </div>
                <div v-if="detail.status === 'approved' && canPay" class="d-flex gap-2 mt-3">
                    <div class="flex-grow-1"><LookupSelect v-model="pay.payment_account_id" endpoint="/api/fetchchildaccounts" :params="scope()" placeholder="Cash or bank account" aria-label="Payment account" /></div>
                    <input v-model="pay.paid_on" type="date" class="form-control form-control-sm" style="width: 10rem" aria-label="Paid on" />
                    <button type="button" class="btn btn-dark btn-sm" @click="act('/pay', { payment_account_id: pay.payment_account_id, paid_on: pay.paid_on || undefined }, 'Claim paid')">Pay</button>
                </div>
                <div v-if="detail.status === 'paid'" class="small text-success mt-2">Paid on {{ String(detail.paid_on).slice(0, 10) }} (voucher {{ detail.voucher_id }}).</div>
            </div>
        </div>
    </div></div></div>
</template>
