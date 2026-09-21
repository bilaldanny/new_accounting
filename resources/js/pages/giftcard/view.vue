<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, CalendarDays, Gift, Pencil, User } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Gift Card',
            subtitle: 'Gift card balance and history',
            breadcrumbs: [
                {
                    title: 'Gift Card',
                    href: '/giftcard',
                },
                {
                    title: 'View Gift Card',
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

    type GiftCardEntry = {
        id: number;
        type: 'issue' | 'redeem' | 'topup';
        amount: string | number;
        balance_after: string | number;
        note?: string | null;
        created_at?: string;
        user?: { first_name?: string; last_name?: string } | null;
    };

    type GiftCardDetail = {
        id?: number;
        code?: string;
        initial_value?: string | number;
        balance?: string | number;
        expires_at?: string | null;
        note?: string | null;
        is_active?: boolean | number;
        company?: { name?: string } | null;
        contact_name?: string | null;
        entries?: GiftCardEntry[];
    };

    const loading = ref(true);
    const saving = ref(false);
    const giftcard = ref<GiftCardDetail>({});
    const amount = ref<number | null>(null);
    const note = ref('');

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const canEdit = computed(() => permissionPaths.value.includes('/giftcard/:id/edit'));
    const canRedeem = computed(() => permissionPaths.value.includes('/giftcard/redeem'));
    const canTopUp = computed(() => permissionPaths.value.includes('/giftcard/topup'));

    const typeLabels: Record<string, string> = { issue: 'Issued', redeem: 'Redeemed', topup: 'Topped up' };

    async function loadGiftCard() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.giftCards}/${routeProps.id}`);
            giftcard.value = response.data;
        } catch {
            Notify('Unable to load gift card details.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function moveMoney(action: 'redeem' | 'topup') {
        if (! amount.value || amount.value <= 0) {
            Notify('Enter an amount above zero.', 'alert');

            return;
        }

        saving.value = true;

        try {
            const response = await window.axios.post(`${API_ENDPOINTS.giftCards}/${routeProps.id}/${action}`, {
                amount: amount.value,
                note: note.value || null,
            });
            Notify(response.data?.message || 'Successfully Saved', 'success');
            amount.value = null;
            note.value = '';
            await loadGiftCard();
        } catch (error: unknown) {
            const message = window.axios.isAxiosError(error)
                ? (error.response?.data?.message || 'The request failed.')
                : 'Unexpected error occurred';
            Notify(message, 'alert');
        } finally {
            saving.value = false;
        }
    }

    onMounted(loadGiftCard);
</script>

<template>
    <Head title="View Gift Card" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/giftcard" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to gift cards
                </Link>

                <Link
                    v-if="canEdit && giftcard.id"
                    :href="`/giftcard/${giftcard.id}/edit`"
                    class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                >
                    <Pencil class="h-4 w-4" />
                    Edit
                </Link>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading gift card details…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ giftcard.company?.name || '—' }}</p>
                                <h4 class="mb-0">{{ giftcard.code || 'Gift Card' }}</h4>
                            </div>
                            <span
                                class="badge"
                                :class="giftcard.is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'"
                            >
                                {{ giftcard.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <Gift class="h-4 w-4" />
                                Balance
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Balance</dt>
                                <dd class="col-7 fw-semibold">{{ giftcard.balance ?? '—' }}</dd>
                                <dt class="col-5 text-muted">Issued value</dt>
                                <dd class="col-7">{{ giftcard.initial_value ?? '—' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <User class="h-4 w-4" />
                                Holder
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Customer</dt>
                                <dd class="col-7">{{ giftcard.contact_name || 'Bearer card' }}</dd>
                                <dt class="col-5 text-muted">Note</dt>
                                <dd class="col-7">{{ giftcard.note || '—' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <CalendarDays class="h-4 w-4" />
                                Validity
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Expires on</dt>
                                <dd class="col-7">{{ giftcard.expires_at || 'No expiry' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div v-if="canRedeem || canTopUp" class="col-12">
                        <div class="border rounded p-3">
                            <h6 class="mb-3">Move money</h6>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label" for="giftcard-amount">Amount</label>
                                    <input id="giftcard-amount" v-model.number="amount" type="number" min="0" step="0.01" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="giftcard-note">Note</label>
                                    <input id="giftcard-note" v-model="note" type="text" maxlength="500" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4 d-flex gap-2">
                                    <button v-if="canRedeem" type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="moveMoney('redeem')">
                                        Redeem
                                    </button>
                                    <button v-if="canTopUp" type="button" class="btn btn-outline-primary btn-sm" :disabled="saving" @click="moveMoney('topup')">
                                        Top up
                                    </button>
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
                                        <th class="text-end">Amount</th>
                                        <th class="text-end">Balance after</th>
                                        <th>By</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="entry in giftcard.entries ?? []" :key="entry.id">
                                        <td>{{ entry.created_at ? String(entry.created_at).slice(0, 10) : '—' }}</td>
                                        <td>{{ typeLabels[entry.type] ?? entry.type }}</td>
                                        <td class="text-end">{{ entry.type === 'redeem' ? '-' : '+' }}{{ entry.amount }}</td>
                                        <td class="text-end">{{ entry.balance_after }}</td>
                                        <td>{{ [entry.user?.first_name, entry.user?.last_name].filter(Boolean).join(' ') || '—' }}</td>
                                        <td>{{ entry.note || '—' }}</td>
                                    </tr>
                                    <tr v-if="! (giftcard.entries ?? []).length">
                                        <td colspan="6" class="text-center text-muted">No entries yet.</td>
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
