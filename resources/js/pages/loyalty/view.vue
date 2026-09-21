<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, Medal, User } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Loyalty Points',
            subtitle: 'Customer points balance and history',
            breadcrumbs: [
                {
                    title: 'Loyalty Points',
                    href: '/loyalty',
                },
                {
                    title: 'View Loyalty Points',
                    href: 'NULL',
                },
            ],
        },
    });

    const routeProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
    });

    const page = usePage();
    const { Notify } = useCommons();

    type Entry = {
        id: number;
        type: 'earn' | 'redeem' | 'adjust';
        points: number;
        balance_after: number;
        amount?: string | number | null;
        note?: string | null;
        created_at?: string;
        user?: { first_name?: string; last_name?: string } | null;
    };

    type Detail = {
        id?: number;
        name?: string;
        mobile?: string | null;
        company_name?: string | null;
        balance?: number;
        balance_value?: number;
        earned_total?: number;
        redeemed_total?: number;
        settings?: { is_enabled: boolean; amount_per_point: number; point_value: number; min_redeem_points: number };
        entries?: Entry[];
    };

    const loading = ref(true);
    const saving = ref(false);
    const customer = ref<Detail>({});
    const redeemPoints = ref<number | null>(null);
    const adjustPoints = ref<number | null>(null);
    const adjustNote = ref('');
    const saleId = ref<number | null>(null);

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const canRedeem = computed(() => permissionPaths.value.includes('/loyalty/redeem'));
    const canAdjust = computed(() => permissionPaths.value.includes('/loyalty/adjust'));
    const canEarn = computed(() => permissionPaths.value.includes('/loyalty/earn'));

    const typeLabels: Record<string, string> = { earn: 'Earned', redeem: 'Redeemed', adjust: 'Adjusted' };

    async function loadCustomer() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.loyalty}/${routeProps.id}`);
            customer.value = response.data;
        } catch {
            Notify('Unable to load the customer points.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function send(action: 'earn' | 'redeem' | 'adjust', body: Record<string, unknown>) {
        saving.value = true;

        try {
            const response = await window.axios.post(`${API_ENDPOINTS.loyalty}/${action}`, body);
            Notify(response.data?.message || 'Successfully Saved', 'success');
            redeemPoints.value = null;
            adjustPoints.value = null;
            adjustNote.value = '';
            saleId.value = null;
            await loadCustomer();
        } catch (error: unknown) {
            const message = window.axios.isAxiosError(error)
                ? (Object.values(error.response?.data?.errors ?? {}).flat()[0] as string | undefined) || error.response?.data?.message || 'The request failed.'
                : 'Unexpected error occurred';
            Notify(message, 'alert');
        } finally {
            saving.value = false;
        }
    }

    onMounted(loadCustomer);
</script>

<template>
    <Head title="View Loyalty Points" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/loyalty" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to loyalty points
                </Link>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading customer points…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <p class="text-muted mb-1">{{ customer.company_name || '—' }}</p>
                        <h4 class="mb-0">{{ customer.name || 'Customer' }}</h4>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <Medal class="h-4 w-4" />
                                Points
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-6 text-muted">Balance</dt>
                                <dd class="col-6 fw-semibold">{{ customer.balance ?? 0 }}</dd>
                                <dt class="col-6 text-muted">Worth</dt>
                                <dd class="col-6">{{ customer.balance_value ?? 0 }}</dd>
                                <dt class="col-6 text-muted">Earned in total</dt>
                                <dd class="col-6">{{ customer.earned_total ?? 0 }}</dd>
                                <dt class="col-6 text-muted">Redeemed in total</dt>
                                <dd class="col-6">{{ customer.redeemed_total ?? 0 }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <User class="h-4 w-4" />
                                Customer
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-6 text-muted">Mobile</dt>
                                <dd class="col-6">{{ customer.mobile || '—' }}</dd>
                                <dt class="col-6 text-muted">Programme</dt>
                                <dd class="col-6">{{ customer.settings?.is_enabled ? 'On' : 'Off' }}</dd>
                                <dt class="col-6 text-muted">Spend per point</dt>
                                <dd class="col-6">{{ customer.settings?.amount_per_point ?? '—' }}</dd>
                                <dt class="col-6 text-muted">Point value</dt>
                                <dd class="col-6">{{ customer.settings?.point_value ?? '—' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div v-if="canRedeem || canAdjust || canEarn" class="col-12">
                        <div class="border rounded p-3">
                            <h6 class="mb-3">Move points</h6>
                            <div class="row g-3">
                                <div v-if="canRedeem" class="col-md-4">
                                    <label class="form-label" for="loyalty-redeem">Redeem points</label>
                                    <div class="input-group input-group-sm">
                                        <input id="loyalty-redeem" v-model.number="redeemPoints" type="number" min="1" step="1" class="form-control">
                                        <button
                                            type="button"
                                            class="btn btn-primary"
                                            :disabled="saving || ! redeemPoints"
                                            @click="send('redeem', { contact_id: customer.id, points: redeemPoints })"
                                        >
                                            Redeem
                                        </button>
                                    </div>
                                </div>

                                <div v-if="canEarn" class="col-md-4">
                                    <label class="form-label" for="loyalty-sale">Award points for a sale (invoice id)</label>
                                    <div class="input-group input-group-sm">
                                        <input id="loyalty-sale" v-model.number="saleId" type="number" min="1" step="1" class="form-control">
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary"
                                            :disabled="saving || ! saleId"
                                            @click="send('earn', { transaction_id: saleId })"
                                        >
                                            Award
                                        </button>
                                    </div>
                                </div>

                                <div v-if="canAdjust" class="col-md-4">
                                    <label class="form-label" for="loyalty-adjust">Adjust (use a minus to take points away)</label>
                                    <div class="input-group input-group-sm mb-1">
                                        <input id="loyalty-adjust" v-model.number="adjustPoints" type="number" step="1" class="form-control" placeholder="Points">
                                        <input v-model="adjustNote" type="text" maxlength="500" class="form-control" placeholder="Reason">
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            :disabled="saving || ! adjustPoints || adjustNote.trim().length < 3"
                                            @click="send('adjust', { contact_id: customer.id, points: adjustPoints, note: adjustNote })"
                                        >
                                            Adjust
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <h6 class="mb-3">History</h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th class="text-end">Points</th>
                                        <th class="text-end">Balance after</th>
                                        <th class="text-end">Amount</th>
                                        <th>By</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="entry in customer.entries ?? []" :key="entry.id">
                                        <td>{{ entry.created_at ? String(entry.created_at).slice(0, 10) : '—' }}</td>
                                        <td>{{ typeLabels[entry.type] ?? entry.type }}</td>
                                        <td class="text-end">{{ entry.points > 0 ? '+' : '' }}{{ entry.points }}</td>
                                        <td class="text-end">{{ entry.balance_after }}</td>
                                        <td class="text-end">{{ entry.amount ?? '—' }}</td>
                                        <td>{{ [entry.user?.first_name, entry.user?.last_name].filter(Boolean).join(' ') || '—' }}</td>
                                        <td>{{ entry.note || '—' }}</td>
                                    </tr>
                                    <tr v-if="! (customer.entries ?? []).length">
                                        <td colspan="7" class="text-center text-muted">No points activity yet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
