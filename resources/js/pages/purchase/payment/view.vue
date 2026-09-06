<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import ModalComponent from '@/components/ModalComponent.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import { nextTick, ref } from 'vue';

    const { handleError } = useCommons();

    const loading = ref(false);
    const purchaseId = ref<number | null>(null);
    const invoiceNo = ref('');
    const payments = ref<Array<{
        id: number;
        paid_on_label?: string;
        paid_on?: string;
        payment_ref_no?: string;
        formatted_amount?: string;
        amount?: number | string;
        method_label?: string;
        note?: string;
    }>>([]);

    function modalInstance() {
        const el = document.getElementById('ViewPaymentModal');

        return el ? (window as any).bootstrap?.Modal.getOrCreateInstance(el) : null;
    }

    async function loadPayments(id: number) {
        loading.value = true;
        payments.value = [];
        invoiceNo.value = '';

        try {
            const [purchaseResponse, paymentsResponse] = await Promise.all([
                window.axios.get(`${API_ENDPOINTS.purchases}/${id}`),
                window.axios.get(API_ENDPOINTS.purchasePayments, {
                    params: { transaction_id: id, show_record: 100 },
                }),
            ]);

            invoiceNo.value = purchaseResponse.data?.invoice_no ?? '';
            payments.value = paymentsResponse.data?.data?.data ?? [];
        } catch (error) {
            handleError(error);
        } finally {
            loading.value = false;
        }
    }

    async function open(id: number) {
        purchaseId.value = id;
        await nextTick();
        modalInstance()?.show();
        await loadPayments(id);
    }

    defineExpose({ open });
</script>

<template>
    <ModalComponent
        id="ViewPaymentModal"
        :title="invoiceNo ? `View Payment ${invoiceNo}` : 'View Payment'"
        size="lg"
    >
        <Loader v-if="loading" message="Loading payments…" />

        <div v-else class="table-responsive">
            <table class="table table-striped table-bordered mb-0">
                <thead>
                    <tr>
                        <th>S.no</th>
                        <th>Date</th>
                        <th>Reference No</th>
                        <th class="text-end">Amount</th>
                        <th>Payment Method</th>
                        <th>Payment Note</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(payment, index) in payments" :key="payment.id">
                        <td>{{ index + 1 }}</td>
                        <td>{{ payment.paid_on_label || payment.paid_on || '—' }}</td>
                        <td>{{ payment.payment_ref_no || '—' }}</td>
                        <td class="text-end">{{ payment.formatted_amount || payment.amount || '—' }}</td>
                        <td class="text-capitalize">{{ payment.method_label || '—' }}</td>
                        <td>{{ payment.note || '—' }}</td>
                    </tr>
                    <tr v-if="! payments.length">
                        <td colspan="6" class="text-center">No Record Found</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <button type="button" class="btn btn-light waves-effect" data-bs-dismiss="modal">Close</button>
        </template>
    </ModalComponent>
</template>
