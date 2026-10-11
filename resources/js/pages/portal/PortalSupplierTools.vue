<script setup lang="ts">
    import { onMounted, ref } from 'vue';

    /**
     * The supplier side of the portal: the purchase orders placed with them and where each stands, and the
     * documents of the bills they issue, which staff check before entering. Nothing here changes the books.
     */
    type Order = { id: number; invoice_no: string | null; date: string | null; status: string; status_label: string; payment_status: string; amount: number; ordered_quantity?: number; received_quantity?: number };
    type OrderLine = { name: string | null; sku: string | null; unit: string | null; quantity: number; received: number; rate: number };
    type Bill = { id: number; reference: string; status: string; bill_no: string | null; bill_date: string | null; amount: number | null; purchase_id: number | null; note: string | null; review_note: string | null; file_name: string | null };

    const tab = ref<'orders' | 'bills'>('orders');
    const money = (value: number | null): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const problem = ref('');
    const message = ref('');

    function explain(error: unknown, fallback: string): string {
        const data = window.axios.isAxiosError(error) ? error.response?.data : null;
        const first = data?.errors ? (Object.values(data.errors as Record<string, string[]>)[0]?.[0] ?? null) : null;

        return first || data?.message || fallback;
    }

    const orders = ref<Order[]>([]);
    const openOrder = ref<(Order & { lines: OrderLine[] }) | null>(null);

    async function loadOrders() {
        try {
            orders.value = (await window.axios.get('/api/portal/purchase-orders')).data.data;
        } catch (error: unknown) {
            problem.value = explain(error, 'Purchase orders could not be loaded.');
        }
    }

    async function showOrder(id: number) {
        try {
            openOrder.value = (await window.axios.get(`/api/portal/purchase-orders/${id}`)).data;
        } catch (error: unknown) {
            problem.value = explain(error, 'The purchase order could not be opened.');
        }
    }

    const bills = ref<Bill[]>([]);
    const billNo = ref('');
    const billDate = ref(new Date().toISOString().slice(0, 10));
    const amount = ref<number | null>(null);
    const purchaseId = ref<number | ''>('');
    const note = ref('');
    const fileInput = ref<HTMLInputElement | null>(null);
    const sending = ref(false);

    async function loadBills() {
        try {
            bills.value = (await window.axios.get('/api/portal/bills')).data.data;
        } catch (error: unknown) {
            problem.value = explain(error, 'Bills could not be loaded.');
        }
    }

    async function sendBill() {
        const file = fileInput.value?.files?.[0];
        problem.value = '';
        message.value = '';

        if (! file) {
            problem.value = 'Choose the bill file (PDF, JPG or PNG).';

            return;
        }

        sending.value = true;

        try {
            const body = new FormData();
            body.append('file', file);
            body.append('bill_no', billNo.value);
            body.append('bill_date', billDate.value);
            body.append('amount', String(amount.value ?? ''));
            body.append('note', note.value);

            if (purchaseId.value !== '') {
                body.append('purchase_id', String(purchaseId.value));
            }

            const response = await window.axios.post('/api/portal/bills', body);
            message.value = response.data.message;
            billNo.value = '';
            amount.value = null;
            note.value = '';
            purchaseId.value = '';

            if (fileInput.value) {
                fileInput.value.value = '';
            }

            await loadBills();
        } catch (error: unknown) {
            problem.value = explain(error, 'The bill could not be sent.');
        } finally {
            sending.value = false;
        }
    }

    async function downloadBill(bill: Bill) {
        try {
            const response = await window.axios.get(`/api/portal/bills/${bill.id}/file`, { responseType: 'blob' });
            const url = URL.createObjectURL(response.data);
            const link = document.createElement('a');
            link.href = url;
            link.download = bill.file_name ?? `bill-${bill.id}`;
            link.click();
            URL.revokeObjectURL(url);
        } catch {
            problem.value = 'The file could not be downloaded.';
        }
    }

    onMounted(async () => {
        await Promise.all([loadOrders(), loadBills()]);
    });
</script>

<template>
    <div class="mb-5" data-section="supplier-tools">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'orders' }" @click="tab = 'orders'">Purchase orders</button></li>
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'bills' }" @click="tab = 'bills'">Send a bill</button></li>
        </ul>

        <div v-if="problem" class="alert alert-warning py-2">{{ problem }}</div>
        <div v-if="message" class="alert alert-success py-2">{{ message }}</div>

        <div v-if="tab === 'orders'">
            <div v-if="openOrder" class="border rounded p-3">
                <button type="button" class="btn btn-link btn-sm p-0 mb-2" @click="openOrder = null">&larr; Back to purchase orders</button>
                <div class="fw-semibold">{{ openOrder.invoice_no }} - {{ openOrder.date }} - {{ openOrder.status_label }}</div>
                <div class="table-responsive mt-2">
                    <table class="table table-sm">
                        <thead><tr><th>Item</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Rate</th></tr></thead>
                        <tbody>
                            <tr v-for="(line, index) in openOrder.lines" :key="index">
                                <td>{{ line.name }}</td>
                                <td class="text-end">{{ line.quantity }} {{ line.unit }}</td>
                                <td class="text-end">{{ line.received }}</td>
                                <td class="text-end">{{ money(line.rate) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Number</th><th>Date</th><th>Status</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Amount</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="order in orders" :key="order.id">
                            <td>{{ order.invoice_no }}</td>
                            <td>{{ order.date }}</td>
                            <td>{{ order.status_label }}</td>
                            <td class="text-end">{{ order.ordered_quantity }}</td>
                            <td class="text-end">{{ order.received_quantity }}</td>
                            <td class="text-end">{{ money(order.amount) }}</td>
                            <td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="showOrder(order.id)">View</button></td>
                        </tr>
                        <tr v-if="! orders.length"><td colspan="7" class="text-center text-muted">No purchase orders yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-else>
            <div class="border rounded p-3 mb-4">
                <h6>Send a bill for checking</h6>
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label small mb-0" for="bill-no">Your bill number</label><input id="bill-no" v-model="billNo" class="form-control form-control-sm" maxlength="100" /></div>
                    <div class="col-md-4"><label class="form-label small mb-0" for="bill-date">Bill date</label><input id="bill-date" v-model="billDate" type="date" class="form-control form-control-sm" /></div>
                    <div class="col-md-4"><label class="form-label small mb-0" for="bill-amount">Amount</label><input id="bill-amount" v-model.number="amount" type="number" min="0" step="0.01" class="form-control form-control-sm" /></div>
                    <div class="col-md-6">
                        <label class="form-label small mb-0" for="bill-po">Purchase order (optional)</label>
                        <select id="bill-po" v-model="purchaseId" class="form-select form-select-sm">
                            <option value="">None</option>
                            <option v-for="order in orders" :key="order.id" :value="order.id">{{ order.invoice_no }} - {{ order.date }}</option>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label small mb-0" for="bill-file">Bill file (PDF, JPG, PNG)</label><input id="bill-file" ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm" /></div>
                    <div class="col-12"><textarea v-model="note" class="form-control form-control-sm" rows="2" maxlength="1000" placeholder="Note (optional)"></textarea></div>
                </div>
                <button type="button" class="btn btn-dark btn-sm mt-2" :disabled="sending" @click="sendBill">{{ sending ? 'Sending…' : 'Send bill' }}</button>
                <div class="text-muted small mt-1">Staff will check the bill and enter it; sending it does not change what you are owed.</div>
            </div>

            <h6>Bills I sent</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Reference</th><th>Bill no.</th><th>Date</th><th class="text-end">Amount</th><th>Status</th><th>Reply</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="bill in bills" :key="bill.id">
                            <td>{{ bill.reference }}</td>
                            <td>{{ bill.bill_no }}</td>
                            <td>{{ bill.bill_date }}</td>
                            <td class="text-end">{{ money(bill.amount) }}</td>
                            <td class="text-capitalize">{{ bill.status }}</td>
                            <td>{{ bill.review_note || '' }}</td>
                            <td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="downloadBill(bill)">File</button></td>
                        </tr>
                        <tr v-if="! bills.length"><td colspan="7" class="text-center text-muted">No bills sent yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
