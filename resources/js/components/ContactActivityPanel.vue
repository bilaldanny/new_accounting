<script setup lang="ts">
    import { Link } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { formatNumber } from '@/utils/numberFormat';

    /**
     * One tab of a customer or supplier page that is not the ledger: their purchases or sales (`orders`), the payments, or the products
     * bought from / sold to them (`stock`). It reads one endpoint (ContactActivityController) and shows the rows in the page's own look.
     */
    const props = defineProps<{
        mode: 'orders' | 'payments' | 'stock';
        kind: 'customer' | 'supplier';
        contactId: number | string;
        currency?: string;
    }>();

    type Row = Record<string, any>;

    const rows = ref<Row[]>([]);
    const summary = ref<Record<string, number>>({});
    const loading = ref(true);
    const failed = ref('');
    const limit = ref(200);
    const filter = ref('');

    const noun = computed(() => (props.kind === 'customer' ? 'sales' : 'purchases'));
    const title = computed(() => ({
        orders: props.kind === 'customer' ? 'Sales' : 'Purchases',
        payments: props.kind === 'customer' ? 'Payments received' : 'Payments made',
        stock: props.kind === 'customer' ? 'Products sold to this customer' : 'Products bought from this supplier',
    })[props.mode]);
    const subtitle = computed(() => ({
        orders: `Every ${props.kind === 'customer' ? 'invoice and return' : 'purchase and return'} with what was paid and what is still due`,
        payments: 'Cash, bank and cheque payments recorded against this account, newest first',
        stock: 'Quantity, value and the last rate, with what is on hand now',
    })[props.mode]);

    const money = (value: unknown) => formatNumber(Number(value ?? 0));
    const visible = computed(() => {
        const term = filter.value.trim().toLowerCase();

        return term === '' ? rows.value : rows.value.filter((row) => JSON.stringify(row).toLowerCase().includes(term));
    });

    async function load() {
        loading.value = true;
        failed.value = '';

        try {
            const path = { orders: 'orders', payments: 'payments', stock: 'stock' }[props.mode];
            const response = await window.axios.get(`/api/contacts/${props.contactId}/${path}`);
            rows.value = response.data.data ?? [];
            summary.value = response.data.summary ?? {};
            limit.value = response.data.limit ?? 200;
        } catch (error: any) {
            rows.value = [];
            failed.value = error?.response?.status === 403 ? 'You do not have permission to see this.' : 'This could not be loaded. Please try again.';
        } finally {
            loading.value = false;
        }
    }

    function statusTone(row: Row): string {
        if (row.is_return) {
            return 'info';
        }

        return { paid: 'success', partial: 'warning', due: 'danger' }[row.payment_status as string] ?? 'muted';
    }

    watch(() => [props.contactId, props.mode], load, { immediate: true });
</script>

<template>
    <section class="cap" :data-test="`contact-${mode}`">
        <header class="cap__head">
            <div>
                <h4 class="cap__title">{{ title }}</h4>
                <p class="cap__subtitle">{{ subtitle }}</p>
            </div>
            <input v-model="filter" type="search" class="cap__search" placeholder="Filter these rows" aria-label="Filter rows">
        </header>

        <div v-if="! loading && ! failed" class="cap__chips">
            <template v-if="mode === 'orders'">
                <span class="cap__chip"><small>Documents</small><strong>{{ summary.count ?? 0 }}</strong></span>
                <span class="cap__chip"><small>Total {{ noun }}</small><strong>{{ currency }} {{ money(summary.total) }}</strong></span>
                <span class="cap__chip cap__chip--success"><small>Paid</small><strong>{{ currency }} {{ money(summary.paid) }}</strong></span>
                <span class="cap__chip cap__chip--danger"><small>Still due</small><strong>{{ currency }} {{ money(summary.due) }}</strong></span>
            </template>
            <template v-else-if="mode === 'payments'">
                <span class="cap__chip"><small>Payments</small><strong>{{ summary.count ?? 0 }}</strong></span>
                <span class="cap__chip cap__chip--success"><small>Net total</small><strong>{{ currency }} {{ money(summary.total) }}</strong></span>
            </template>
            <template v-else>
                <span class="cap__chip"><small>Products</small><strong>{{ summary.products ?? 0 }}</strong></span>
                <span class="cap__chip"><small>Value</small><strong>{{ currency }} {{ money(summary.amount) }}</strong></span>
            </template>
        </div>

        <Loader v-if="loading" message="Loading..." :fields="4" />
        <div v-else-if="failed" class="cap__empty cap__empty--error" role="alert">{{ failed }}</div>
        <div v-else-if="visible.length === 0" class="cap__empty">
            <strong>{{ rows.length === 0 ? 'Nothing here yet' : 'No row matches the filter' }}</strong>
            <span v-if="rows.length === 0">{{ mode === 'orders' ? `No ${noun} have been recorded for this ${kind}.` : mode === 'payments' ? 'No payment has been recorded for this account.' : 'No product has been traded with this party yet.' }}</span>
        </div>

        <div v-else class="cap__table-wrap">
            <table class="cap__table">
                <thead v-if="mode === 'orders'">
                    <tr><th>Date</th><th>Document</th><th>Status</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Due</th><th /></tr>
                </thead>
                <thead v-else-if="mode === 'payments'">
                    <tr><th>Date</th><th>Against</th><th>Method</th><th>Account</th><th>Note</th><th class="num">Amount</th></tr>
                </thead>
                <thead v-else>
                    <tr><th>Product</th><th class="num">Quantity</th><th class="num">Returned</th><th class="num">Value</th><th class="num">Last rate</th><th>Last on</th><th class="num">On hand</th></tr>
                </thead>

                <tbody v-if="mode === 'orders'">
                    <tr v-for="row in visible" :key="row.id">
                        <td>{{ row.date }}</td>
                        <td><strong>{{ row.invoice_no }}</strong> <span v-if="row.is_return" class="cap__tag">Return</span> <span v-if="row.has_attachment" class="cap__tag cap__tag--file" title="Has an attached file">File</span></td>
                        <td><span class="cap__status" :class="`cap__status--${statusTone(row)}`">{{ row.is_return ? 'return' : row.payment_status }}</span></td>
                        <td class="num">{{ money(row.total) }}</td>
                        <td class="num">{{ row.is_return ? '-' : money(row.paid) }}</td>
                        <td class="num" :class="{ 'cap__due': row.due > 0 }">{{ row.is_return ? '-' : money(row.due) }}</td>
                        <td class="num"><Link v-if="row.view_url" :href="row.view_url" class="cap__link">Open</Link></td>
                    </tr>
                </tbody>
                <tbody v-else-if="mode === 'payments'">
                    <tr v-for="row in visible" :key="row.id">
                        <td>{{ row.date }}</td>
                        <td>{{ row.invoice_no || 'On account' }} <span v-if="row.is_return" class="cap__tag">Refund</span></td>
                        <td class="text-capitalize">{{ String(row.method).replace('_', ' ') }}</td>
                        <td>{{ row.account || '-' }}</td>
                        <td class="cap__muted">{{ row.note || '' }}</td>
                        <td class="num"><strong :class="row.is_return ? 'cap__due' : ''">{{ row.is_return ? '-' : '' }}{{ money(row.amount) }}</strong></td>
                    </tr>
                </tbody>
                <tbody v-else>
                    <tr v-for="row in visible" :key="`${row.product_id}-${row.name}`">
                        <td><strong>{{ row.name }}</strong> <span class="cap__muted">{{ row.sku }}</span></td>
                        <td class="num">{{ money(row.quantity) }}</td>
                        <td class="num">{{ Number(row.returned) > 0 ? money(row.returned) : '-' }}</td>
                        <td class="num">{{ money(row.amount) }}</td>
                        <td class="num">{{ money(row.last_rate) }}</td>
                        <td>{{ row.last_date }}</td>
                        <td class="num"><span class="cap__status" :class="Number(row.on_hand) > 0 ? 'cap__status--success' : 'cap__status--danger'">{{ money(row.on_hand) }}</span></td>
                    </tr>
                </tbody>
            </table>
            <p v-if="rows.length >= limit" class="cap__note">Showing the latest {{ limit }}. Use the ledger for the full history.</p>
        </div>
    </section>
</template>

<style scoped>
.cap {
    --cap-accent: #199683;
    --cap-accent-dark: #0f766e;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 0.5rem 1.25rem 1.25rem;
}

.cap__head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 0.75rem;
}

.cap__title {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
}

.cap__subtitle {
    margin: 0.15rem 0 0;
    font-size: 0.85rem;
    color: #64748b;
}

.cap__search {
    min-width: 14rem;
    padding: 0.5rem 0.85rem;
    border: 1px solid #dbe4ee;
    border-radius: 999px;
    background: #f8fafc;
    font-size: 0.85rem;
}

.cap__search:focus {
    outline: none;
    border-color: var(--cap-accent);
    box-shadow: 0 0 0 3px rgba(25, 150, 131, 0.15);
}

.cap__chips {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr));
    gap: 0.75rem;
}

.cap__chip {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.75rem 0.95rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.9rem;
    background: #f8fafc;
}

.cap__chip small {
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #94a3b8;
}

.cap__chip strong {
    font-size: 1.05rem;
    color: #0f172a;
    font-variant-numeric: tabular-nums;
}

.cap__chip--success {
    background: rgba(25, 150, 131, 0.08);
    border-color: rgba(25, 150, 131, 0.2);
}

.cap__chip--success strong {
    color: var(--cap-accent-dark);
}

.cap__chip--danger {
    background: rgba(220, 38, 38, 0.06);
    border-color: rgba(220, 38, 38, 0.16);
}

.cap__chip--danger strong {
    color: #b91c1c;
}

.cap__table-wrap {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 0.9rem;
    background: #fff;
}

.cap__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.cap__table th {
    position: sticky;
    top: 0;
    padding: 0.7rem 0.9rem;
    text-align: left;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}

.cap__table td {
    padding: 0.65rem 0.9rem;
    border-bottom: 1px solid #eef2f6;
    color: #1e293b;
    vertical-align: middle;
}

.cap__table tbody tr:hover {
    background: rgba(25, 150, 131, 0.045);
}

.cap__table tbody tr:last-child td {
    border-bottom: 0;
}

.cap__table .num {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.cap__muted {
    color: #94a3b8;
    font-size: 0.8rem;
}

.cap__due {
    color: #b91c1c;
    font-weight: 600;
}

.cap__tag {
    display: inline-block;
    padding: 0.05rem 0.45rem;
    border-radius: 999px;
    background: #e0f2fe;
    color: #0369a1;
    font-size: 0.7rem;
    font-weight: 700;
}

.cap__tag--file {
    background: rgba(25, 150, 131, 0.12);
    color: var(--cap-accent-dark);
}

.cap__status {
    display: inline-block;
    min-width: 3.5rem;
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    text-align: center;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: capitalize;
    background: #f1f5f9;
    color: #475569;
}

.cap__status--success {
    background: rgba(25, 150, 131, 0.12);
    color: var(--cap-accent-dark);
}

.cap__status--warning {
    background: #fef3c7;
    color: #92400e;
}

.cap__status--danger {
    background: #fee2e2;
    color: #b91c1c;
}

.cap__status--info {
    background: #e0f2fe;
    color: #0369a1;
}

.cap__link {
    color: var(--cap-accent-dark);
    font-weight: 600;
    text-decoration: none;
}

.cap__link:hover {
    text-decoration: underline;
}

.cap__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    padding: 2.25rem 1rem;
    border: 1px dashed #cbd5e1;
    border-radius: 0.9rem;
    color: #64748b;
    background: #f8fafc;
    text-align: center;
}

.cap__empty--error {
    color: #b91c1c;
    border-color: #fecaca;
    background: #fef2f2;
}

.cap__note {
    margin: 0;
    padding: 0.6rem 0.9rem;
    font-size: 0.8rem;
    color: #64748b;
    background: #f8fafc;
}
</style>
