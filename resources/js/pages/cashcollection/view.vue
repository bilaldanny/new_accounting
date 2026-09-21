<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, Banknote, Pencil } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Cash Collection',
            subtitle: 'Collected cash, its invoices and status',
            breadcrumbs: [
                {
                    title: 'Cash Collection',
                    href: '/cashcollection',
                },
                {
                    title: 'View Cash Collection',
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

    type Allocation = {
        id: number;
        kind?: 'collected' | 'advance';
        invoice_no?: string | null;
        payment_ref_no?: string | null;
        amount: number;
    };

    type Detail = {
        id?: number;
        reference?: string;
        status?: 'pending' | 'completed' | 'cancelled';
        collected_on?: string;
        amount?: number;
        note?: string | null;
        advance_amount?: number;
        advance_remaining?: number;
        enabled?: boolean;
        reversed_at?: string | null;
        contact_id?: number;
        company_id?: number;
        company_name?: string | null;
        branch_name?: string | null;
        contact_name?: string | null;
        collector_name?: string | null;
        allocations?: Allocation[];
    };

    type OpenInvoice = {
        id: number;
        invoice_no: string | null;
        transaction_date: string | null;
        final_amount: number;
        remaining: number;
    };

    const loading = ref(true);
    const saving = ref(false);
    const collection = ref<Detail>({});
    const invoices = ref<OpenInvoice[]>([]);
    const accounts = ref<Array<{ id: number; text?: string; name?: string }>>([]);
    const amounts = ref<Record<number, number | null>>({});
    const paymentAccount = ref<number | ''>('');
    const keepAdvance = ref(false);
    const advanceAmounts = ref<Record<number, number | null>>({});

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const isPending = computed(() => collection.value.status === 'pending');
    const canEdit = computed(() => isPending.value && permissionPaths.value.includes('/cashcollection/:id/edit'));
    const canComplete = computed(() => isPending.value && permissionPaths.value.includes('/cashcollection/complete'));
    const canCancel = computed(() => isPending.value && permissionPaths.value.includes('/cashcollection/cancel'));
    const isCompleted = computed(() => collection.value.status === 'completed');
    const canReverse = computed(() => isCompleted.value && permissionPaths.value.includes('/cashcollection/reverse'));
    const advanceLeft = computed(() => Number(collection.value.advance_remaining ?? 0));
    const canUseAdvance = computed(() => isCompleted.value && advanceLeft.value > 0 && permissionPaths.value.includes('/cashcollection/advance'));
    const switchedOff = computed(() => collection.value.enabled === false);
    const collectedAllocations = computed(() => (collection.value.allocations ?? []).filter((allocation) => allocation.kind !== 'advance'));
    const advanceAllocations = computed(() => (collection.value.allocations ?? []).filter((allocation) => allocation.kind === 'advance'));

    const allocatedTotal = computed(() => Math.round(Object.values(amounts.value).reduce<number>((sum, value) => sum + (Number(value) || 0), 0) * 100) / 100);
    const remainingToAllocate = computed(() => Math.round(((collection.value.amount ?? 0) - allocatedTotal.value) * 100) / 100);
    // the invoices may take all of it, or less with the rest kept as an advance (never more)
    const canSubmit = computed(() => paymentAccount.value !== '' && ! switchedOff.value && remainingToAllocate.value >= 0
        && (remainingToAllocate.value === 0 ? allocatedTotal.value > 0 : keepAdvance.value));
    const advanceAllocatedTotal = computed(() => Math.round(Object.values(advanceAmounts.value).reduce<number>((sum, value) => sum + (Number(value) || 0), 0) * 100) / 100);
    const canApplyAdvance = computed(() => advanceAllocatedTotal.value > 0 && advanceAllocatedTotal.value <= advanceLeft.value);

    function errorMessage(error: unknown): string {
        if (! window.axios.isAxiosError(error)) {
            return 'Unexpected error occurred';
        }

        const errors = Object.values(error.response?.data?.errors ?? {}).flat() as string[];

        return errors[0] || error.response?.data?.message || error.response?.data?.errormessage || 'The request failed.';
    }

    function autoAllocate() {
        let left = collection.value.amount ?? 0;
        const next: Record<number, number | null> = {};

        for (const invoice of invoices.value) {
            const part = Math.min(left, invoice.remaining);

            next[invoice.id] = part > 0 ? Math.round(part * 100) / 100 : null;
            left = Math.round((left - part) * 100) / 100;
        }

        amounts.value = next;
    }

    async function loadPayables() {
        if (! (canComplete.value || canUseAdvance.value) || ! collection.value.contact_id) {
            return;
        }

        try {
            const [invoiceResponse, accountResponse] = await Promise.all([
                window.axios.get(API_ENDPOINTS.cashCollectionOpenInvoices, { params: { contact_id: collection.value.contact_id, company_id: collection.value.company_id } }),
                window.axios.get(API_ENDPOINTS.sellPaymentAccounts, { params: { company_id: collection.value.company_id } }),
            ]);
            invoices.value = invoiceResponse.data;
            accounts.value = accountResponse.data;
            keepAdvance.value = false;
            advanceAmounts.value = {};

            if (canComplete.value) {
                autoAllocate();
            }
        } catch {
            Notify('Unable to load the customer\'s open invoices.', 'alert');
        }
    }

    async function load() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.cashCollections}/${routeProps.id}`);
            collection.value = response.data;
            await loadPayables();
        } catch {
            Notify('Unable to load the cash collection.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function complete() {
        saving.value = true;

        try {
            const allocations = invoices.value
                .filter((invoice) => Number(amounts.value[invoice.id]) > 0)
                .map((invoice) => ({ transaction_id: invoice.id, amount: Number(amounts.value[invoice.id]) }));

            const response = await window.axios.post(`${API_ENDPOINTS.cashCollections}/${routeProps.id}/complete`, {
                payment_account: paymentAccount.value,
                keep_advance: keepAdvance.value,
                allocations,
            });
            Notify(response.data?.message || 'Successfully Saved', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            saving.value = false;
        }
    }

    async function applyAdvance() {
        saving.value = true;

        try {
            const allocations = invoices.value
                .filter((invoice) => Number(advanceAmounts.value[invoice.id]) > 0)
                .map((invoice) => ({ transaction_id: invoice.id, amount: Number(advanceAmounts.value[invoice.id]) }));

            const response = await window.axios.post(`${API_ENDPOINTS.cashCollections}/${routeProps.id}/apply-advance`, { allocations });
            Notify(response.data?.message || 'Successfully Saved', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            saving.value = false;
        }
    }

    async function reverseCollection() {
        if (! window.confirm('Reverse this collection? Every payment it made, and any invoice settled from its advance, is removed and the collection goes back to pending.')) {
            return;
        }

        const note = window.prompt('Why is it being reversed? (optional)');

        if (note === null) {
            return;
        }

        saving.value = true;

        try {
            await window.axios.post(`${API_ENDPOINTS.cashCollections}/${routeProps.id}/reverse`, { note: note.trim() || undefined });
            Notify('Collection reversed', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            saving.value = false;
        }
    }

    async function cancelCollection() {
        if (! window.confirm('Cancel this collection? Nothing has been posted for it.')) {
            return;
        }

        saving.value = true;

        try {
            await window.axios.post(`${API_ENDPOINTS.cashCollections}/${routeProps.id}/cancel`);
            Notify('Collection cancelled', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            saving.value = false;
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="View Cash Collection" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/cashcollection" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to cash collections
                </Link>

                <div class="d-flex gap-2">
                    <Link
                        v-if="canEdit && collection.id"
                        :href="`/cashcollection/${collection.id}/edit`"
                        class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1"
                    >
                        <Pencil class="h-4 w-4" />
                        Edit
                    </Link>
                    <button v-if="canCancel" type="button" class="btn btn-outline-danger btn-sm" :disabled="saving" @click="cancelCollection">
                        Cancel collection
                    </button>
                    <button v-if="canReverse" type="button" class="btn btn-outline-danger btn-sm" :disabled="saving" @click="reverseCollection">
                        Reverse collection
                    </button>
                </div>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading cash collection…" />

                <div v-else class="row g-4">
                    <div v-if="switchedOff" class="col-12">
                        <div class="alert alert-warning mb-0">
                            Cash collection is switched off for this company, so this collection cannot be completed or its advance used. Switch it on in Company Settings.
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ collection.company_name || '—' }} · {{ collection.branch_name || '—' }}</p>
                                <h4 class="mb-0 d-flex align-items-center gap-2">
                                    <Banknote class="h-5 w-5" />
                                    {{ collection.reference }}
                                </h4>
                            </div>
                            <span
                                class="badge"
                                :class="{
                                    'bg-success-subtle text-success': collection.status === 'completed',
                                    'bg-warning-subtle text-warning': collection.status === 'pending',
                                    'bg-secondary-subtle text-secondary': collection.status === 'cancelled',
                                }"
                            >
                                {{ collection.status }}
                            </span>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Customer</dt>
                                <dd class="col-7">{{ collection.contact_name || '—' }}</dd>
                                <dt class="col-5 text-muted">Amount</dt>
                                <dd class="col-7 fw-semibold">{{ collection.amount }}</dd>
                                <dt v-if="isCompleted && (collection.advance_amount ?? 0) > 0" class="col-5 text-muted">Advance kept</dt>
                                <dd v-if="isCompleted && (collection.advance_amount ?? 0) > 0" class="col-7">{{ collection.advance_amount }} (left: {{ advanceLeft }})</dd>
                                <dt v-if="collection.reversed_at" class="col-5 text-muted">Reversed</dt>
                                <dd v-if="collection.reversed_at" class="col-7">{{ String(collection.reversed_at).slice(0, 10) }}</dd>
                                <dt class="col-5 text-muted">Collected on</dt>
                                <dd class="col-7">{{ collection.collected_on }}</dd>
                                <dt class="col-5 text-muted">Collected by</dt>
                                <dd class="col-7">{{ collection.collector_name || '—' }}</dd>
                                <dt class="col-5 text-muted">Note</dt>
                                <dd class="col-7">{{ collection.note || '—' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div v-if="collection.status === 'completed'" class="col-12">
                        <h6 class="mb-3">Invoices paid</h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Payment</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="allocation in collectedAllocations" :key="allocation.id">
                                        <td>{{ allocation.invoice_no || '—' }}</td>
                                        <td>{{ allocation.payment_ref_no || '—' }}</td>
                                        <td class="text-end">{{ allocation.amount }}</td>
                                    </tr>
                                    <tr v-for="allocation in advanceAllocations" :key="`advance-${allocation.id}`">
                                        <td>{{ allocation.invoice_no || '—' }} <span class="badge bg-info-subtle text-info">from advance</span></td>
                                        <td>{{ allocation.payment_ref_no || '—' }}</td>
                                        <td class="text-end">{{ allocation.amount }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="canUseAdvance" class="col-12">
                        <div class="border rounded p-3">
                            <h6 class="mb-1">Use the advance ({{ advanceLeft }} left)</h6>
                            <p class="text-muted small">Settle open invoices of this customer out of the advance kept from this collection. No cash moves.</p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Invoice</th>
                                            <th class="text-end">Still owed</th>
                                            <th class="text-end" style="width: 9rem">Use advance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="invoice in invoices" :key="`use-${invoice.id}`">
                                            <td>{{ invoice.invoice_no || `#${invoice.id}` }}</td>
                                            <td class="text-end">{{ invoice.remaining }}</td>
                                            <td class="text-end">
                                                <input
                                                    v-model.number="advanceAmounts[invoice.id]"
                                                    type="number"
                                                    min="0"
                                                    :max="Math.min(invoice.remaining, advanceLeft)"
                                                    step="0.01"
                                                    class="form-control form-control-sm text-end"
                                                    :aria-label="`Advance to use on ${invoice.invoice_no ?? invoice.id}`"
                                                >
                                            </td>
                                        </tr>
                                        <tr v-if="! invoices.length">
                                            <td colspan="3" class="text-center text-muted">This customer has no open invoices.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" :disabled="saving || ! canApplyAdvance" @click="applyAdvance">Use advance</button>
                        </div>
                    </div>

                    <div v-if="canComplete" class="col-12">
                        <div class="border rounded p-3">
                            <h6 class="mb-3">Complete this collection</h6>
                            <p class="text-muted small">
                                Spread {{ collection.amount }} over the customer's open invoices. Completing posts a cash receipt against each one.
                            </p>

                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="cashcollection-account">Cash / bank account</label>
                                    <select id="cashcollection-account" v-model="paymentAccount" class="form-select form-select-sm">
                                        <option value="">Select account</option>
                                        <option v-for="account in accounts" :key="account.id" :value="account.id">
                                            {{ account.text ?? account.name }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-8 d-flex align-items-end gap-3">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="autoAllocate">Allocate oldest first</button>
                                    <span :class="remainingToAllocate === 0 ? 'text-success' : (remainingToAllocate > 0 && keepAdvance ? 'text-info' : 'text-danger')">
                                        Left to allocate: {{ remainingToAllocate }}
                                    </span>
                                </div>
                            </div>

                            <div v-if="remainingToAllocate > 0" class="form-check mb-3">
                                <input id="cashcollection-advance" v-model="keepAdvance" class="form-check-input" type="checkbox">
                                <label class="form-check-label" for="cashcollection-advance">
                                    Keep the remaining {{ remainingToAllocate }} as an advance for this customer (it can settle their later invoices)
                                </label>
                            </div>
                            <p v-if="remainingToAllocate < 0" class="text-danger small">The invoices add up to more than was collected.</p>

                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Invoice</th>
                                            <th>Date</th>
                                            <th class="text-end">Invoice total</th>
                                            <th class="text-end">Still owed</th>
                                            <th class="text-end" style="width: 9rem">Pay now</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="invoice in invoices" :key="invoice.id">
                                            <td>{{ invoice.invoice_no || `#${invoice.id}` }}</td>
                                            <td>{{ invoice.transaction_date || '—' }}</td>
                                            <td class="text-end">{{ invoice.final_amount }}</td>
                                            <td class="text-end">{{ invoice.remaining }}</td>
                                            <td class="text-end">
                                                <input
                                                    v-model.number="amounts[invoice.id]"
                                                    type="number"
                                                    min="0"
                                                    :max="invoice.remaining"
                                                    step="0.01"
                                                    class="form-control form-control-sm text-end"
                                                    :aria-label="`Amount to pay on ${invoice.invoice_no ?? invoice.id}`"
                                                >
                                            </td>
                                        </tr>
                                        <tr v-if="! invoices.length">
                                            <td colspan="5" class="text-center text-muted">This customer has no open invoices.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <button type="button" class="btn btn-primary btn-sm" :disabled="saving || ! canSubmit" @click="complete">
                                Complete collection
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
