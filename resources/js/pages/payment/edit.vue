<script setup lang="ts">
    import { Head, router } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import usePayments from '@/composables/payment';
    import Fields from './Fields.vue';

    const pageProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
    });

    defineOptions({
        layout: {
            title: 'Edit Payment',
            subtitle: 'Update payment date, narration, and account lines',
            breadcrumbs: [
                {
                    title: 'Payment',
                    href: '/acpayment',
                },
                {
                    title: 'Edit Payment',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify, handleError, formatedText } = useCommons();
    const { formData, getEditData } = usePayments();

    const formRef = ref<any>(null);
    const isSaving = ref(false);
    const isLeaving = ref(false);
    const pageReady = ref(false);
    const saveAction = ref<'close' | 'add-new'>('close');
    const isWorking = computed(() => isSaving.value || isLeaving.value);
    const isBusy = computed(() => ! pageReady.value || isWorking.value);
    const recordId = computed(() => Number(pageProps.id));
    const endpoint = computed(() => `${API_ENDPOINTS.payments}/${recordId.value}`);

    const isBalanced = computed(() => {
        const lines = Array.isArray(formData.value?.taccountdetails) ? formData.value.taccountdetails : [];
        const debit = lines.reduce((sum, line) => sum + Number(line.debit || 0), 0);
        const credit = lines.reduce((sum, line) => sum + Number(line.credit || 0), 0);

        return lines.length > 0 && Math.round(debit * 100) === Math.round(credit * 100);
    });

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function submitPayment(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(endpoint.value, {
            ...form$?.data,
            taccountdetails: formData.value?.taccountdetails ?? [],
            attachments: formData.value?.attachments ?? [],
            voucher_type: formData.value?.voucher_type ?? 'BP',
            comments: formData.value?.comments ?? '',
            cheque_no: formData.value?.cheque_no ?? '',
            total_amount: formData.value?.total_amount ?? 0,
        });

        if (response.data?.errormessage) {
            handleFormError({ response }, { type: 'submit' });

            return response;
        }

        Notify(response.data?.message || 'Successfully Saved', 'success');
        isLeaving.value = true;
        await router.visit(saveAction.value === 'add-new' ? '/acpayment/add' : '/acpayment');

        return response;
    }

    function save(action: 'close' | 'add-new') {
        saveAction.value = action;
        formRef.value?.submitForm();
    }

    onMounted(async () => {
        const loaded = await getEditData(recordId.value);

        if (! loaded) {
            router.visit('/acpayment');

            return;
        }

        pageReady.value = true;
    });
</script>

<template>
    <Head :title="`Edit ${formatedText('payment')}`" />

    <div class="product-form-page purchase-form-page journal-form-page">
        <div class="product-form purchase-form">
            <Loader v-if="!pageReady" message="Loading payment…" />

            <TheForm
                v-else
                v-model:submitting="isSaving"
                :key="endpoint"
                :onSubmit="submitPayment"
                :formData="formData"
                :show-required="[]"
                :error="handleFormError"
                :url="endpoint"
                ref="formRef"
            >
                <Fields
                    type="edit"
                    :record-id="recordId"
                    :form-data="formData"
                    :form-ref="formRef"
                />
            </TheForm>

            <div class="product-form-page__footer">
                <p class="journal-form-page__status mb-0" :class="{ 'is-ok': isBalanced }">
                    {{ isBalanced ? 'Payment is balanced and ready to save' : 'Add matching debit and credit lines to save' }}
                </p>
                <div class="product-form-page__actions">
                    <button
                        type="button"
                        class="btn btn-light"
                        :disabled="isBusy"
                        @click="router.visit('/acpayment')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn btn-outline-primary d-inline-flex align-items-center"
                        :disabled="isBusy || !isBalanced"
                        :aria-busy="isWorking && saveAction === 'add-new'"
                        @click="save('add-new')"
                    >
                        <span
                            v-if="isWorking && saveAction === 'add-new'"
                            class="spinner-border spinner-border-sm me-1"
                            role="status"
                            aria-hidden="true"
                        ></span>
                        {{ isWorking && saveAction === 'add-new' ? 'Saving…' : 'Save & Add New' }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary d-inline-flex align-items-center"
                        :disabled="isBusy || !isBalanced"
                        :aria-busy="isWorking && saveAction === 'close'"
                        @click="save('close')"
                    >
                        <span
                            v-if="isWorking && saveAction === 'close'"
                            class="spinner-border spinner-border-sm me-1"
                            role="status"
                            aria-hidden="true"
                        ></span>
                        {{ isWorking && saveAction === 'close' ? 'Saving…' : 'Save & Close' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
