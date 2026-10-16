<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Subscription Invoices',
            subtitle: 'Tenant billing invoices — generate, mark paid, cancel',
            breadcrumbs: [
                { title: 'Subscription Invoices', href: 'NULL' },
            ],
        },
    });

    const METHODS = ['cash', 'card', 'cheque', 'bank_transfer', 'other'];

    type Invoice = {
        id: number;
        invoice_no: string;
        company_name: string | null;
        plan_name: string | null;
        period_start: string;
        period_end: string;
        due_date: string;
        total_amount: number;
        status: string;
    };

    const page = usePage();
    const { Notify } = useCommons();

    const loading = ref(true);
    const generating = ref(false);
    const invoices = ref<Invoice[]>([]);
    const payingInvoice = ref<Invoice | null>(null);
    const payMethod = ref('bank_transfer');
    const payReference = ref('');
    const payAmount = ref<number | ''>('');
    const paying = ref(false);

    const user = computed(() => page.props.auth?.user as { rolename?: string } | null);
    const isSuperadmin = computed(() => String(user.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');

    function statusBadge(status: string): string {
        return {
            unpaid: 'badge bg-secondary-subtle text-secondary',
            paid: 'badge bg-success-subtle text-success',
            overdue: 'badge bg-danger-subtle text-danger',
            cancelled: 'badge bg-dark-subtle text-dark',
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
            const response = await window.axios.get(API_ENDPOINTS.subscriptionInvoices);
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
            const response = await window.axios.post(API_ENDPOINTS.subscriptionInvoiceGenerate);
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
        payMethod.value = 'bank_transfer';
        payReference.value = '';
        payAmount.value = invoice.total_amount;
    }

    async function submitMarkPaid() {
        if (! payingInvoice.value) {
            return;
        }

        paying.value = true;

        try {
            await window.axios.post(API_ENDPOINTS.subscriptionInvoiceMarkPaid(payingInvoice.value.id), {
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

    async function cancelInvoice(invoice: Invoice) {
        if (! window.confirm(`Cancel invoice ${invoice.invoice_no}?`)) {
            return;
        }

        try {
            await window.axios.post(API_ENDPOINTS.subscriptionInvoiceCancel(invoice.id));
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
                        Payment here is manual-only: generating an invoice never auto-charges anything. Mark one paid after you've received a
                        bank transfer, cash, or other out-of-band payment.
                    </div>
                    <button v-if="isSuperadmin" type="button" class="btn btn-primary btn-sm text-nowrap" :disabled="generating" @click="generate">
                        {{ generating ? 'Generating…' : 'Generate due invoices' }}
                    </button>
                </div>

                <Loader v-if="loading" message="Loading subscription invoices…" />

                <div v-else class="table-responsive">
                    <ClientTable  :columns="[{ key: 'c0', label: 'Invoice #' }, { key: 'c1', label: 'Company' }, { key: 'c2', label: 'Plan' }, { key: 'c3', label: 'Period' }, { key: 'c4', label: 'Due' }, { key: 'c5', label: 'Total' }, { key: 'c6', label: 'Status' }, { key: 'c7', label: 'Action' }]" :rows="(invoices).map((invoice) => ({ __s: { invoice }, c0: (invoice.invoice_no), c1: (invoice.company_name), c2: (invoice.plan_name), c3: `${invoice.period_start} → ${invoice.period_end}`, c4: (invoice.due_date), c5: (invoice.total_amount), c6: '', c7: '' }))" export-name="invoice">
<template #cell-c6="{ row: __m }"><template v-for="{ invoice } in [__m.__s]" :key="0"><span :class="statusBadge(invoice.status)">{{ invoice.status }}</span></template></template>
<template #cell-c7="{ row: __m }"><template v-for="{ invoice } in [__m.__s]" :key="0">
                                    <button
                                        v-if="invoice.status === 'unpaid' || invoice.status === 'overdue'"
                                        type="button"
                                        class="btn btn-outline-success btn-sm me-1"
                                        @click="openMarkPaid(invoice)"
                                    >
                                        Mark paid
                                    </button>
                                    <button
                                        v-if="invoice.status !== 'paid' && invoice.status !== 'cancelled'"
                                        type="button"
                                        class="btn btn-outline-danger btn-sm"
                                        @click="cancelInvoice(invoice)"
                                    >
                                        Cancel
                                    </button>
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
                                    <input v-model="payReference" type="text" class="form-control form-control-sm" placeholder="Bank transfer ref, cheque no., ...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Amount Paid</label>
                                    <input v-model="payAmount" type="number" step="0.01" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" @click="payingInvoice = null">Cancel</button>
                                <button type="button" class="btn btn-success btn-sm" :disabled="paying" @click="submitMarkPaid">
                                    {{ paying ? 'Saving…' : 'Mark as paid' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
