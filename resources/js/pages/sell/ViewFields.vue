<script setup lang="ts">
import type { SellLineRow } from '@/composables/sell';

type SellView = {
    invoice_no?: string;
    transaction_date_label?: string;
    transaction_date?: string;
    branch_name?: string;
    status?: string;
    status_label?: string;
    payment_status?: string;
    payment_status_label?: string;
    shipping_status?: string;
    business_name?: string;
    customer_name?: string;
    address?: string;
    mobile?: string;
    company_name?: string;
    company_address?: string;
    formatted_amount?: string;
    final_amount?: number | string;
    pay_term?: number | string;
    pay_type?: string;
    shipping_details?: string;
    shipping_address?: string;
    delivered_to?: string;
    billty_no?: string;
    billty_date?: string;
    packing?: string;
    additional_note?: string;
    credit_limit?: number | string;
    selllines?: SellLineRow[];
};

defineProps<{
    note: SellView;
}>();

function qty(value: number | string | null | undefined): string {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });
}

function money(value: number | string | null | undefined): string {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function statusClass(status?: string): string {
    const value = String(status ?? '').toLowerCase();

    if (value === 'approved' || value === 'final' || value === 'paid' || value === 'delivered') {
        return 'is-success';
    }

    if (value === 'pending' || value === 'due' || value === 'draft' || value === 'quotation') {
        return 'is-warning';
    }

    if (value === 'partial' || value === 'ordered' || value === 'packed' || value === 'shipped') {
        return 'is-info';
    }

    return 'is-muted';
}

function headline(value?: string): string {
    const text = String(value ?? '').replace(/[_-]+/g, ' ').trim();

    if (! text) {
        return '—';
    }

    return text.replace(/\b\w/g, (char) => char.toUpperCase());
}
</script>

<template>
    <article class="purchase-approval-doc" id="sell-print">
        <header class="purchase-approval-doc__header">
            <div>
                <p class="purchase-approval-doc__eyebrow">Sell invoice</p>
                <h2 class="purchase-approval-doc__title">
                    {{ note.invoice_no ? `#${note.invoice_no}` : 'Sell invoice' }}
                </h2>
                <p class="purchase-approval-doc__meta">
                    {{ note.transaction_date_label || note.transaction_date || '-' }}
                    <span v-if="note.branch_name"> · {{ note.branch_name }}</span>
                </p>
            </div>
            <div class="purchase-approval-doc__badges">
                <span class="purchase-approval-doc__badge" :class="statusClass(note.status)">
                    {{ note.status_label || headline(note.status) }}
                </span>
                <span class="purchase-approval-doc__badge" :class="statusClass(note.payment_status)">
                    {{ note.payment_status_label || headline(note.payment_status) }}
                </span>
            </div>
        </header>

        <section class="purchase-approval-doc__parties">
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Customer</p>
                <strong>{{ note.customer_name || note.business_name || '—' }}</strong>
                <address>
                    {{ note.address || 'No address on file' }}
                    <br v-if="note.mobile">
                    <span v-if="note.mobile">Mobile: {{ note.mobile }}</span>
                </address>
            </div>
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Company</p>
                <strong>{{ note.company_name || '—' }}</strong>
                <address>{{ note.company_address || 'No address on file' }}</address>
            </div>
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Document</p>
                <dl class="purchase-approval-doc__facts">
                    <div>
                        <dt>Invoice no</dt>
                        <dd>{{ note.invoice_no || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Date</dt>
                        <dd>{{ note.transaction_date_label || note.transaction_date || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Pay term</dt>
                        <dd>{{ note.pay_term ? `${note.pay_term} ${note.pay_type || ''}`.trim() : '—' }}</dd>
                    </div>
                    <div>
                        <dt>Invoice total</dt>
                        <dd>{{ note.formatted_amount || money(note.final_amount) }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="purchase-approval-doc__lines">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Packing</th>
                        <th>Rate</th>
                        <th>Discount</th>
                        <th>Net</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in (note.selllines || [])" :key="line.id ?? index">
                        <td>{{ index + 1 }}</td>
                        <td>
                            <strong>{{ line.product_name }}</strong>
                            <small v-if="line.sku">{{ line.sku }}</small>
                        </td>
                        <td>{{ qty(line.quantity) }} {{ line.unit_name }}</td>
                        <td>{{ qty(line.packing_qty) }}</td>
                        <td>{{ money(line.unit_price) }}</td>
                        <td>{{ money(line.discount_percent) }}</td>
                        <td>{{ money(line.unit_price_after_discount) }}</td>
                        <td>{{ money(line.row_subtotal) }}</td>
                    </tr>
                    <tr v-if="!note.selllines?.length">
                        <td colspan="8">No line items</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="purchase-approval-doc__parties" v-if="note.shipping_details || note.shipping_address || note.delivered_to || note.billty_no || note.packing || note.additional_note">
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Delivery</p>
                <dl class="purchase-approval-doc__facts">
                    <div v-if="note.shipping_status">
                        <dt>Status</dt>
                        <dd>{{ headline(note.shipping_status) }}</dd>
                    </div>
                    <div v-if="note.delivered_to">
                        <dt>Delivered to</dt>
                        <dd>{{ note.delivered_to }}</dd>
                    </div>
                    <div v-if="note.shipping_details">
                        <dt>Shipping</dt>
                        <dd>{{ note.shipping_details }}</dd>
                    </div>
                    <div v-if="note.shipping_address">
                        <dt>Address</dt>
                        <dd>{{ note.shipping_address }}</dd>
                    </div>
                    <div v-if="note.billty_no">
                        <dt>Bilty no</dt>
                        <dd>{{ note.billty_no }}</dd>
                    </div>
                    <div v-if="note.packing">
                        <dt>Packing</dt>
                        <dd>{{ note.packing }}</dd>
                    </div>
                </dl>
            </div>
            <div v-if="note.additional_note" class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Notes</p>
                <p class="mb-0">{{ note.additional_note }}</p>
            </div>
        </section>
    </article>
</template>
