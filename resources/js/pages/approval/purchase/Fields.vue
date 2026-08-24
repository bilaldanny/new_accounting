<script setup lang="ts">
import type { PurchaseApprovalView } from '@/composables/purchaseApproval';

defineProps<{
    purchase: PurchaseApprovalView;
}>();

function money(value: number | string | null | undefined): string {
    const amount = Number(value || 0);

    return amount.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function statusClass(status?: string): string {
    const value = String(status ?? '').toLowerCase();

    if (value === 'approved' || value === 'received' || value === 'paid') {
        return 'is-success';
    }

    if (value === 'pending' || value === 'due') {
        return 'is-warning';
    }

    if (value === 'partial' || value === 'ordered') {
        return 'is-info';
    }

    return 'is-muted';
}
</script>

<template>
    <article class="purchase-approval-doc" id="purchase-approval-print">
        <header class="purchase-approval-doc__header">
            <div>
                <p class="purchase-approval-doc__eyebrow">Purchase order</p>
                <h2 class="purchase-approval-doc__title">
                    {{ purchase.invoice_no ? `#${purchase.invoice_no}` : 'Purchase review' }}
                </h2>
                <p class="purchase-approval-doc__meta">
                    {{ purchase.transaction_date_label || '-' }}
                    <span v-if="purchase.branch_name"> · {{ purchase.branch_name }}</span>
                </p>
            </div>
            <div class="purchase-approval-doc__badges">
                <span class="purchase-approval-doc__badge" :class="statusClass(purchase.status)">
                    {{ purchase.status_label || '—' }}
                </span>
                <span class="purchase-approval-doc__badge" :class="statusClass(purchase.payment_status)">
                    {{ purchase.payment_status_label || '—' }}
                </span>
            </div>
        </header>

        <section class="purchase-approval-doc__parties">
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Supplier</p>
                <strong>{{ purchase.business_name || '—' }}</strong>
                <address>
                    {{ purchase.address || 'No address on file' }}
                    <br v-if="purchase.mobile">
                    <span v-if="purchase.mobile">Mobile: {{ purchase.mobile }}</span>
                </address>
            </div>
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Company</p>
                <strong>{{ purchase.company_name || '—' }}</strong>
                <address>{{ purchase.company_address || 'No address on file' }}</address>
            </div>
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Document</p>
                <dl class="purchase-approval-doc__facts">
                    <div>
                        <dt>Reference no</dt>
                        <dd>{{ purchase.invoice_no || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Date</dt>
                        <dd>{{ purchase.transaction_date_label || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Supplier invoice</dt>
                        <dd>{{ purchase.sup_ref_no || '—' }}</dd>
                    </div>
                    <div v-if="purchase.approved_by_name">
                        <dt>Approved by</dt>
                        <dd>{{ purchase.approved_by_name }}<span v-if="purchase.approved_date"> · {{ purchase.approved_date }}</span></dd>
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
                            <th class="is-num">Disc %</th>
                            <th class="is-num">Disc/rate</th>
                            <th class="is-num">Net amt.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-if="purchase.line_groups?.length">
                            <template v-for="group in purchase.line_groups" :key="group.itemtype_name">
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
                                    <td class="is-num">{{ line.quantity }} {{ line.unit_name || '' }}</td>
                                    <td class="is-num">{{ line.packing_qty }}</td>
                                    <td class="is-num">{{ money(line.unit_rate) }}</td>
                                    <td class="is-num">{{ money(line.line_amount) }}</td>
                                    <td class="is-num">{{ money(line.discount_percent) }}</td>
                                    <td class="is-num">{{ money(line.discount_rate) }}</td>
                                    <td class="is-num">{{ money(line.net_amount) }}</td>
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
                            <tr v-if="!purchase.payments?.length">
                                <td colspan="6" class="is-empty">No payments recorded</td>
                            </tr>
                            <tr v-for="(payment, index) in purchase.payments" :key="`${payment.payment_ref_no}-${index}`">
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
                    <h3 class="purchase-settlement__heading">Purchase total</h3>
                </div>
                <div class="purchase-summary__rows">
                    <div class="purchase-summary__row">
                        <span>Net total amount</span>
                        <strong>{{ money(purchase.net_sub_total) }}</strong>
                    </div>
                    <div class="purchase-summary__row">
                        <span>Discount (−)</span>
                        <strong>{{ money(purchase.discount_val) }}</strong>
                    </div>
                    <div class="purchase-summary__row">
                        <span>Purchase tax (+)</span>
                        <strong class="is-muted">{{ money(purchase.tax_amount) }}</strong>
                    </div>
                    <div class="purchase-summary__row">
                        <span>Shipping charges (+)</span>
                        <strong>{{ money(purchase.shipping_charges) }}</strong>
                    </div>
                </div>
                <div class="purchase-summary__due">
                    <span>Purchase total</span>
                    <strong>{{ money(purchase.final_amount) }}</strong>
                </div>
            </div>
        </section>

        <section class="purchase-approval-doc__notes">
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Shipping details</p>
                <p>{{ purchase.shipping_details || '—' }}</p>
            </div>
            <div class="purchase-approval-doc__card">
                <p class="purchase-approval-doc__card-label">Additional notes</p>
                <p>{{ purchase.additional_note || '—' }}</p>
            </div>
        </section>
    </article>
</template>
