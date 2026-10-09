<script setup lang="ts">
    import { Head, router } from '@inertiajs/vue3';
    import { LogOut } from '@lucide/vue';
    import { onMounted, ref } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';

    type Document = { id: number; invoice_no: string | null; date: string | null; amount: number; paid: number; due: number };
    type PaymentRow = { id: number; date: string | null; amount: number; method: string; reference: string | null; invoice_no: string | null };
    type Section = {
        kind: 'customer' | 'supplier';
        balance: number;
        position: 'you_owe' | 'owed_to_you' | 'credit' | 'settled';
        as_of: string;
        documents: Document[];
        payments: PaymentRow[];
    };

    type SubscriptionPlan = { id: number; name: string; price: number; billing_cycle: string };
    type Subscription = {
        id: number;
        status: 'trial' | 'active' | 'paused' | 'cancelled' | 'expired';
        current_period_ends_at: string;
        plan: SubscriptionPlan | null;
    };

    const loading = ref(true);
    const failed = ref('');
    const name = ref('');
    const sections = ref<Section[]>([]);

    const subscriptions = ref<Subscription[]>([]);
    const subscriptionPlans = ref<Record<number, SubscriptionPlan[]>>({});
    const subscriptionBusy = ref<number | null>(null);

    const showPasswordForm = ref(false);
    const changingPassword = ref(false);
    const passwordError = ref('');
    const passwordSuccess = ref('');
    const currentPassword = ref('');
    const newPassword = ref('');
    const newPasswordConfirmation = ref('');

    const money = (value: number): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const headline = (section: Section): string => {
        switch (section.position) {
            case 'you_owe':
                return 'You owe';
            case 'owed_to_you':
                return 'You are owed';
            case 'credit':
                return section.kind === 'customer' ? 'Credit in your favour' : 'Paid in advance';
            default:
                return 'All settled';
        }
    };

    async function load() {
        try {
            const response = await window.axios.get(API_ENDPOINTS.portal);
            name.value = response.data?.contact?.name ?? '';
            sections.value = response.data?.sections ?? [];
        } catch (error: unknown) {
            failed.value = window.axios.isAxiosError(error) ? (error.response?.data?.message ?? 'The portal could not be loaded.') : 'The portal could not be loaded.';
        } finally {
            loading.value = false;
        }

        await loadSubscriptions();
    }

    async function loadSubscriptions() {
        try {
            const [subscriptionsResponse, plansResponse] = await Promise.all([
                window.axios.get(API_ENDPOINTS.portalSubscriptions),
                window.axios.get(API_ENDPOINTS.portalSubscriptionPlans),
            ]);
            subscriptions.value = subscriptionsResponse.data?.data ?? [];
            const plans = plansResponse.data?.data ?? [];
            const byId: Record<number, SubscriptionPlan[]> = {};
            subscriptions.value.forEach((subscription) => {
 byId[subscription.id] = plans; 
});
            subscriptionPlans.value = byId;
        } catch {
            // No subscriptions is not an error worth showing — the section simply stays empty.
        }
    }

    async function subscriptionAction(subscription: Subscription, action: 'pause' | 'resume' | 'cancel') {
        subscriptionBusy.value = subscription.id;

        try {
            const endpoint = action === 'pause'
                ? API_ENDPOINTS.portalSubscriptionPause(subscription.id)
                : action === 'resume'
                    ? API_ENDPOINTS.portalSubscriptionResume(subscription.id)
                    : API_ENDPOINTS.portalSubscriptionCancel(subscription.id);

            await window.axios.post(endpoint);
            await loadSubscriptions();
        } finally {
            subscriptionBusy.value = null;
        }
    }

    async function changeSubscriptionPlan(subscription: Subscription, planId: number | string) {
        subscriptionBusy.value = subscription.id;

        try {
            await window.axios.post(API_ENDPOINTS.portalSubscriptionChangePlan(subscription.id), {
                customer_subscription_plan_id: planId,
            });
            await loadSubscriptions();
        } finally {
            subscriptionBusy.value = null;
        }
    }

    function signOut() {
        router.post('/logout');
    }

    async function changePassword() {
        passwordError.value = '';
        passwordSuccess.value = '';

        if (newPassword.value !== newPasswordConfirmation.value) {
            passwordError.value = 'The new password confirmation does not match.';

            return;
        }

        changingPassword.value = true;

        try {
            await window.axios.post(API_ENDPOINTS.portalChangePassword, {
                current_password: currentPassword.value,
                password: newPassword.value,
                password_confirmation: newPasswordConfirmation.value,
            });
            passwordSuccess.value = 'Your password has been changed.';
            currentPassword.value = '';
            newPassword.value = '';
            newPasswordConfirmation.value = '';
        } catch (error: unknown) {
            const data = window.axios.isAxiosError(error) ? error.response?.data : null;
            passwordError.value = data?.errors ? (Object.values(data.errors)[0] as string[])[0] : (data?.message ?? 'The password could not be changed.');
        } finally {
            changingPassword.value = false;
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="My account" />

    <div class="container py-4" style="max-width: 60rem">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h3 class="mb-0">{{ name || 'My account' }}</h3>
                <p class="text-muted mb-0">Your balance and recent activity with us</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-test="toggle-change-password" @click="showPasswordForm = !showPasswordForm">Change password</button>
                <button type="button" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1" @click="signOut"><LogOut class="h-4 w-4" /> Sign out</button>
            </div>
        </div>

        <div v-if="showPasswordForm" class="border rounded p-3 mb-4" data-test="change-password-form" style="max-width: 24rem">
            <h6>Change password</h6>
            <div v-if="passwordError" class="alert alert-warning py-2">{{ passwordError }}</div>
            <div v-if="passwordSuccess" class="alert alert-success py-2">{{ passwordSuccess }}</div>
            <form @submit.prevent="changePassword">
                <div class="mb-2">
                    <label class="form-label small">Current password</label>
                    <input v-model="currentPassword" type="password" class="form-control form-control-sm" required autocomplete="current-password" />
                </div>
                <div class="mb-2">
                    <label class="form-label small">New password</label>
                    <input v-model="newPassword" type="password" class="form-control form-control-sm" required autocomplete="new-password" />
                </div>
                <div class="mb-3">
                    <label class="form-label small">Confirm new password</label>
                    <input v-model="newPasswordConfirmation" type="password" class="form-control form-control-sm" required autocomplete="new-password" />
                </div>
                <button type="submit" class="btn btn-primary btn-sm" :disabled="changingPassword">{{ changingPassword ? 'Saving…' : 'Save password' }}</button>
            </form>
        </div>

        <p v-if="loading" class="text-muted">Loading…</p>
        <div v-else-if="failed" class="alert alert-warning">{{ failed }}</div>

        <template v-else>
            <div v-if="subscriptions.length" class="mb-5" data-section="subscriptions">
                <h5>My subscriptions</h5>
                <div v-for="subscription in subscriptions" :key="subscription.id" class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <div class="fw-semibold">{{ subscription.plan?.name || 'Subscription' }}</div>
                            <div class="text-muted small">
                                <span class="badge bg-secondary-subtle text-secondary text-capitalize">{{ subscription.status }}</span>
                                renews {{ subscription.current_period_ends_at }}
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <select
                                v-if="subscription.status === 'active' || subscription.status === 'trial'"
                                class="form-select form-select-sm"
                                style="width: auto"
                                :value="subscription.plan?.id ?? ''"
                                :disabled="subscriptionBusy === subscription.id"
                                @change="changeSubscriptionPlan(subscription, ($event.target as HTMLSelectElement).value)"
                            >
                                <option v-for="plan in subscriptionPlans[subscription.id] ?? []" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                            </select>
                            <button
                                v-if="subscription.status === 'active' || subscription.status === 'trial'"
                                type="button" class="btn btn-outline-secondary btn-sm"
                                :disabled="subscriptionBusy === subscription.id"
                                @click="subscriptionAction(subscription, 'pause')"
                            >Pause</button>
                            <button
                                v-if="subscription.status === 'paused'"
                                type="button" class="btn btn-outline-success btn-sm"
                                :disabled="subscriptionBusy === subscription.id"
                                @click="subscriptionAction(subscription, 'resume')"
                            >Resume</button>
                            <button
                                v-if="subscription.status !== 'cancelled'"
                                type="button" class="btn btn-outline-danger btn-sm"
                                :disabled="subscriptionBusy === subscription.id"
                                @click="subscriptionAction(subscription, 'cancel')"
                            >Cancel</button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-for="section in sections" :key="section.kind" class="mb-5" :data-section="section.kind">
                <h5 v-if="sections.length > 1">{{ section.kind === 'customer' ? 'As a customer' : 'As a supplier' }}</h5>

                <div class="border rounded p-3 mb-3">
                    <div class="text-muted small">{{ headline(section) }} (as of {{ section.as_of }})</div>
                    <div class="fs-2 fw-semibold" data-test="balance">{{ money(Math.abs(section.balance)) }}</div>
                </div>

                <h6>Recent {{ section.kind === 'customer' ? 'invoices' : 'purchases' }}</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Number</th><th>Date</th><th class="text-end">Amount</th><th class="text-end">Paid</th><th class="text-end">Still due</th></tr></thead>
                        <tbody>
                            <tr v-for="document in section.documents" :key="document.id">
                                <td>{{ document.invoice_no || `#${document.id}` }}</td>
                                <td>{{ document.date || '—' }}</td>
                                <td class="text-end">{{ money(document.amount) }}</td>
                                <td class="text-end">{{ money(document.paid) }}</td>
                                <td class="text-end">{{ money(document.due) }}</td>
                            </tr>
                            <tr v-if="! section.documents.length"><td colspan="5" class="text-center text-muted">Nothing yet.</td></tr>
                        </tbody>
                    </table>
                </div>

                <h6>Recent payments</h6>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Date</th><th>Reference</th><th>For</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <tr v-for="payment in section.payments" :key="payment.id">
                                <td>{{ payment.date || '—' }}</td>
                                <td>{{ payment.reference || '—' }}</td>
                                <td>{{ payment.invoice_no || '—' }}</td>
                                <td>{{ payment.method }}</td>
                                <td class="text-end">{{ money(payment.amount) }}</td>
                            </tr>
                            <tr v-if="! section.payments.length"><td colspan="5" class="text-center text-muted">Nothing yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
