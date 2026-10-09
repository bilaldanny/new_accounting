<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    /**
     * A self-contained page, the same shape as Tenant Directory and Subscription Invoices (Module 17):
     * every action here (create, change plan, pause/resume/cancel, record usage) is a single inline
     * action, not a multi-step Vueform page.
     */
    defineOptions({
        layout: {
            title: 'Customer Subscriptions',
            subtitle: 'Your customers’ subscription contracts',
            breadcrumbs: [
                { title: 'Customer Subscriptions', href: 'NULL' },
            ],
        },
    });

    const STATUSES = ['trial', 'active', 'paused', 'cancelled', 'expired'];

    type Plan = { id: number; name: string; is_metered: boolean };
    type Subscription = {
        id: number;
        status: string;
        customer_name: string | null;
        plan_name: string | null;
        customer_subscription_plan_id: number;
        current_period_ends_at: string;
        auto_renew: boolean;
    };
    type Customer = { id: number; text: string };

    const { Notify } = useCommons();

    const loading = ref(true);
    const subscriptions = ref<Subscription[]>([]);
    const plans = ref<Plan[]>([]);
    const customers = ref<Customer[]>([]);

    const newContactId = ref<number | ''>('');
    const newPlanId = ref<number | ''>('');
    const creating = ref(false);

    const usageFor = ref<Subscription | null>(null);
    const usageQuantity = ref<number | ''>('');
    const usageNote = ref('');
    const recordingUsage = ref(false);

    function errorMessage(error: unknown): string {
        if (! window.axios.isAxiosError(error)) {
            return 'Unexpected error occurred';
        }

        const errors = Object.values(error.response?.data?.errors ?? {}).flat() as string[];

        return errors[0] || error.response?.data?.message || 'The request failed.';
    }

    async function load() {
        loading.value = true;

        try {
            const [subscriptionsResponse, plansResponse, customersResponse] = await Promise.all([
                window.axios.get(API_ENDPOINTS.customerSubscriptions),
                window.axios.get(API_ENDPOINTS.fetchCustomerSubscriptionPlans),
                window.axios.get(API_ENDPOINTS.fetchCustomers),
            ]);
            subscriptions.value = subscriptionsResponse.data?.data?.data ?? subscriptionsResponse.data?.data ?? [];
            plans.value = plansResponse.data ?? [];
            customers.value = customersResponse.data ?? [];
        } catch {
            Notify('Unable to load customer subscriptions.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function createSubscription() {
        if (! newContactId.value || ! newPlanId.value) {
            Notify('Pick a customer and a plan.', 'alert');

            return;
        }

        creating.value = true;

        try {
            await window.axios.post(API_ENDPOINTS.customerSubscriptions, {
                contact_id: newContactId.value,
                customer_subscription_plan_id: newPlanId.value,
            });
            Notify('Subscription created', 'success');
            newContactId.value = '';
            newPlanId.value = '';
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            creating.value = false;
        }
    }

    async function changePlan(subscription: Subscription, planId: string) {
        try {
            await window.axios.post(API_ENDPOINTS.customerSubscriptionChangePlan(subscription.id), {
                customer_subscription_plan_id: planId,
            });
            Notify('Plan updated', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        }
    }

    async function lifecycle(subscription: Subscription, action: 'pause' | 'resume' | 'cancel') {
        const endpoint = action === 'pause'
            ? API_ENDPOINTS.customerSubscriptionPause(subscription.id)
            : action === 'resume'
                ? API_ENDPOINTS.customerSubscriptionResume(subscription.id)
                : API_ENDPOINTS.customerSubscriptionCancel(subscription.id);

        try {
            await window.axios.post(endpoint);
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        }
    }

    function openUsage(subscription: Subscription) {
        usageFor.value = subscription;
        usageQuantity.value = '';
        usageNote.value = '';
    }

    async function submitUsage() {
        if (! usageFor.value || ! usageQuantity.value) {
            return;
        }

        recordingUsage.value = true;

        try {
            await window.axios.post(API_ENDPOINTS.customerSubscriptionUsage(usageFor.value.id), {
                quantity: usageQuantity.value,
                note: usageNote.value || null,
            });
            Notify('Usage recorded', 'success');
            usageFor.value = null;
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            recordingUsage.value = false;
        }
    }

    function planIsMetered(planId: number): boolean {
        return plans.value.find((plan) => plan.id === planId)?.is_metered ?? false;
    }

    onMounted(load);
</script>

<template>
    <Head title="Customer Subscriptions" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__body p-3 p-md-4">
                <form class="row g-2 align-items-end mb-4" @submit.prevent="createSubscription">
                    <div class="col-md-4">
                        <label class="form-label">Customer</label>
                        <select v-model="newContactId" class="form-select form-select-sm">
                            <option value="" disabled>Select a customer</option>
                            <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.text }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Plan</label>
                        <select v-model="newPlanId" class="form-select form-select-sm">
                            <option value="" disabled>Select a plan</option>
                            <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="creating">{{ creating ? 'Creating…' : 'Subscribe customer' }}</button>
                    </div>
                </form>

                <Loader v-if="loading" message="Loading customer subscriptions…" />

                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Plan</th>
                                <th>Status</th>
                                <th>Renews</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="subscription in subscriptions" :key="subscription.id">
                                <td>{{ subscription.customer_name }}</td>
                                <td>
                                    <select
                                        class="form-select form-select-sm"
                                        :value="subscription.customer_subscription_plan_id"
                                        @change="changePlan(subscription, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                                    </select>
                                </td>
                                <td><span class="badge bg-secondary-subtle text-secondary text-capitalize">{{ subscription.status }}</span></td>
                                <td>{{ subscription.current_period_ends_at }}</td>
                                <td class="text-end">
                                    <button v-if="subscription.status === 'active' || subscription.status === 'trial'" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="lifecycle(subscription, 'pause')">Pause</button>
                                    <button v-if="subscription.status === 'paused'" type="button" class="btn btn-outline-success btn-sm me-1" @click="lifecycle(subscription, 'resume')">Resume</button>
                                    <button v-if="subscription.status !== 'cancelled'" type="button" class="btn btn-outline-danger btn-sm me-1" @click="lifecycle(subscription, 'cancel')">Cancel</button>
                                    <button v-if="planIsMetered(subscription.customer_subscription_plan_id)" type="button" class="btn btn-outline-primary btn-sm" @click="openUsage(subscription)">Record usage</button>
                                </td>
                            </tr>
                            <tr v-if="! subscriptions.length">
                                <td colspan="5" class="text-center text-muted">No customer subscriptions yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="usageFor" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,0.4)">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Record usage — {{ usageFor.customer_name }}</h5>
                                <button type="button" class="btn-close" @click="usageFor = null"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Quantity</label>
                                    <input v-model="usageQuantity" type="number" step="0.01" class="form-control form-control-sm">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Note</label>
                                    <input v-model="usageNote" type="text" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" @click="usageFor = null">Cancel</button>
                                <button type="button" class="btn btn-primary btn-sm" :disabled="recordingUsage" @click="submitUsage">{{ recordingUsage ? 'Saving…' : 'Record usage' }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
