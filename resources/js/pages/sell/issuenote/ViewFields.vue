<script setup lang="ts">
import type { IssueNoteLine } from '@/composables/issueNote';

type IssueNoteView = {
    invoice_no?: string;
    sell_order_no?: string;
    transaction_date_label?: string;
    transaction_date?: string;
    branch_name?: string;
    status?: string;
    status_label?: string;
    payment_status?: string;
    payment_status_label?: string;
    business_name?: string;
    customer_name?: string;
    address?: string;
    mobile?: string;
    company_name?: string;
    company_address?: string;
    formatted_amount?: string;
    selllines?: IssueNoteLine[];
};

defineProps<{
    note: IssueNoteView;
}>();

function qty(value: number | string | null | undefined): string {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });
}

function statusClass(status?: string): string {
    const value = String(status ?? '').toLowerCase();

    if (value === 'approved' || value === 'received' || value === 'paid' || value === 'issue') {
        return 'is-success';
    }

    if (value === 'pending' || value === 'due' || value === 'final' || value === 'draft') {
        return 'is-warning';
    }

    if (value === 'partial' || value === 'ordered') {
        return 'is-info';
    }

    return 'is-muted';
}
</script>

<template>
    <article class="purchase-approval-doc" id="issue-note-print">
        <header class="purchase-approval-doc__header">
            <div>
                <p class="purchase-approval-doc__eyebrow">Goods issue note</p>
                <h2 class="purchase-approval-doc__title">
                    {{ note.invoice_no ? `#${note.invoice_no}` : 'Issue note' }}
                </h2>
                <p class="purchase-approval-doc__meta">
                    {{ note.transaction_date_label || note.transaction_date || '-' }}
                    <span v-if="note.branch_name"> · {{ note.branch_name }}</span>
                    <span v-if="note.sell_order_no"> · INV {{ note.sell_order_no }}</span>
                </p>
            </div>
            <div class="purchase-approval-doc__badges">
                <span class="purchase-approval-doc__badge" :class="statusClass(note.status)">
                    {{ note.status_label || '—' }}
                </span>
                <span class="purchase-approval-doc__badge" :class="statusClass(note.payment_status)">
                    {{ note.payment_status_label || '—' }}
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
                        <dt>Reference no</dt>
                        <dd>{{ note.invoice_no || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Sell invoice</dt>
                        <dd>{{ note.sell_order_no || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Date</dt>
                        <dd>{{ note.transaction_date_label || note.transaction_date || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Invoice total</dt>
                        <dd>{{ note.formatted_amount || '—' }}</dd>
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
                            <th>Product name</th>
                            <th class="is-num">Sell quantity</th>
                            <th class="is-num">Issue quantity</th>
                            <th class="is-num">Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!note.selllines?.length">
                            <td colspan="5" class="is-empty">No line items found</td>
                        </tr>
                        <tr v-for="(line, index) in note.selllines" :key="line.id ?? index">
                            <td>{{ index + 1 }}</td>
                            <td>
                                <strong>{{ line.product_name || '—' }}</strong>
                                <span v-if="line.sku"> · {{ line.sku }}</span>
                            </td>
                            <td class="is-num">{{ qty(line.quantity) }} {{ line.unit_short_name || line.unit_name || '' }}</td>
                            <td class="is-num">{{ qty(line.quantity_issue) }}</td>
                            <td class="is-num">{{ qty(line.remaining_qty) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </article>
</template>
