<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import useSells from '@/composables/sell';
    import { Head, Link } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';

    const props = defineProps({
        id: {
            type: [Number, String],
            required: true,
        },
    });

    defineOptions({
        layout: {
            title: 'Sales Invoice',
            subtitle: 'Printable customer invoice',
            breadcrumbs: [
                {
                    title: 'Sell Management',
                    href: '/sell',
                },
                {
                    title: 'Invoice',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify } = useCommons();
    const { getEditData, viewData } = useSells();
    const loading = ref(true);

    const invoice = computed(() => viewData.value as Record<string, any>);

    function money(value: unknown): string {
        return Number(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function lineQty(line: Record<string, any>): number {
        const issued = Number(line.quantity_issue || 0);

        return issued > 0 ? issued : Number(line.quantity || 0);
    }

    function lineWeight(line: Record<string, any>): number {
        return Number(line.packing_qty || 0) * lineQty(line) * Number(line.weight || 0);
    }

    function printInvoice() {
        window.print();
    }

    onMounted(async () => {
        loading.value = true;
        const loaded = await getEditData(Number(props.id));

        if (! loaded) {
            Notify('Invoice not found', 'alert');
        }

        loading.value = false;
    });
</script>

<template>
    <Head title="Sales Invoice" />

    <div class="sell-invoice-page">
        <div class="sell-invoice-page__toolbar no-print">
            <Link href="/sell" class="btn btn-light btn-sm">Back</Link>
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="printInvoice">Print</button>
        </div>

        <Loader v-if="loading" message="Loading invoice…" />

        <div v-else class="card">
            <div class="card-header d-flex justify-content-between align-items-center no-print">
                <h5 class="mb-0">Invoice No {{ invoice.invoice_no || '—' }}</h5>
            </div>
            <div class="card-body" id="printarea">
                <table class="table mb-3">
                    <tr>
                        <td style="vertical-align: top; width: 45%;">
                            <h2 class="mb-1" style="color: navy;">{{ invoice.company_business_name || invoice.company_name || '—' }}</h2>
                            <p class="mb-1">{{ invoice.company_setting_address || invoice.company_address || '—' }}</p>
                            <p class="mb-1"><strong>Tel no : </strong>{{ invoice.company_phone || '—' }}, <strong>Cell No : </strong>{{ invoice.company_cell || '—' }}</p>
                            <p class="mb-1">{{ invoice.company_fb_link || '—' }}</p>
                            <p class="mb-0"><strong>Email Us At : </strong>{{ invoice.company_email || '—' }}</p>
                        </td>
                        <td class="text-center" style="vertical-align: top;">
                            <h2 class="mb-0" style="color: navy; border: 1px solid navy; display: inline-block; padding: 0.4rem 1rem;">SALES INVOICE</h2>
                        </td>
                        <td class="text-end" style="vertical-align: top; width: 25%;">
                            <img v-if="invoice.company_logo_url" :src="invoice.company_logo_url" alt="Company logo" style="max-width: 50%;">
                        </td>
                    </tr>
                </table>

                <table class="w-100 mb-3">
                    <tr>
                        <td>
                            <table class="table table-bordered mb-0">
                                <tr><th>Invoice #</th><td>{{ invoice.invoice_no || '—' }}</td></tr>
                                <tr><th>Customer Name</th><td>{{ invoice.customer_full_name || invoice.customer_name || '—' }}</td></tr>
                                <tr><th>Phone No</th><td>{{ invoice.mobile || '—' }}</td></tr>
                                <tr><th>Address</th><td>{{ invoice.address || '—' }}</td></tr>
                            </table>
                        </td>
                        <td style="width: 8%;"></td>
                        <td>
                            <table class="table table-bordered mb-0">
                                <tr><th>Invoice Date</th><td>{{ invoice.transaction_date_label || invoice.transaction_date || '—' }}</td></tr>
                                <tr><th>Invoice By</th><td>{{ invoice.created_by_name || '—' }}</td></tr>
                                <tr><th>Cr. {{ invoice.pay_type || 'Day' }}</th><td>{{ invoice.pay_term || '0' }}</td></tr>
                                <tr><th>Previous Balance</th><td>{{ money(invoice.previous_balance) }}</td></tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>S#</th>
                                <th>Item</th>
                                <th>Unit</th>
                                <th class="text-end">QTY</th>
                                <th class="text-end">Total Wt</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in (invoice.selllines || [])" :key="line.id || index">
                                <td>{{ index + 1 }}</td>
                                <td>{{ line.product_name || '—' }}</td>
                                <td>{{ line.unit_name || '—' }}</td>
                                <td class="text-end">{{ money(lineQty(line)) }}</td>
                                <td class="text-end">{{ money(lineWeight(line)) }}</td>
                                <td class="text-end">{{ money(line.unit_price_after_discount) }}</td>
                                <td class="text-end">{{ money(line.row_subtotal ?? line.subtotal) }}</td>
                            </tr>
                            <tr v-if="! invoice.selllines?.length">
                                <td colspan="7" class="text-center">No items</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <table class="w-100">
                    <tr>
                        <td>
                            <table class="table table-bordered mb-0">
                                <tr><th>Driver:</th><td style="width: 50%;"></td></tr>
                                <tr><th>Vehicle No:</th><td></td></tr>
                                <tr><th>Loader:</th><td></td></tr>
                                <tr><th>Stock Dispatch (Date Time):</th><td></td></tr>
                            </table>
                        </td>
                        <td style="width: 8%;"></td>
                        <td>
                            <table class="table table-bordered mb-0">
                                <tr><th>Bill</th><td class="text-end">{{ money(invoice.bill_total) }}</td></tr>
                                <tr><th>Shipping Charges</th><td class="text-end">{{ money(invoice.shipping_charges) }}</td></tr>
                                <tr><th>Paid</th><td class="text-end">{{ money(invoice.paid) }}</td></tr>
                                <tr><th>Balance</th><td class="text-end">{{ money(invoice.balance) }}</td></tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <div class="row mt-5 signaturemain">
                    <div class="col-md-6">
                        <div class="signature-line"></div>
                        <span>Customer's Signature</span>
                    </div>
                    <div class="col-md-6 text-end">
                        <div class="signature-line ms-auto"></div>
                        <span>Signature &amp; Stamp</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.signature-line {
    border-top: 1px solid #333;
    width: 220px;
    margin-bottom: 0.35rem;
}

@media print {
    .no-print {
        display: none !important;
    }
}
</style>
