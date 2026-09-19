<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';

    // This page renders without AppLayout (see resources/js/app.ts) so it can be
    // opened in a second window/monitor as a clean, full-screen customer display.
    // It has no data of its own — the cashier's POS screen (sell/addpos.vue) pushes
    // live cart updates to it over a same-origin BroadcastChannel, so both windows
    // must be open in the same browser (e.g. the POS window dragged to a second monitor).

    type DisplayLine = {
        name: string;
        quantity: number;
        unit_price: number;
        row_subtotal: number;
    };

    type DisplayPayload = {
        type: 'update' | 'completed' | 'idle';
        businessName?: string;
        customerName?: string;
        lines?: DisplayLine[];
        totals?: {
            itemCount: number;
            netTotal: number;
            discount: number;
            shipping: number;
            finalAmount: number;
        };
        payment?: {
            totalPaying: number;
            changeReturn: number;
            balance: number;
        };
    };

    const businessName = ref('');
    const customerName = ref('Walk-In Customer');
    const lines = ref<DisplayLine[]>([]);
    const totals = reactive({
        itemCount: 0,
        netTotal: 0,
        discount: 0,
        shipping: 0,
        finalAmount: 0,
    });
    const payment = reactive({
        totalPaying: 0,
        changeReturn: 0,
        balance: 0,
    });
    const connected = ref(false);

    let channel: BroadcastChannel | null = null;
    let idleTimer: ReturnType<typeof setTimeout> | null = null;

    function money(value: unknown): string {
        const amount = Number(value);

        return (Number.isFinite(amount) ? amount : 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function resetToIdle() {
        customerName.value = 'Walk-In Customer';
        lines.value = [];
        totals.itemCount = 0;
        totals.netTotal = 0;
        totals.discount = 0;
        totals.shipping = 0;
        totals.finalAmount = 0;
        payment.totalPaying = 0;
        payment.changeReturn = 0;
        payment.balance = 0;
    }

    function applyPayload(payload: DisplayPayload) {
        connected.value = true;

        if (idleTimer) {
            clearTimeout(idleTimer);
        }

        if (payload.businessName) {
            businessName.value = payload.businessName;
        }

        if (payload.type === 'idle') {
            resetToIdle();

            return;
        }

        customerName.value = payload.customerName || 'Walk-In Customer';
        lines.value = payload.lines ?? [];

        if (payload.totals) {
            Object.assign(totals, payload.totals);
        }

        payment.totalPaying = payload.payment?.totalPaying ?? 0;
        payment.changeReturn = payload.payment?.changeReturn ?? 0;
        payment.balance = payload.payment?.balance ?? 0;

        if (payload.type === 'completed') {
            // Give the customer a moment to see the completed total before the
            // screen clears itself for the next customer.
            idleTimer = setTimeout(resetToIdle, 8000);
        }
    }

    const slides = [
        'Thank you for shopping with us today.',
        'Ask our staff about today’s offers.',
        'We hope to see you again soon!',
    ];
    const activeSlide = ref(0);
    let slideTimer: ReturnType<typeof setInterval> | null = null;

    onMounted(() => {
        if (typeof BroadcastChannel !== 'undefined') {
            channel = new BroadcastChannel('pos-customer-display');
            channel.onmessage = (event: MessageEvent<DisplayPayload>) => applyPayload(event.data);
        }

        slideTimer = setInterval(() => {
            activeSlide.value = (activeSlide.value + 1) % slides.length;
        }, 4000);
    });

    onBeforeUnmount(() => {
        channel?.close();

        if (idleTimer) {
            clearTimeout(idleTimer);
        }

        if (slideTimer) {
            clearInterval(slideTimer);
        }
    });
</script>

<template>
    <Head title="Customer Display" />

    <div class="display-screen">
        <div class="display-topbar">
            <span>{{ businessName || 'Welcome' }}</span>
        </div>

        <div class="display-body">
            <div class="display-cart">
                <div class="display-cart__customer">
                    {{ customerName }}
                </div>

                <table class="display-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="is-center">Quantity</th>
                            <th class="is-right">Price</th>
                            <th class="is-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in lines" :key="index">
                            <td>{{ line.name }}</td>
                            <td class="is-center">{{ line.quantity }}</td>
                            <td class="is-right">{{ money(line.unit_price) }}</td>
                            <td class="is-right">{{ money(line.row_subtotal) }}</td>
                        </tr>
                        <tr v-if="!lines.length">
                            <td colspan="4" class="display-table__empty">Waiting for items…</td>
                        </tr>
                    </tbody>
                </table>

                <div class="display-summary">
                    <div class="display-summary__row">
                        <span><strong>Items:</strong> {{ totals.itemCount }}</span>
                        <span><strong>Total:</strong> ${{ money(totals.netTotal) }}</span>
                    </div>
                    <div class="display-summary__row">
                        <span><strong>Discount (-):</strong> ${{ money(totals.discount) }}</span>
                        <span><strong>Shipping (+):</strong> ${{ money(totals.shipping) }}</span>
                        <strong class="display-summary__total">Total Payable: ${{ money(totals.finalAmount) }}</strong>
                    </div>
                </div>

                <div class="display-payment">
                    <div>
                        <span>Total Paying:</span>
                        <strong>${{ money(payment.totalPaying) }}</strong>
                    </div>
                    <div>
                        <span>Change Return:</span>
                        <strong>${{ money(payment.changeReturn) }}</strong>
                    </div>
                    <div>
                        <span>Balance:</span>
                        <strong class="is-balance">${{ money(payment.balance) }}</strong>
                    </div>
                </div>
            </div>

            <div class="display-promo">
                <div class="display-promo__mark">{{ (businessName || 'POS').charAt(0) }}</div>
                <h2>{{ businessName || 'Welcome' }}</h2>
                <p>{{ slides[activeSlide] }}</p>
                <div class="display-promo__dots">
                    <span v-for="(slide, index) in slides" :key="index" :class="{ 'is-active': index === activeSlide }"></span>
                </div>
                <p v-if="!connected" class="display-promo__hint">Waiting for the POS screen to connect…</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.display-screen {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    box-sizing: border-box;
    min-height: 100vh;
    padding: 0.75rem;
    background: var(--app-background, #f8fafc);
    font-family: var(--app-font, Inter, ui-sans-serif, system-ui, sans-serif);
}

.display-screen *,
.display-screen *::before,
.display-screen *::after {
    box-sizing: border-box;
}

.display-topbar {
    padding: 0.9rem;
    border-radius: var(--app-radius-lg, 14px);
    background: #fff;
    box-shadow: var(--app-shadow-sm, 0 1px 2px rgba(15, 23, 42, 0.05));
    text-align: center;
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--app-text, #111827);
}

.display-body {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
    gap: 0.75rem;
    flex: 1;
}

@media (max-width: 1024px) {
    .display-body {
        grid-template-columns: 1fr;
    }
}

.display-cart {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 1.25rem;
    border-radius: var(--app-radius-lg, 14px);
    background: #fff;
    box-shadow: var(--app-shadow-sm, 0 1px 2px rgba(15, 23, 42, 0.05));
}

.display-cart__customer {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--app-text, #111827);
}

.display-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.95rem;
}

.display-table thead th {
    padding: 0.6rem 0.5rem;
    border-bottom: 2px solid var(--app-text, #111827);
    text-align: left;
    font-weight: 700;
    color: var(--app-text, #111827);
}

.display-table td {
    padding: 0.55rem 0.5rem;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
}

.display-table .is-center {
    text-align: center;
}

.display-table .is-right {
    text-align: right;
}

.display-table__empty {
    padding: 2rem 0.5rem;
    text-align: center;
    color: #94a3b8;
}

.display-summary {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    padding-top: 0.4rem;
    border-top: 1px solid var(--app-border, #e2e8f0);
    font-size: 0.95rem;
    color: var(--app-text, #111827);
}

.display-summary__row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.display-summary__total {
    margin-left: auto;
    color: var(--app-primary-hover, #0f766e);
}

.display-payment {
    display: flex;
    flex-wrap: wrap;
    gap: 1.25rem;
    margin-top: auto;
    padding: 1rem 1.25rem;
    border-radius: var(--app-radius-md, 9px);
    background: var(--app-warning, #d97706);
    color: #fff;
}

.display-payment div {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    font-size: 0.85rem;
}

.display-payment strong {
    font-size: 1.35rem;
}

.display-payment strong.is-balance {
    color: #fff7ed;
}

.display-promo {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 2rem;
    border-radius: var(--app-radius-xl, 18px);
    background: var(--app-btn-gradient, linear-gradient(to right, #0d9488, #0f2b48));
    color: #fff;
    text-align: center;
}

.display-promo__mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 4.5rem;
    height: 4.5rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.15);
    font-size: 2rem;
    font-weight: 800;
}

.display-promo h2 {
    margin: 0;
    font-size: 1.6rem;
    font-weight: 800;
}

.display-promo p {
    margin: 0;
    max-width: 26rem;
    font-size: 1.05rem;
    opacity: 0.9;
}

.display-promo__dots {
    display: flex;
    gap: 0.4rem;
}

.display-promo__dots span {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.35);
}

.display-promo__dots span.is-active {
    background: #fff;
}

.display-promo__hint {
    margin-top: 1rem;
    font-size: 0.8rem;
    opacity: 0.75;
}
</style>
