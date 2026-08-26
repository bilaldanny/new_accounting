<script setup lang="ts">
import type { SellApprovalView } from '@/composables/sellApproval';

defineProps<{
    sell: SellApprovalView;
}>();

function money(value: number | string | null | undefined): string {
    const amount = Number(value || 0);

    return amount.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function qty(value: number | string | null | undefined): string {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });
}

function statusClass(status?: string): string {
    const value = String(status ?? '').toLowerCase();

    if (value === 'approved' || value === 'paid' || value === 'delivered') {
        return 'is-success';
    }

    if (value === 'final' || value === 'pending' || value === 'due' || value === 'draft' || value === 'quotation') {
        return 'is-warning';
    }

    if (value === 'partial' || value === 'ordered' || value === 'packed' || value === 'shipped') {
        return 'is-info';
    }

    return 'is-muted';
}

function headline(value?: string | null): string {
    const text = String(value ?? '').replace(/[_-]+/g, ' ').trim();

    if (! text) {
        return '—';
    }

    return text.replace(/\b\w/g, (char) => char.toUpperCase());
}

function payTerm(term?: number | string | null, type?: string | null): string {
    if (! term) {
        return '—';
    }

    return `${term} ${type || ''}`.trim();
}
</script>

<template>
    <article class="purchase-approval-doc" id="sell-approval-print">
        <header class="purchase-approval-doc__header">
            <div>
                <p class="purchase-approval-doc__eyebrow">Sell invoice</p>
                <h2 class="purchase-approval-doc__title">
                    {{ sell.invoice_no ? `#${sell.invoice_no}` : 'Sell review' }}
                </h2>
                <p class="purchase-approval-doc__meta">
                    {{ sell.transaction_date_label || '-' }}
                    <span v-if="sell.branch_name"> · {{ sell.branch_name }}</span>
                </p>
            </div>
            <div class="purchase-approval-doc__badges">
                <span class="purchase-approval-doc__badge" :class="statusClass(sell.status)">
                    {{ sell.status_label || '—' }}
                </span>
                <span class="purchase-approval-doc__badge" :class="statusClass(sell.payment_status)">
                    {{ sell.payment_status_label || '—' }}
                </span>
                <span
                    v-if="sell.shipping_status"
                    class="purchase-approval-doc__badge"
                    :class="statusClass(sell.shipping_status)"
                >
                    {{ sell.shipping_status_label || headline(sell.shipping_status) }}
                </span>
            </div>
        </header>

        <section class="purchase-approval-doc__parties">
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Customer</p>
                <strong>{{ sell.customer_name || sell.business_name || '—' }}</strong>
                <address>
                    {{ sell.address || 'No address on file' }}
                    <br v-if="sell.mobile">
                    <span v-if="sell.mobile">Mobile: {{ sell.mobile }}</span>
                </address>
            </div>
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Company</p>
                <strong>{{ sell.company_name || '—' }}</strong>
                <address>{{ sell.company_address || 'No address on file' }}</address>
            </div>
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Document</p>
                <dl class="purchase-approval-doc__facts">
                    <div>
                        <dt>Invoice no</dt>
                        <dd>{{ sell.invoice_no || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Date</dt>
                        <dd>{{ sell.transaction_date_label || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Pay term</dt>
                        <dd>{{ payTerm(sell.pay_term, sell.pay_type) }}</dd>
                    </div>
                    <div v-if="sell.approved_by_name">
                        <dt>Approved by</dt>
                        <dd>{{ sell.approved_by_name }}<span v-if="sell.approved_date"> · {{ sell.approved_date }}</span></dd>
                    </div>
                    <div v-else>
                        <dt>Invoice total</dt>
                        <dd>{{ sell.formatted_amount || money(sell.final_amount) }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="purchase-approval-doc__lines">
            <div class="table-responsive">
                <table class="purchase-approval-doc__table">
                    <thead>
                        <tr>
                            <th>S#</th>
                            <th>Item description</th>
                            <th class="is-num">Size</th>
                            <th class="is-num">Qty</th>
                            <th class="is-num">Packing</th>
                            <th class="is-num">Unit rate</th>
                            <th class="is-num">Amount</th>
                            <th class="is-num">Disc</th>
                            <th class="is-num">Net rate</th>
                            <th class="is-num">Net amt.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-if="sell.line_groups?.length">
                            <template v-for="group in sell.line_groups" :key="group.itemtype_name">
                                <tr class="purchase-approval-doc__group">
                                    <td colspan="10">{{ group.itemtype_name }}</td>
                                </tr>
                                <tr v-for="(line, index) in group.lines" :key="line.id ?? `${group.itemtype_name}-${index}`">
                                    <td>{{ index + 1 }}</td>
                                    <td>
                                        <strong>
                                            <span v-if="line.brand_name">{{ line.brand_name }} / </span>
                                            {{ line.product_name || '—' }}
                                        </strong>
                                    </td>
                                    <td class="is-num">{{ line.variation_name || '—' }}</td>
                                    <td class="is-num">{{ qty(line.display_quantity ?? line.quantity) }} {{ line.unit_name || '' }}</td>
                                    <td class="is-num">{{ qty(line.packing_qty) }}</td>
                                    <td class="is-num">{{ money(line.unit_rate ?? line.unit_price) }}</td>
                                    <td class="is-num">{{ money(line.line_amount) }}</td>
                                    <td class="is-num">{{ money(line.discount_percent) }}</td>
                                    <td class="is-num">{{ money(line.discount_rate ?? line.unit_price_after_discount) }}</td>
                                    <td class="is-num">{{ money(line.net_amount ?? line.row_subtotal) }}</td>
                                </tr>
                            </template>
                        </template>
                        <tr v-else>
                            <td colspan="10" class="is-empty">No line items found</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="purchase-approval-doc__settlement">
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Payment info</p>
                <div class="table-responsive">
                    <table class="purchase-approval-doc__table is-compact">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Reference no</th>
                                <th class="is-num">Amount</th>
                                <th>Payment mode</th>
                                <th>Payment note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!sell.payments?.length">
                                <td colspan="6" class="is-empty">No payments recorded</td>
                            </tr>
                            <tr v-for="(payment, index) in sell.payments" :key="`${payment.payment_ref_no}-${index}`">
                                <td>{{ index + 1 }}</td>
                                <td>{{ payment.paid_on || '—' }}</td>
                                <td>{{ payment.payment_ref_no || '—' }}</td>
                                <td class="is-num">{{ money(payment.amount) }}</td>
                                <td>{{ payment.method || '—' }}</td>
                                <td>{{ payment.note || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="purchase-summary">
                <div class="purchase-summary__head">
                    <p class="purchase-settlement__eyebrow">Settlement</p>
                    <h3 class="purchase-settlement__heading">Sell total</h3>
                </div>
                <div class="purchase-summary__rows">
                    <div class="purchase-summary__row">
                        <span>Bill total</span>
                        <strong>{{ money(sell.net_sub_total) }}</strong>
                    </div>
                    <div class="purchase-summary__row">
                        <span>Discount (−)</span>
                        <strong>{{ money(sell.discount_val) }}</strong>
                    </div>
                    <div class="purchase-summary__row">
                        <span>Freight (+)</span>
                        <strong>{{ money(sell.shipping_charges) }}</strong>
                    </div>
                </div>
                <div class="purchase-summary__due">
                    <span>Net amount</span>
                    <strong>{{ money(sell.final_amount) }}</strong>
                </div>
            </div>
        </section>

        <section class="purchase-approval-doc__notes">
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Shipping details</p>
                <p>{{ sell.shipping_details || '—' }}</p>
                <dl v-if="sell.shipping_address || sell.delivered_to" class="purchase-approval-doc__facts mt-3">
                    <div v-if="sell.delivered_to">
                        <dt>Delivered to</dt>
                        <dd>{{ sell.delivered_to }}</dd>
                    </div>
                    <div v-if="sell.shipping_address">
                        <dt>Address</dt>
                        <dd>{{ sell.shipping_address }}</dd>
                    </div>
                </dl>
            </div>
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Bilty & packing</p>
                <dl class="purchase-approval-doc__facts">
                    <div>
                        <dt>Bilty no</dt>
                        <dd>{{ sell.billty_no || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Bilty date</dt>
                        <dd>{{ sell.billty_date_label || sell.billty_date || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Packing</dt>
                        <dd>{{ sell.packing || '—' }}</dd>
                    </div>
                </dl>
            </div>
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Additional notes</p>
                <p>{{ sell.additional_note || '—' }}</p>
            </div>
        </section>
    </article>
</template>
