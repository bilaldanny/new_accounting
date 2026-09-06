<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import ModalComponent from '@/components/ModalComponent.vue';
    import useCommons from '@/composables/common';
    import { nextTick, ref } from 'vue';

    const emit = defineEmits<{
        saved: [];
    }>();

    const { Notify, handleError } = useCommons();

    const loading = ref(false);
    const saving = ref(false);
    const sellId = ref<number | null>(null);
    const invoiceNo = ref('');
    const form = ref({
        shipping_details: '',
        shipping_address: '',
        shipping_status: 'ordered',
        delivered_to: '',
        shipping_note: '',
    });

    function modalInstance() {
        const el = document.getElementById('ViewShippingModal');

        return el ? (window as any).bootstrap?.Modal.getOrCreateInstance(el) : null;
    }

    async function loadSell(id: number) {
        loading.value = true;

        try {
            const response = await window.axios.get(`/api/sells/${id}`);
            const sell = response.data ?? {};
            invoiceNo.value = sell.invoice_no ?? '';
            form.value = {
                shipping_details: sell.shipping_details ?? '',
                shipping_address: sell.shipping_address ?? '',
                shipping_status: sell.shipping_status || 'ordered',
                delivered_to: sell.delivered_to ?? '',
                shipping_note: sell.shipping_note ?? '',
            };
        } catch (error) {
            handleError(error);
        } finally {
            loading.value = false;
        }
    }

    async function open(id: number) {
        sellId.value = id;
        await nextTick();
        modalInstance()?.show();
        await loadSell(id);
    }

    async function save() {
        if (! sellId.value || saving.value) {
            return;
        }

        saving.value = true;

        try {
            await window.axios.put(`/api/sells/${sellId.value}/shipping`, form.value);
            Notify('Successfully Saved');
            modalInstance()?.hide();
            emit('saved');
        } catch (error) {
            handleError(error);
        } finally {
            saving.value = false;
        }
    }

    defineExpose({ open });
</script>

<template>
    <ModalComponent
        id="ViewShippingModal"
        :title="invoiceNo ? `Edit Shipping ${invoiceNo}` : 'Edit Shipping'"
        size="lg"
    >
        <Loader v-if="loading" message="Loading shipping…" />

        <div v-else class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="shipping-details">Shipping Detail</label>
                <textarea id="shipping-details" class="form-control form-control-sm" rows="3" v-model="form.shipping_details"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="shipping-address">Shipping Address</label>
                <textarea id="shipping-address" class="form-control form-control-sm" rows="3" v-model="form.shipping_address"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="shipping-status">Delivery Status</label>
                <select id="shipping-status" class="form-select form-select-sm" v-model="form.shipping_status">
                    <option value="ordered">Ordered</option>
                    <option value="packed">Packed</option>
                    <option value="shipped">Shipped</option>
                    <option value="delivered">Delivered</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="delivered-to">Delivered To</label>
                <input id="delivered-to" type="text" class="form-control form-control-sm" v-model="form.delivered_to">
            </div>
            <div class="col-md-12">
                <label class="form-label" for="shipping-note">Shipping Note</label>
                <textarea id="shipping-note" class="form-control form-control-sm" rows="3" v-model="form.shipping_note"></textarea>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-light waves-effect" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-primary" :disabled="loading || saving" @click="save">
                {{ saving ? 'Saving…' : 'Save' }}
            </button>
        </template>
    </ModalComponent>
</template>
