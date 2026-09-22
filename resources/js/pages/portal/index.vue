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

    const loading = ref(true);
    const failed = ref('');
    const name = ref('');
    const sections = ref<Section[]>([]);

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
    }

    function signOut() {
        router.post('/logout');
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
            <button type="button" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1" @click="signOut"><LogOut class="h-4 w-4" /> Sign out</button>
        </div>

        <p v-if="loading" class="text-muted">Loading…</p>
        <div v-else-if="failed" class="alert alert-warning">{{ failed }}</div>

        <template v-else>
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
