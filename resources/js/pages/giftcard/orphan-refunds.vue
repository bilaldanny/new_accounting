<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Orphaned Refunds',
            subtitle: 'Gift card money owed to customers whose card no longer exists',
            breadcrumbs: [
                {
                    title: 'Gift Card',
                    href: '/giftcard',
                },
                {
                    title: 'Orphaned Refunds',
                    href: 'NULL',
                },
            ],
        },
    });

    const page = usePage();
    const { Notify } = useCommons();

    type OrphanRefund = {
        id: number;
        company_name?: string | null;
        gift_card_code: string;
        gift_card_id?: number | null;
        transaction_id?: number | null;
        invoice_no?: string | null;
        amount: number;
        reason?: string | null;
        resolved_at?: string | null;
        resolved_note?: string | null;
        created_at?: string | null;
    };

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        permission_paths?: string[];
    } | null);

    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const canResolve = computed(() => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes('/giftcard/topup'));

    const loading = ref(true);
    const saving = ref(false);
    const showAll = ref(false);
    const rows = ref<OrphanRefund[]>([]);
    const openTotal = ref(0);
    const resolvingId = ref<number | null>(null);
    const note = ref('');

    const formatDate = (value?: string | null) => (value ? String(value).slice(0, 10) : '—');
    const formatAmount = (value: number) => Number(value).toFixed(2);

    async function loadRefunds() {
        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.giftCardOrphanRefunds, {
                params: { status: showAll.value ? 'all' : 'open' },
            });
            rows.value = response.data?.data ?? [];
            openTotal.value = Number(response.data?.open_total ?? 0);
        } catch {
            Notify('Unable to load the orphaned refunds.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    function startResolving(row: OrphanRefund) {
        resolvingId.value = row.id;
        note.value = '';
    }

    function cancelResolving() {
        resolvingId.value = null;
        note.value = '';
    }

    async function resolveRefund(row: OrphanRefund) {
        if (note.value.trim().length < 3) {
            Notify('Write a short note on how it was settled (at least 3 characters).', 'alert');

            return;
        }

        saving.value = true;

        try {
            const response = await window.axios.post(`${API_ENDPOINTS.giftCardOrphanRefunds}/${row.id}/resolve`, { note: note.value.trim() });
            Notify(response.data?.message || 'Successfully Saved', 'success');
            cancelResolving();
            await loadRefunds();
        } catch (error: unknown) {
            const message = window.axios.isAxiosError(error)
                ? (error.response?.data?.errors?.note?.[0] || error.response?.data?.message || 'The request failed.')
                : 'Unexpected error occurred';
            Notify(message, 'alert');
            await loadRefunds();
        } finally {
            saving.value = false;
        }
    }

    function toggleShowAll() {
        showAll.value = ! showAll.value;
        loadRefunds();
    }

    onMounted(loadRefunds);
</script>

<template>
    <Head title="Orphaned Refunds" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/giftcard" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to gift cards
                </Link>

                <div class="form-check form-switch mb-0">
                    <input id="orphan-show-all" class="form-check-input" type="checkbox" role="switch" :checked="showAll" @change="toggleShowAll">
                    <label class="form-check-label" for="orphan-show-all">Show resolved too</label>
                </div>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <p class="text-muted">
                    When a sale paid with a gift card is deleted or returned, the money goes back on the card. If the card
                    itself was deleted for good there is nowhere to put it back, so the refund is listed here to be paid to the
                    customer by hand. A negative amount is a restored sale taking back what was listed.
                </p>

                <Loader v-if="loading" message="Loading orphaned refunds…" />

                <template v-else>
                    <div class="border rounded p-3 mb-3 d-flex flex-wrap justify-content-between gap-2">
                        <span class="text-muted">Still owed to customers</span>
                        <strong>{{ formatAmount(openTotal) }}</strong>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th v-if="isSuperadmin">Company</th>
                                    <th>Sale</th>
                                    <th>Gift card</th>
                                    <th class="text-end">Amount</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th v-if="canResolve" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="row in rows" :key="row.id">
                                    <tr>
                                        <td>{{ formatDate(row.created_at) }}</td>
                                        <td v-if="isSuperadmin">{{ row.company_name || '—' }}</td>
                                        <td>
                                            <span v-if="row.transaction_id">{{ row.invoice_no || `#${row.transaction_id}` }}</span>
                                            <span v-else>—</span>
                                        </td>
                                        <td>
                                            {{ row.gift_card_code }}
                                            <Link v-if="row.gift_card_id" :href="`/giftcard/${row.gift_card_id}/view`" class="ms-1 small">
                                                (a new card has this code)
                                            </Link>
                                        </td>
                                        <td class="text-end fw-semibold">{{ formatAmount(row.amount) }}</td>
                                        <td>{{ row.reason || '—' }}</td>
                                        <td>
                                            <span
                                                class="badge"
                                                :class="row.resolved_at ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'"
                                            >
                                                {{ row.resolved_at ? 'Resolved' : 'Open' }}
                                            </span>
                                            <div v-if="row.resolved_at" class="small text-muted">
                                                {{ formatDate(row.resolved_at) }}: {{ row.resolved_note }}
                                            </div>
                                        </td>
                                        <td v-if="canResolve" class="text-end">
                                            <button
                                                v-if="! row.resolved_at && resolvingId !== row.id"
                                                type="button"
                                                class="btn btn-outline-primary btn-sm"
                                                @click="startResolving(row)"
                                            >
                                                Resolve
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="resolvingId === row.id">
                                        <td :colspan="isSuperadmin ? 8 : 7">
                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-8">
                                                    <label class="form-label" :for="`orphan-note-${row.id}`">How was it settled?</label>
                                                    <input
                                                        :id="`orphan-note-${row.id}`"
                                                        v-model="note"
                                                        type="text"
                                                        maxlength="500"
                                                        class="form-control form-control-sm"
                                                        placeholder="e.g. Paid back in cash on 22 Sep"
                                                        @keyup.enter="resolveRefund(row)"
                                                    >
                                                </div>
                                                <div class="col-md-4 d-flex gap-2">
                                                    <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="resolveRefund(row)">
                                                        Mark as resolved
                                                    </button>
                                                    <button type="button" class="btn btn-light btn-sm" :disabled="saving" @click="cancelResolving">
                                                        Cancel
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="! rows.length">
                                    <td :colspan="isSuperadmin ? 8 : 7" class="text-center text-muted">
                                        {{ showAll ? 'No orphaned refunds.' : 'No open orphaned refunds.' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
