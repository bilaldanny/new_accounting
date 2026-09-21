<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';

    /**
     * The customer-facing part of the Discount, Gift Card and Loyalty modules on the sell form and the POS:
     * an "Apply discount code" field, a "Pay with gift card" field and the customer's loyalty points.
     * Each part shows only when the company has switched it on (Checkout Extras / Loyalty settings), and the
     * server enforces the same switches and re-checks every amount when the sale is saved.
     *
     * It never changes totals itself: it writes `discount_code` / `coupon_discount_amount` and
     * `gift_card_code` / `gift_card_amount` / `gift_card_payment_account` into the form through `persist`,
     * and the parent (which owns `recalculateTotals`) takes the coupon off `final_amount`.
     */
    const props = defineProps<{
        formData: Record<string, any>;
        netSubTotal: number;
        persist: (patch: Record<string, unknown>) => void;
        variant?: 'form' | 'pos';
    }>();

    const isPos = computed(() => props.variant === 'pos');
    const rowClass = computed(() => (isPos.value ? 'pos-summary__row' : 'd-flex align-items-center justify-content-between gap-2 py-1'));
    const inputClass = computed(() => (isPos.value ? 'pos-select pos-select--sm' : 'form-control form-control-sm'));

    const enabled = reactive({ discount: false, gift: false, loyalty: false });
    const loyalty = reactive({ amountPerPoint: 100, pointValue: 1, minRedeem: 0, balance: null as number | null, balanceValue: 0 });
    const accounts = ref<Array<{ id: number; text?: string; name?: string }>>([]);

    const discountInput = ref(String(props.formData.discount_code ?? ''));
    const discountMessage = ref('');
    const discountBusy = ref(false);

    const giftInput = ref('');
    const giftMessage = ref('');
    const giftBusy = ref(false);
    const giftBalance = ref<number | null>(null);

    const isFinal = computed(() => (props.formData.status ?? 'final') === 'final');
    const alreadyGiftPaid = computed(() => (props.formData.gift_card_payments ?? []).length > 0);
    const appliedCode = computed(() => String(props.formData.discount_code ?? ''));
    const couponAmount = computed(() => Number(props.formData.coupon_discount_amount ?? 0));
    const finalAmount = computed(() => Number(props.formData.final_amount ?? 0));
    const estimatedPoints = computed(() => (loyalty.amountPerPoint > 0 ? Math.floor(Math.max(finalAmount.value, 0) / loyalty.amountPerPoint) : 0));

    // Redeeming: the points the customer holds (the ones this sale already spent are theirs again while it is
    // edited), capped so their value never exceeds what is left of the goods after a coupon
    const redeemedPoints = computed(() => Number(props.formData.loyalty_points ?? 0));
    const alreadySpent = computed(() => (String(props.formData.loyalty_saved_contact_id ?? '') === String(props.formData.contact_id ?? '') ? Number(props.formData.loyalty_points_spent ?? 0) : 0));
    const availablePoints = computed(() => (loyalty.balance ?? 0) + alreadySpent.value);
    const redeemableMax = computed(() => {
        if (loyalty.pointValue <= 0) {
            return 0;
        }

        const byValue = Math.floor(Math.max(props.netSubTotal - couponAmount.value, 0) / loyalty.pointValue);

        return Math.max(Math.min(availablePoints.value, byValue), 0);
    });
    const canRedeem = computed(() => {
        const user = usePage().props.auth?.user as { rolename?: string; permission_paths?: string[] } | null;

        return String(user?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin' || (user?.permission_paths ?? []).includes('/loyalty/redeem');
    });
    const showRedeem = computed(() => enabled.loyalty && canRedeem.value && isFinal.value && Boolean(props.formData.contact_id) && (redeemableMax.value > 0 || redeemedPoints.value > 0));

    const money = (value: unknown) => Number(value ?? 0).toFixed(2);

    function apiMessage(error: unknown, fallback: string): string {
        if (! window.axios.isAxiosError(error)) {
            return fallback;
        }

        const errors = Object.values(error.response?.data?.errors ?? {}).flat() as string[];

        return errors[0] || error.response?.data?.message || fallback;
    }

    async function loadSettings() {
        const companyId = props.formData.company_id;

        enabled.discount = false;
        enabled.gift = false;
        enabled.loyalty = false;

        if (! companyId) {
            return;
        }

        const [checkout, programme] = await Promise.allSettled([
            window.axios.get(API_ENDPOINTS.documentSettings('checkout'), { params: { company_id: companyId } }),
            window.axios.get(API_ENDPOINTS.loyaltySettings, { params: { company_id: companyId } }),
        ]);

        if (checkout.status === 'fulfilled') {
            enabled.discount = Boolean(checkout.value.data?.values?.discount_codes_enabled);
            enabled.gift = Boolean(checkout.value.data?.values?.gift_cards_enabled);
        }

        if (programme.status === 'fulfilled') {
            enabled.loyalty = Boolean(programme.value.data?.is_enabled);
            loyalty.amountPerPoint = Number(programme.value.data?.amount_per_point ?? 100);
            loyalty.pointValue = Number(programme.value.data?.point_value ?? 1);
            loyalty.minRedeem = Number(programme.value.data?.min_redeem_points ?? 0);
        }

        if (enabled.gift) {
            await loadAccounts();
        }

        await loadBalance();
    }

    async function loadAccounts() {
        try {
            const response = await window.axios.get(API_ENDPOINTS.sellPaymentAccounts, {
                params: { company_id: props.formData.company_id, branch_id: props.formData.branch_id },
            });
            accounts.value = response.data ?? [];

            if (! props.formData.gift_card_payment_account && accounts.value.length > 0) {
                const preferred = accounts.value.find((account) => /gift/i.test(String(account.text ?? account.name ?? ''))) ?? accounts.value[0];
                props.persist({ gift_card_payment_account: preferred.id });
            }
        } catch {
            accounts.value = [];
        }
    }

    async function loadBalance() {
        loyalty.balance = null;

        if (! enabled.loyalty || ! props.formData.contact_id) {
            return;
        }

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.loyalty}/${props.formData.contact_id}`);
            loyalty.balance = Number(response.data?.balance ?? 0);
            loyalty.balanceValue = Number(response.data?.balance_value ?? 0);
        } catch {
            loyalty.balance = null;
        }
    }

    async function applyCode(silent = false) {
        const code = discountInput.value.trim();

        if (! code) {
            return;
        }

        discountBusy.value = true;
        discountMessage.value = '';

        try {
            const response = await window.axios.post(API_ENDPOINTS.discountApply, {
                code,
                subtotal: props.netSubTotal,
                company_id: props.formData.company_id,
            });
            props.persist({ discount_code: response.data.code ?? code.toUpperCase(), coupon_discount_amount: Number(response.data.discount_amount ?? 0) });
            discountInput.value = String(response.data.code ?? code.toUpperCase());

            if (! silent) {
                discountMessage.value = `${response.data.name ?? 'Discount'} applied: − ${money(response.data.discount_amount)}`;
            }
        } catch (error: unknown) {
            const reason = window.axios.isAxiosError(error) ? error.response?.data?.reason : null;
            const message = apiMessage(error, 'The discount code could not be applied.');

            // a discount that has expired since it was applied to this sale stays on it; the server decides on save
            if (silent && reason !== 'min_purchase') {
                return;
            }

            props.persist({ discount_code: '', coupon_discount_amount: 0 });
            discountMessage.value = message;
        } finally {
            discountBusy.value = false;
        }
    }

    function removeCode() {
        discountInput.value = '';
        discountMessage.value = '';
        props.persist({ discount_code: '', coupon_discount_amount: 0 });
    }

    async function checkGiftCard() {
        const code = giftInput.value.trim();

        if (! code) {
            return;
        }

        giftBusy.value = true;
        giftMessage.value = '';

        try {
            const response = await window.axios.post(API_ENDPOINTS.giftCardLookup, { code, company_id: props.formData.company_id });

            if (! response.data.usable) {
                giftBalance.value = null;
                giftMessage.value = response.data.reason === 'expired' ? 'This gift card has expired.' : 'This gift card is switched off.';
                props.persist({ gift_card_code: '', gift_card_amount: 0 });

                return;
            }

            giftBalance.value = Number(response.data.balance);
            props.persist({
                gift_card_code: response.data.code,
                gift_card_amount: Math.min(giftBalance.value, Math.max(finalAmount.value, 0)),
            });
            giftMessage.value = `Balance ${money(giftBalance.value)}`;
        } catch (error: unknown) {
            giftBalance.value = null;
            giftMessage.value = apiMessage(error, 'The gift card could not be checked.');
            props.persist({ gift_card_code: '', gift_card_amount: 0 });
        } finally {
            giftBusy.value = false;
        }
    }

    function removeGiftCard() {
        giftInput.value = '';
        giftMessage.value = '';
        giftBalance.value = null;
        props.persist({ gift_card_code: '', gift_card_amount: 0 });
    }

    function setRedeemedPoints(points: number) {
        const whole = Math.max(Math.min(Math.floor(Number.isFinite(points) ? points : 0), redeemableMax.value), 0);

        props.persist({ loyalty_points: whole, loyalty_discount_amount: Number((whole * loyalty.pointValue).toFixed(2)) });
    }

    function redeemAll() {
        setRedeemedPoints(redeemableMax.value);
    }

    function setGiftAmount(event: Event) {
        const raw = Number((event.target as HTMLInputElement).value);
        const ceiling = Math.min(giftBalance.value ?? raw, Math.max(finalAmount.value, 0));

        props.persist({ gift_card_amount: Math.max(Math.min(Number.isFinite(raw) ? raw : 0, ceiling), 0) });
    }

    // keep the input in step when the form is filled from outside (an edit page loading a sale)
    watch(() => props.formData.discount_code, (value) => {
        discountInput.value = String(value ?? '');
    });

    watch(() => props.formData.gift_card_code, (value) => {
        if (! value) {
            giftInput.value = '';
            giftBalance.value = null;
        }
    });

    watch(() => [props.formData.company_id, props.formData.branch_id], () => loadSettings());
    watch(() => props.formData.contact_id, () => {
        // points belong to a customer: another customer starts with none redeemed
        if (redeemedPoints.value > 0 && String(props.formData.loyalty_saved_contact_id ?? '') !== String(props.formData.contact_id ?? '')) {
            setRedeemedPoints(0);
        }

        void loadBalance();
    });

    // the goods or the coupon moved: the points can never be worth more than what is left of them
    watch(redeemableMax, (ceiling) => {
        if (redeemedPoints.value > ceiling && loyalty.balance !== null) {
            setRedeemedPoints(ceiling);
        }
    });

    // the line total moved: price the code again against it
    watch(() => props.netSubTotal, () => {
        if (appliedCode.value) {
            discountInput.value = appliedCode.value;
            void applyCode(true);
        }
    });

    // a gift card can never pay more than the sale now costs
    watch(finalAmount, (amount) => {
        if (props.formData.gift_card_code && Number(props.formData.gift_card_amount) > amount) {
            props.persist({ gift_card_amount: Math.max(amount, 0) });
        }
    });

    watch(isFinal, (final) => {
        if (! final && props.formData.gift_card_code) {
            removeGiftCard();
        }

        if (! final && redeemedPoints.value > 0) {
            setRedeemedPoints(0);
        }
    });

    onMounted(loadSettings);
</script>

<template>
    <div v-if="enabled.discount || enabled.gift || enabled.loyalty" class="sale-incentives" :class="isPos ? 'pos-summary__extras' : 'border rounded p-3 mb-3 bg-white'">
        <div v-if="enabled.discount" class="sale-incentives__discount">
            <div :class="rowClass">
                <span>Discount code</span>
                <div class="d-flex gap-1">
                    <input
                        v-model="discountInput"
                        type="text"
                        maxlength="50"
                        :class="inputClass"
                        placeholder="Code"
                        aria-label="Discount code"
                        :disabled="discountBusy"
                        @keydown.enter.prevent="applyCode()"
                    >
                    <button v-if="! appliedCode" type="button" class="btn btn-outline-primary btn-sm" :disabled="discountBusy || ! discountInput.trim()" @click="applyCode()">
                        Apply
                    </button>
                    <button v-else type="button" class="btn btn-outline-secondary btn-sm" @click="removeCode">Remove</button>
                </div>
            </div>
            <div v-if="appliedCode" :class="rowClass">
                <span class="text-success">Coupon {{ appliedCode }}</span>
                <strong>− {{ money(couponAmount) }}</strong>
            </div>
            <p v-if="discountMessage" class="small text-muted mb-1">{{ discountMessage }}</p>
        </div>

        <div v-if="enabled.gift && isFinal" class="sale-incentives__gift">
            <template v-if="alreadyGiftPaid">
                <div v-for="(payment, index) in formData.gift_card_payments" :key="index" :class="rowClass">
                    <span>Paid by gift card {{ payment.code }}</span>
                    <strong>{{ money(payment.amount) }}</strong>
                </div>
            </template>

            <template v-else>
                <div :class="rowClass">
                    <span>Gift card</span>
                    <div class="d-flex gap-1">
                        <input
                            v-model="giftInput"
                            type="text"
                            maxlength="50"
                            :class="inputClass"
                            placeholder="Card code"
                            aria-label="Gift card code"
                            :disabled="giftBusy || Boolean(formData.gift_card_code)"
                            @keydown.enter.prevent="checkGiftCard()"
                        >
                        <button v-if="! formData.gift_card_code" type="button" class="btn btn-outline-primary btn-sm" :disabled="giftBusy || ! giftInput.trim()" @click="checkGiftCard()">
                            Check
                        </button>
                        <button v-else type="button" class="btn btn-outline-secondary btn-sm" @click="removeGiftCard">Remove</button>
                    </div>
                </div>

                <template v-if="formData.gift_card_code">
                    <div :class="rowClass">
                        <span>Pay from card</span>
                        <input
                            :value="formData.gift_card_amount"
                            type="number"
                            min="0"
                            step="0.01"
                            :class="inputClass"
                            aria-label="Amount to take from the gift card"
                            @input="setGiftAmount"
                        >
                    </div>
                    <div :class="rowClass">
                        <span>Post to account</span>
                        <select
                            :value="formData.gift_card_payment_account"
                            :class="inputClass"
                            aria-label="Account for the gift card payment"
                            @change="persist({ gift_card_payment_account: Number(($event.target as HTMLSelectElement).value) || '' })"
                        >
                            <option value="">Select account</option>
                            <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.text ?? account.name }}</option>
                        </select>
                    </div>
                </template>

                <p v-if="giftMessage" class="small text-muted mb-1">{{ giftMessage }}</p>
            </template>
        </div>

        <div v-if="enabled.loyalty && formData.contact_id" class="sale-incentives__loyalty">
            <div :class="rowClass">
                <span>Loyalty points</span>
                <strong>{{ loyalty.balance === null ? '—' : loyalty.balance }}<span v-if="loyalty.balance" class="text-muted small"> (worth {{ money(loyalty.balanceValue) }})</span></strong>
            </div>
            <div v-if="showRedeem" :class="rowClass">
                <span>Redeem points</span>
                <div class="d-flex gap-1">
                    <input
                        :value="redeemedPoints"
                        type="number"
                        min="0"
                        :max="redeemableMax"
                        step="1"
                        :class="inputClass"
                        aria-label="Loyalty points to redeem"
                        @input="setRedeemedPoints(Number(($event.target as HTMLInputElement).value))"
                    >
                    <button v-if="! redeemedPoints" type="button" class="btn btn-outline-primary btn-sm" :disabled="redeemableMax < 1" @click="redeemAll">Use max</button>
                    <button v-else type="button" class="btn btn-outline-secondary btn-sm" @click="setRedeemedPoints(0)">Clear</button>
                </div>
            </div>
            <div v-if="showRedeem && redeemedPoints > 0" :class="rowClass">
                <span class="text-success">Points discount</span>
                <strong>− {{ money(formData.loyalty_discount_amount) }}</strong>
            </div>
            <p v-if="showRedeem && loyalty.minRedeem > redeemedPoints && redeemedPoints > 0" class="small text-danger mb-1">At least {{ loyalty.minRedeem }} points must be redeemed at once.</p>
            <p v-else-if="showRedeem" class="small text-muted mb-1">1 point = {{ money(loyalty.pointValue) }}<span v-if="loyalty.minRedeem > 0">, at least {{ loyalty.minRedeem }} to redeem</span></p>
            <div v-if="isFinal && estimatedPoints > 0" :class="rowClass">
                <span class="text-muted small">This sale earns about</span>
                <span class="text-muted small">+{{ estimatedPoints }} points</span>
            </div>
        </div>
    </div>
</template>
