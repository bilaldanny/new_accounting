<script setup lang="ts">
    import { computed, onMounted, ref } from 'vue';

    /**
     * The customer side of the portal: invoices (with a print / PDF download of each), the statement for a period,
     * and quotation / order requests that staff then approve. Payment is not taken here (see PortalPaymentInfo).
     */
    type Invoice = { id: number; invoice_no: string | null; date: string | null; status: string; payment_status: string; amount: number; paid: number; due: number };
    type InvoiceLine = { name: string | null; sku: string | null; unit: string | null; quantity: number; unit_price: number; discount_percent: number; subtotal: number };
    type InvoiceDetail = Invoice & {
        company: { name: string | null; address: string | null; phone: string | null; email: string | null };
        customer: { name: string; address: string | null; mobile: string | null };
        lines: InvoiceLine[];
        total_before_tax: number;
        tax_amount: number;
        discount_amount: number;
        shipping_charges: number;
        payments: { date: string | null; amount: number; method: string; reference: string | null }[];
    };
    type StatementRow = { date: string | null; reference: string | null; description: string; debit: number; credit: number; balance: number };
    type Statement = { contact: { name: string; code: string | null }; from: string; to: string; opening_balance: number; closing_balance: number; rows: StatementRow[] };
    type Product = { id: number; name: string; sku: string | null; price: number };
    type RequestRow = { id: number; reference: string; kind: string; status: string; note: string | null; lines: { name: string; quantity: number; unit_price: number }[]; review_note: string | null; created_at: string | null };

    const tab = ref<'invoices' | 'statement' | 'requests'>('invoices');
    const money = (value: number): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const message = ref('');
    const problem = ref('');

    function explain(error: unknown, fallback: string): string {
        const data = window.axios.isAxiosError(error) ? error.response?.data : null;
        const first = data?.errors ? (Object.values(data.errors as Record<string, string[]>)[0]?.[0] ?? null) : null;

        return first || data?.message || fallback;
    }

    // --- invoices
    const invoices = ref<Invoice[]>([]);
    const page = ref(1);
    const lastPage = ref(1);
    const selected = ref<InvoiceDetail | null>(null);

    async function loadInvoices() {
        try {
            const response = await window.axios.get('/api/portal/invoices', { params: { page: page.value } });
            invoices.value = response.data.data;
            lastPage.value = response.data.meta.last_page;
        } catch (error: unknown) {
            problem.value = explain(error, 'Invoices could not be loaded.');
        }
    }

    async function openInvoice(id: number) {
        try {
            selected.value = (await window.axios.get(`/api/portal/invoices/${id}`)).data;
        } catch (error: unknown) {
            problem.value = explain(error, 'The invoice could not be opened.');
        }
    }

    async function downloadInvoicePdf(invoice: InvoiceDetail) {
        const [{ default: jsPDF }, { default: autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')]);
        const doc = new jsPDF({ unit: 'mm', format: 'a4' });
        doc.setFontSize(14);
        doc.text(invoice.company.name ?? 'Invoice', 14, 16);
        doc.setFontSize(9);
        doc.text([invoice.company.address, invoice.company.phone, invoice.company.email].filter(Boolean).join('  |  '), 14, 22);
        doc.setFontSize(12);
        doc.text(`Invoice ${invoice.invoice_no ?? invoice.id}`, 14, 32);
        doc.setFontSize(9);
        doc.text(`Date: ${invoice.date ?? ''}`, 14, 38);
        doc.text(`Customer: ${invoice.customer.name}${invoice.customer.address ? `, ${invoice.customer.address}` : ''}`, 14, 43);
        autoTable(doc, {
            startY: 49,
            head: [['Item', 'Qty', 'Unit price', 'Disc %', 'Amount']],
            body: invoice.lines.map((line) => [line.name ?? '', `${line.quantity}${line.unit ? ` ${line.unit}` : ''}`, money(line.unit_price), String(line.discount_percent), money(line.subtotal)]),
            styles: { fontSize: 9 },
            headStyles: { fillColor: [52, 73, 94] },
        });
        const finalY = (doc as unknown as { lastAutoTable?: { finalY: number } }).lastAutoTable?.finalY ?? 80;
        const totals: [string, number][] = [
            ['Subtotal', invoice.total_before_tax],
            ['Discount', invoice.discount_amount],
            ['Tax', invoice.tax_amount],
            ['Shipping', invoice.shipping_charges],
            ['Total', invoice.amount],
            ['Paid', invoice.paid],
            ['Balance due', invoice.due],
        ];
        autoTable(doc, {
            startY: finalY + 6,
            body: totals.map(([label, value]) => [label, money(value)]),
            theme: 'plain',
            styles: { fontSize: 9, halign: 'right' },
            margin: { left: 120 },
        });
        doc.save(`invoice-${invoice.invoice_no ?? invoice.id}.pdf`);
    }

    function printInvoice() {
        window.print();
    }

    // --- statement
    const from = ref(new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10));
    const to = ref(new Date().toISOString().slice(0, 10));
    const statement = ref<Statement | null>(null);

    async function loadStatement() {
        problem.value = '';

        try {
            statement.value = (await window.axios.get('/api/portal/statement', { params: { from: from.value, to: to.value } })).data;
        } catch (error: unknown) {
            problem.value = explain(error, 'The statement could not be loaded.');
        }
    }

    async function downloadStatementPdf() {
        if (statement.value === null) {
            return;
        }

        const [{ default: jsPDF }, { default: autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')]);
        const doc = new jsPDF({ unit: 'mm', format: 'a4' });
        doc.setFontSize(13);
        doc.text(`Statement of account - ${statement.value.contact.name}`, 14, 14);
        doc.setFontSize(9);
        doc.text(`${statement.value.from} to ${statement.value.to}   Opening ${money(statement.value.opening_balance)}   Closing ${money(statement.value.closing_balance)}`, 14, 20);
        autoTable(doc, {
            startY: 25,
            head: [['Date', 'Reference', 'Description', 'Debit', 'Credit', 'Balance']],
            body: statement.value.rows.map((row) => [row.date ?? '', row.reference ?? '', row.description, money(row.debit), money(row.credit), money(row.balance)]),
            styles: { fontSize: 8 },
            headStyles: { fillColor: [52, 73, 94] },
        });
        doc.save(`statement-${statement.value.from}-${statement.value.to}.pdf`);
    }

    function downloadStatementCsv() {
        if (statement.value === null) {
            return;
        }

        const escape = (value: string | number | null): string => `"${String(value ?? '').replace(/"/g, '""')}"`;
        const lines = [['Date', 'Reference', 'Description', 'Debit', 'Credit', 'Balance'].map(escape).join(',')].concat(
            statement.value.rows.map((row) => [row.date, row.reference, row.description, row.debit, row.credit, row.balance].map(escape).join(',')),
        );
        const url = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `statement-${statement.value.from}-${statement.value.to}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    }

    // --- requests
    const requests = ref<RequestRow[]>([]);
    const search = ref('');
    const found = ref<Product[]>([]);
    const cart = ref<{ product: Product; quantity: number }[]>([]);
    const kind = ref<'quotation' | 'order'>('quotation');
    const note = ref('');
    const sending = ref(false);

    const cartTotal = computed(() => cart.value.reduce((sum, item) => sum + item.product.price * item.quantity, 0));

    async function loadRequests() {
        try {
            requests.value = (await window.axios.get('/api/portal/requests')).data.data;
        } catch (error: unknown) {
            problem.value = explain(error, 'Requests could not be loaded.');
        }
    }

    async function searchProducts() {
        try {
            found.value = (await window.axios.get('/api/portal/products', { params: { search: search.value } })).data.data;
        } catch (error: unknown) {
            problem.value = explain(error, 'Products could not be loaded.');
        }
    }

    function addToCart(product: Product) {
        const existing = cart.value.find((item) => item.product.id === product.id);

        if (existing) {
            existing.quantity += 1;
        } else {
            cart.value.push({ product, quantity: 1 });
        }
    }

    async function sendRequest() {
        problem.value = '';
        message.value = '';
        sending.value = true;

        try {
            const response = await window.axios.post('/api/portal/requests', {
                kind: kind.value,
                note: note.value,
                lines: cart.value.map((item) => ({ product_id: item.product.id, quantity: item.quantity })),
            });
            message.value = response.data.message;
            cart.value = [];
            note.value = '';
            await loadRequests();
        } catch (error: unknown) {
            problem.value = explain(error, 'The request could not be sent.');
        } finally {
            sending.value = false;
        }
    }

    async function cancelRequest(row: RequestRow) {
        try {
            await window.axios.post(`/api/portal/requests/${row.id}/cancel`);
            await loadRequests();
        } catch (error: unknown) {
            problem.value = explain(error, 'The request could not be cancelled.');
        }
    }

    onMounted(async () => {
        await Promise.all([loadInvoices(), loadRequests(), searchProducts()]);
    });
</script>

<template>
    <div class="mb-5" data-section="customer-tools">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'invoices' }" @click="tab = 'invoices'">Invoices</button></li>
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'statement' }" @click="tab = 'statement'; loadStatement()">Statement</button></li>
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'requests' }" @click="tab = 'requests'">Order / quotation request</button></li>
        </ul>

        <div v-if="problem" class="alert alert-warning py-2">{{ problem }}</div>
        <div v-if="message" class="alert alert-success py-2">{{ message }}</div>

        <div v-if="tab === 'invoices'">
            <div v-if="selected" class="border rounded p-3 mb-3" data-test="invoice-detail">
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-2 d-print-none">
                    <button type="button" class="btn btn-link btn-sm p-0" @click="selected = null">&larr; Back to invoices</button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-dark btn-sm" @click="printInvoice">Print</button>
                        <button type="button" class="btn btn-dark btn-sm" @click="downloadInvoicePdf(selected)">Download PDF</button>
                    </div>
                </div>
                <h5 class="mb-0">{{ selected.company.name }}</h5>
                <div class="text-muted small mb-2">{{ [selected.company.address, selected.company.phone, selected.company.email].filter(Boolean).join(' | ') }}</div>
                <div class="fw-semibold">Invoice {{ selected.invoice_no }} - {{ selected.date }}</div>
                <div class="small mb-3">{{ selected.customer.name }}<span v-if="selected.customer.address">, {{ selected.customer.address }}</span></div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Disc %</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <tr v-for="(line, index) in selected.lines" :key="index">
                                <td>{{ line.name }}</td>
                                <td class="text-end">{{ line.quantity }} {{ line.unit }}</td>
                                <td class="text-end">{{ money(line.unit_price) }}</td>
                                <td class="text-end">{{ line.discount_percent }}</td>
                                <td class="text-end">{{ money(line.subtotal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="text-end small">
                    <div>Subtotal {{ money(selected.total_before_tax) }}</div>
                    <div v-if="selected.discount_amount">Discount {{ money(selected.discount_amount) }}</div>
                    <div v-if="selected.tax_amount">Tax {{ money(selected.tax_amount) }}</div>
                    <div v-if="selected.shipping_charges">Shipping {{ money(selected.shipping_charges) }}</div>
                    <div class="fw-semibold">Total {{ money(selected.amount) }}</div>
                    <div>Paid {{ money(selected.paid) }}</div>
                    <div class="fw-semibold">Balance due {{ money(selected.due) }}</div>
                </div>
            </div>
            <template v-else>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Number</th><th>Date</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Still due</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="invoice in invoices" :key="invoice.id">
                                <td>{{ invoice.invoice_no }}</td>
                                <td>{{ invoice.date }}</td>
                                <td class="text-capitalize">{{ invoice.payment_status }}</td>
                                <td class="text-end">{{ money(invoice.amount) }}</td>
                                <td class="text-end">{{ money(invoice.due) }}</td>
                                <td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="openInvoice(invoice.id)">View / print</button></td>
                            </tr>
                            <tr v-if="! invoices.length"><td colspan="6" class="text-center text-muted">No invoices yet.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="lastPage > 1" class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="page <= 1" @click="page -= 1; loadInvoices()">Previous</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="page >= lastPage" @click="page += 1; loadInvoices()">Next</button>
                </div>
            </template>
        </div>

        <div v-else-if="tab === 'statement'">
            <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
                <div><label class="form-label small mb-0" for="statement-from">From</label><input id="statement-from" v-model="from" type="date" class="form-control form-control-sm" /></div>
                <div><label class="form-label small mb-0" for="statement-to">To</label><input id="statement-to" v-model="to" type="date" class="form-control form-control-sm" /></div>
                <button type="button" class="btn btn-dark btn-sm" @click="loadStatement">Show</button>
                <button v-if="statement" type="button" class="btn btn-outline-dark btn-sm" @click="downloadStatementPdf">Download PDF</button>
                <button v-if="statement" type="button" class="btn btn-outline-dark btn-sm" @click="downloadStatementCsv">Download CSV</button>
            </div>
            <div v-if="statement" class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Date</th><th>Reference</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                        <tr class="table-light"><td colspan="5">Opening balance</td><td class="text-end">{{ money(statement.opening_balance) }}</td></tr>
                        <tr v-for="(row, index) in statement.rows" :key="index">
                            <td>{{ row.date }}</td><td>{{ row.reference }}</td><td>{{ row.description }}</td>
                            <td class="text-end">{{ row.debit ? money(row.debit) : '' }}</td>
                            <td class="text-end">{{ row.credit ? money(row.credit) : '' }}</td>
                            <td class="text-end">{{ money(row.balance) }}</td>
                        </tr>
                        <tr class="table-light fw-semibold"><td colspan="5">Closing balance</td><td class="text-end">{{ money(statement.closing_balance) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-else>
            <div class="border rounded p-3 mb-4">
                <h6>New request</h6>
                <div class="d-flex flex-wrap gap-3 mb-2">
                    <label class="small"><input v-model="kind" type="radio" value="quotation" class="me-1" />Ask for a quotation</label>
                    <label class="small"><input v-model="kind" type="radio" value="order" class="me-1" />Place an order</label>
                </div>
                <div class="input-group input-group-sm mb-2">
                    <input v-model="search" type="text" class="form-control" placeholder="Search products by name or code" @keyup.enter="searchProducts" />
                    <button type="button" class="btn btn-outline-dark" @click="searchProducts">Search</button>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button v-for="product in found" :key="product.id" type="button" class="btn btn-outline-secondary btn-sm" @click="addToCart(product)">
                        {{ product.name }} - {{ money(product.price) }}
                    </button>
                </div>
                <table v-if="cart.length" class="table table-sm align-middle">
                    <thead><tr><th>Product</th><th style="width: 8rem">Quantity</th><th class="text-end">Price</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="item in cart" :key="item.product.id">
                            <td>{{ item.product.name }}</td>
                            <td><input v-model.number="item.quantity" type="number" min="0.001" step="any" class="form-control form-control-sm" /></td>
                            <td class="text-end">{{ money(item.product.price * item.quantity) }}</td>
                            <td class="text-end"><button type="button" class="btn btn-link btn-sm text-danger" @click="cart = cart.filter((entry) => entry !== item)">Remove</button></td>
                        </tr>
                        <tr><td colspan="2" class="text-end fw-semibold">Estimated total</td><td class="text-end fw-semibold">{{ money(cartTotal) }}</td><td></td></tr>
                    </tbody>
                </table>
                <textarea v-model="note" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="Note (optional)"></textarea>
                <button type="button" class="btn btn-dark btn-sm" :disabled="sending || ! cart.length" @click="sendRequest">{{ sending ? 'Sending…' : 'Send request' }}</button>
                <div class="text-muted small mt-1">Prices are estimates; we will confirm them. Nothing is charged by sending a request.</div>
            </div>

            <h6>My requests</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Reference</th><th>Type</th><th>Items</th><th>Status</th><th>Reply</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="row in requests" :key="row.id">
                            <td>{{ row.reference }}</td>
                            <td class="text-capitalize">{{ row.kind }}</td>
                            <td>{{ row.lines.map((line) => `${line.name} x ${line.quantity}`).join(', ') }}</td>
                            <td class="text-capitalize">{{ row.status }}</td>
                            <td>{{ row.review_note || '' }}</td>
                            <td class="text-end"><button v-if="row.status === 'pending'" type="button" class="btn btn-outline-danger btn-sm" @click="cancelRequest(row)">Cancel</button></td>
                        </tr>
                        <tr v-if="! requests.length"><td colspan="6" class="text-center text-muted">No requests yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
