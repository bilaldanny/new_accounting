<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import { onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Subscription Invoices',
            subtitle: 'Invoices billed to your customers — generate, mark paid, refund, cancel',
            breadcrumbs: [
                { title: 'Subscription Invoices', href: 'NULL' },
            ],
        },
    });

    const METHODS = ['cash', 'card', 'cheque', 'bank_transfer', 'other'];

    type Invoice = {
        id: number;
        invoice_no: string;
        customer_name: string | null;
        period_start: string;
        period_end: string;
        due_date: string;
        total_amount: number;
        status: string;
    };

    const { Notify } = useCommons();

    const loading = ref(true);
    const generating = ref(false);
    const invoices = ref<Invoice[]>([]);

    const payingInvoice = ref<Invoice | null>(null);
    const payMethod = ref('cash');
    const payReference = ref('');
    const payAmount = ref<number | ''>('');
    const paying = ref(false);

    const refundingInvoice = ref<Invoice | null>(null);
    const refundAmount = ref<number | ''>('');
    const refunding = ref(false);

    function statusBadge(status: string): string {
        return {
            unpaid: 'badge bg-secondary-subtle text-secondary',
            paid: 'badge bg-success-subtle text-success',
            overdue: 'badge bg-danger-subtle text-danger',
            cancelled: 'badge bg-dark-subtle text-dark',
            refunded: 'badge bg-warning-subtle text-warning',
        }[status] ?? 'badge bg-secondary-subtle text-secondary';
    }

    function errorMessage(error: unknown): string {
        if (! window.axios.isAxiosError(error)) {
            return 'Unexpected error occurred';
        }

        const errors = Object.values(error.response?.data?.errors ?? {}).flat() as string[];

        return errors[0] || error.response?.data?.message || 'The request failed.';
    }

    async function load() {
        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.customerSubscriptionInvoices);
            invoices.value = response.data?.data?.data ?? response.data?.data ?? [];
        } catch {
            Notify('Unable to load subscription invoices.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function generate() {
        generating.value = true;

        try {
            const response = await window.axios.post(API_ENDPOINTS.customerSubscriptionInvoiceGenerate);
            Notify(response.data?.message || 'Invoices generated', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            generating.value = false;
        }
    }

    function openMarkPaid(invoice: Invoice) {
        payingInvoice.value = invoice;
        payMethod.value = 'cash';
        payReference.value = '';
        payAmount.value = invoice.total_amount;
    }

    async function submitMarkPaid() {
        if (! payingInvoice.value) {
            return;
        }

        paying.value = true;

        try {
            await window.axios.post(API_ENDPOINTS.customerSubscriptionInvoiceMarkPaid(payingInvoice.value.id), {
                method: payMethod.value,
                reference: payReference.value || null,
                paid_amount: payAmount.value,
            });
            Notify('Invoice marked as paid', 'success');
            payingInvoice.value = null;
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            paying.value = false;
        }
    }

    function openRefund(invoice: Invoice) {
        refundingInvoice.value = invoice;
        refundAmount.value = invoice.total_amount;
    }

    async function submitRefund() {
        if (! refundingInvoice.value) {
            return;
        }

        refunding.value = true;

        try {
            await window.axios.post(API_ENDPOINTS.customerSubscriptionInvoiceRefund(refundingInvoice.value.id), {
                amount: refundAmount.value,
            });
            Notify('Invoice refunded', 'success');
            refundingInvoice.value = null;
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            refunding.value = false;
        }
    }

    async function cancelInvoice(invoice: Invoice) {
        if (! window.confirm(`Cancel invoice ${invoice.invoice_no}?`)) {
            return;
        }

        try {
            await window.axios.post(API_ENDPOINTS.customerSubscriptionInvoiceCancel(invoice.id));
            Notify('Invoice cancelled', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Subscription Invoices" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__body p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="alert alert-info small mb-0 flex-grow-1 me-3">
                        Manual-payment model: generating an invoice never auto-charges a customer. Mark one paid after you've received payment
                        out-of-band (bank transfer, cash, ...).
                    </div>
                    <button type="button" class="btn btn-primary btn-sm text-nowrap" :disabled="generating" @click="generate">
                        {{ generating ? 'Generating…' : 'Generate due invoices' }}
                    </button>
                </div>

                <Loader v-if="loading" message="Loading subscription invoices…" />

                <div v-else class="table-responsive">
                    <ClientTable  :columns="[{ key: 'c0', label: 'Invoice #' }, { key: 'c1', label: 'Customer' }, { key: 'c2', label: 'Period' }, { key: 'c3', label: 'Due' }, { key: 'c4', label: 'Total' }, { key: 'c5', label: 'Status' }, { key: 'c6', label: 'Action' }]" :rows="(invoices).map((invoice) => ({ __s: { invoice }, c0: (invoice.invoice_no), c1: (invoice.customer_name), c2: `${invoice.period_start} → ${invoice.period_end}`, c3: (invoice.due_date), c4: (invoice.total_amount), c5: '', c6: '' }))" export-name="invoice">
<template #cell-c5="{ row: __m }"><template v-for="{ invoice } in [__m.__s]" :key="0"><span :class="statusBadge(invoice.status)">{{ invoice.status }}</span></template></template>
<template #cell-c6="{ row: __m }"><template v-for="{ invoice } in [__m.__s]" :key="0">
                                    <button
                                        v-if="invoice.status === 'unpaid' || invoice.status === 'overdue'"
                                        type="button" class="btn btn-outline-success btn-sm me-1"
                                        @click="openMarkPaid(invoice)"
                                    >Mark paid</button>
                                    <button
                                        v-if="invoice.status === 'paid'"
                                        type="button" class="btn btn-outline-warning btn-sm me-1"
                                        @click="openRefund(invoice)"
                                    >Refund</button>
                                    <button
                                        v-if="invoice.status !== 'paid' && invoice.status !== 'cancelled' && invoice.status !== 'refunded'"
                                        type="button" class="btn btn-outline-danger btn-sm"
                                        @click="cancelInvoice(invoice)"
                                    >Cancel</button>
                                </template></template>
</ClientTable>
                </div>

                <div v-if="payingInvoice" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,0.4)">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Mark {{ payingInvoice.invoice_no }} as paid</h5>
                                <button type="button" class="btn-close" @click="payingInvoice = null"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Payment Method</label>
                                    <select v-model="payMethod" class="form-select form-select-sm">
                                        <option v-for="method in METHODS" :key="method" :value="method">{{ method }}</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Reference</label>
                                    <input v-model="payReference" type="text" class="form-control form-control-sm">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Amount Paid</label>
                                    <input v-model="payAmount" type="number" step="0.01" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" @click="payingInvoice = null">Cancel</button>
                                <button type="button" class="btn btn-success btn-sm" :disabled="paying" @click="submitMarkPaid">{{ paying ? 'Saving…' : 'Mark as paid' }}</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="refundingInvoice" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,0.4)">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Refund {{ refundingInvoice.invoice_no }}</h5>
                                <button type="button" class="btn-close" @click="refundingInvoice = null"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Refund Amount</label>
                                    <input v-model="refundAmount" type="number" step="0.01" class="form-control form-control-sm" :max="refundingInvoice.total_amount">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" @click="refundingInvoice = null">Cancel</button>
                                <button type="button" class="btn btn-warning btn-sm" :disabled="refunding" @click="submitRefund">{{ refunding ? 'Saving…' : 'Refund' }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
