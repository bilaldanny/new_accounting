<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Subscription Billing',
            subtitle: 'Automatic invoices, reminders, dunning and pro-rata refunds',
            breadcrumbs: [{ title: 'Subscription Billing', href: 'NULL' }],
        },
    });

    type Reminder = { id: number; invoice_no: string; kind: string; channel: string; status: string; created_at: string };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    const reminders = ref<Reminder[]>([]);
    const result = ref('');
    const subscriptionId = ref<number | ''>('');
    const quote = ref<{ paid: number; unused_days: number; period_days: number; refund: number } | null>(null);

    async function load() {
        reminders.value = (await run(() => window.axios.get('/api/customer-subscription-billing/reminders', { params: scope() })))?.data?.data ?? [];
    }

    async function runNow() {
        const data = (await run(() => window.axios.post('/api/customer-subscription-billing/run', scope())))?.data?.data;
        result.value = data ? `Generated ${data.generated} invoice(s), ${data.upcoming} due-soon and ${data.dunning} dunning reminder(s).` : '';
        await load();
    }

    async function getQuote() {
        quote.value = (await run(() => window.axios.get(`/api/customer-subscriptions/${subscriptionId.value}/refund-quote`)))?.data?.data ?? null;
    }

    async function cancel(refund: boolean) {
        if (window.confirm(refund ? 'Cancel now and record the pro-rata refund?' : 'Cancel now with no refund?')
            && await run(() => window.axios.post(`/api/customer-subscriptions/${subscriptionId.value}/cancel-prorated`, { refund }), 'Subscription cancelled')) {
            quote.value = null;
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Subscription Billing" />

    <div class="container-fluid py-3">
        <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm mb-3" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />
        <div class="row g-3 mb-4">
            <div class="col-md-5">
                <div class="border rounded p-3">
                    <h6>Run billing now</h6>
                    <p class="small text-muted">It also runs every day: next-cycle invoices, a reminder three days before the due date, and the dunning level of an overdue invoice, each once.</p>
                    <button v-if="can('/customersubscriptioninvoices/add')" type="button" class="btn btn-dark btn-sm" @click="runNow">Run now</button>
                    <div v-if="result" class="small mt-2">{{ result }}</div>
                </div>
            </div>
            <div class="col-md-7">
                <div class="border rounded p-3">
                    <h6>Cancel with a pro-rata refund</h6>
                    <div class="d-flex gap-2 mb-2">
                        <input v-model.number="subscriptionId" type="number" class="form-control form-control-sm" style="width: 9rem" placeholder="Subscription id" aria-label="Subscription id" />
                        <button type="button" class="btn btn-outline-dark btn-sm" @click="getQuote">Quote</button>
                    </div>
                    <div v-if="quote" class="small">
                        Paid {{ money(quote.paid) }}, {{ quote.unused_days }} of {{ quote.period_days }} days unused: refund <strong>{{ money(quote.refund) }}</strong>.
                        <div v-if="can('/customersubscriptions/:id/edit')" class="mt-2">
                            <button type="button" class="btn btn-dark btn-sm me-2" @click="cancel(true)">Cancel and refund</button>
                            <button type="button" class="btn btn-outline-danger btn-sm" @click="cancel(false)">Cancel, no refund</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <table class="table table-sm">
            <thead><tr><th>When</th><th>Invoice</th><th>Reminder</th><th>Result</th></tr></thead>
            <tbody>
                <tr v-for="row in reminders" :key="row.id"><td>{{ row.created_at }}</td><td>{{ row.invoice_no }}</td><td class="text-capitalize">{{ row.kind.replace('_', ' ') }}</td><td class="text-capitalize">{{ row.status }}</td></tr>
                <tr v-if="! reminders.length"><td colspan="4" class="text-center text-muted">No reminders sent yet.</td></tr>
            </tbody>
        </table>
    </div>
</template>
