<script setup lang="ts">
    import { Head, Link } from '@inertiajs/vue3';
    import JsBarcode from 'jsbarcode';
    import { computed, nextTick, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import useSells from '@/composables/sell';

    const props = defineProps({
        id: {
            type: [Number, String],
            required: true,
        },
    });

    type ReceiptSettings = {
        printer_type: 'thermal' | 'a4' | 'a5' | 'dot_matrix';
        paper_width_mm: number;
        copies: number;
        show_logo: boolean;
        show_barcode: boolean;
        auto_print: boolean;
        header_text: string;
        footer_text: string;
    };

    // What a company that never saved its Receipt Printer Settings gets (the same defaults as the settings page).
    const defaultSettings: ReceiptSettings = {
        printer_type: 'thermal',
        paper_width_mm: 80,
        copies: 1,
        show_logo: true,
        show_barcode: false,
        auto_print: false,
        header_text: '',
        footer_text: 'Thank you for your business',
    };

    const { Notify } = useCommons();
    const { getEditData, viewData } = useSells();
    const loading = ref(true);

    const sale = computed(() => viewData.value as Record<string, any>);
    const settings = computed<ReceiptSettings>(() => ({ ...defaultSettings, ...(sale.value.print_settings?.receipt ?? {}) }));
    const copies = computed(() => Math.max(1, Math.min(5, Number(settings.value.copies) || 1)));

    /**
     * Sheet printers (A4 / A5) print a full page, a thermal roll prints as wide as its paper, dot matrix is the
     * same text laid out in a monospace face.
     */
    const paper = computed(() => {
        switch (settings.value.printer_type) {
            case 'a4':
                return { css: 'A4', width: '190mm' };
            case 'a5':
                return { css: 'A5', width: '128mm' };
            case 'dot_matrix':
                return { css: 'A5', width: '128mm' };
            default: {
                const width = Number(settings.value.paper_width_mm) === 58 ? 58 : 80;

                return { css: `${width}mm auto`, width: `${width - 6}mm` };
            }
        }
    });

    const pageRule = computed(() => `@page { size: ${paper.value.css}; margin: 3mm; }`);

    const autoPrint = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('autoprint') === '1';

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

    function renderBarcodes() {
        document.querySelectorAll<SVGElement>('.receipt-barcode').forEach((el) => {
            try {
                JsBarcode(el, String(sale.value.invoice_no || ''), {
                    format: 'CODE128',
                    width: 1.4,
                    height: 34,
                    fontSize: 11,
                    margin: 2,
                    displayValue: true,
                });
            } catch {
                // An invoice number the barcode cannot encode: print the receipt without it.
            }
        });
    }

    function printReceipt() {
        window.print();
    }

    onMounted(async () => {
        loading.value = true;
        const loaded = await getEditData(Number(props.id));

        if (! loaded) {
            Notify('Sale not found', 'alert');
        }

        loading.value = false;
        await nextTick();

        if (settings.value.show_barcode && sale.value.invoice_no) {
            renderBarcodes();
        }

        // The POS opens this page in a hidden frame after a sale when "Print automatically" is on.
        if (loaded && autoPrint) {
            window.print();
        }
    });
</script>

<template>
    <Head title="Receipt" />

    <component :is="'style'">{{ pageRule }}</component>

    <div class="receipt-page">
        <div class="receipt-page__toolbar no-print">
            <Link :href="`/sell/${props.id}/invoice`" class="btn btn-light btn-sm">Invoice</Link>
            <Link href="/sell" class="btn btn-light btn-sm">Back</Link>
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="printReceipt">Print</button>
        </div>

        <Loader v-if="loading" message="Loading receipt…" />

        <template v-else>
            <div
                v-for="copy in copies"
                :key="copy"
                class="receipt-sheet"
                :class="[`receipt-sheet--${settings.printer_type}`]"
                :style="{ width: paper.width }"
            >
                <div class="text-center">
                    <img v-if="settings.show_logo && sale.company_logo_url" :src="sale.company_logo_url" alt="Company logo" class="receipt-logo">
                    <div class="receipt-business">{{ sale.company_business_name || sale.company_name || '' }}</div>
                    <div v-if="sale.company_setting_address" class="receipt-small">{{ sale.company_setting_address }}</div>
                    <div v-if="sale.company_phone || sale.company_cell" class="receipt-small">
                        {{ [sale.company_phone, sale.company_cell].filter(Boolean).join(', ') }}
                    </div>
                    <div v-if="settings.header_text" class="receipt-header-text">{{ settings.header_text }}</div>
                </div>

                <hr class="receipt-rule">

                <div class="receipt-row"><span>Invoice</span><span>{{ sale.invoice_no || '—' }}</span></div>
                <div class="receipt-row"><span>Date</span><span>{{ sale.transaction_date_label || sale.transaction_date || '—' }}</span></div>
                <div class="receipt-row"><span>Customer</span><span>{{ sale.customer_full_name || sale.customer_name || '—' }}</span></div>
                <div v-if="sale.created_by_name" class="receipt-row"><span>Cashier</span><span>{{ sale.created_by_name }}</span></div>

                <hr class="receipt-rule">

                <div v-for="(line, index) in (sale.selllines || [])" :key="line.id || index" class="receipt-line">
                    <div>{{ line.product_name || '—' }}</div>
                    <div class="receipt-row receipt-small">
                        <span>{{ money(lineQty(line)) }} {{ line.unit_name || '' }} x {{ money(line.unit_price_after_discount) }}</span>
                        <span>{{ money(line.row_subtotal ?? line.subtotal) }}</span>
                    </div>
                </div>

                <hr class="receipt-rule">

                <div class="receipt-row"><span>Bill</span><span>{{ money(sale.bill_total) }}</span></div>
                <div v-if="Number(sale.shipping_charges)" class="receipt-row"><span>Shipping</span><span>{{ money(sale.shipping_charges) }}</span></div>
                <div class="receipt-row receipt-total"><span>Total</span><span>{{ money(sale.final_amount) }}</span></div>
                <div class="receipt-row"><span>Paid</span><span>{{ money(sale.paid) }}</span></div>
                <div class="receipt-row"><span>Balance</span><span>{{ money(sale.balance) }}</span></div>

                <div v-if="settings.show_barcode && sale.invoice_no" class="text-center mt-2">
                    <svg class="receipt-barcode" />
                </div>

                <div v-if="settings.footer_text" class="receipt-footer text-center">{{ settings.footer_text }}</div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.receipt-page {
    padding: 1rem;
}

.receipt-page__toolbar {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.receipt-sheet {
    margin: 0 auto 1.5rem;
    padding: 0.5rem;
    background: #fff;
    color: #000;
    font-size: 12px;
    line-height: 1.35;
    page-break-after: always;
    break-after: page;
}

.receipt-sheet:last-child {
    page-break-after: auto;
    break-after: auto;
}

.receipt-sheet--a4,
.receipt-sheet--a5 {
    font-size: 14px;
}

.receipt-sheet--dot_matrix {
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
}

.receipt-logo {
    max-width: 50%;
    max-height: 60px;
    margin-bottom: 0.25rem;
}

.receipt-business {
    font-weight: 700;
    font-size: 1.15em;
}

.receipt-small {
    font-size: 0.9em;
}

.receipt-header-text {
    margin-top: 0.25rem;
    font-style: italic;
    white-space: pre-line;
}

.receipt-rule {
    border: 0;
    border-top: 1px dashed #000;
    margin: 0.4rem 0;
    opacity: 1;
}

.receipt-row {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
}

.receipt-line {
    margin-bottom: 0.3rem;
}

.receipt-total {
    font-weight: 700;
    font-size: 1.1em;
}

.receipt-footer {
    margin-top: 0.5rem;
    white-space: pre-line;
}

@media print {
    .receipt-page {
        padding: 0;
    }

    .receipt-sheet {
        margin: 0 auto;
        padding: 0;
    }

    .no-print {
        display: none !important;
    }
}
</style>
