<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import usePurchaseReturns from '@/composables/purchaseReturn';
    import { Head, router } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Fields from './Fields.vue';
    import PurchaseFormChrome from '../PurchaseFormChrome.vue';

    const pageProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
    });

    defineOptions({
        layout: {
            title: 'Edit Purchase Return',
            subtitle: 'Update returned quantities for this purchase return',
            breadcrumbs: [
                {
                    title: 'Purchase Return',
                    href: '/purchase/return',
                },
                {
                    title: 'Edit Purchase Return',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify, handleError, formatedText } = useCommons();
    const { formData, getEditData } = usePurchaseReturns();

    const formRef = ref<any>(null);
    const isSaving = ref(false);
    const isLeaving = ref(false);
    const pageReady = ref(false);
    const saveAction = ref<'close' | 'add-new'>('close');
    const isWorking = computed(() => isSaving.value || isLeaving.value);
    const isBusy = computed(() => ! pageReady.value || isWorking.value);

    const recordId = computed(() => Number(pageProps.id));
    const endpoint = computed(() => `${API_ENDPOINTS.purchaseReturns}/${recordId.value}`);

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function submitWithLines(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(endpoint.value, {
            ...form$?.data,
            _method: 'PUT',
            purchaselines: formData.value?.purchaselines ?? [],
            final_amount: formData.value?.final_amount ?? 0,
        });

        if (response.data?.errormessage) {
            handleFormError({ response }, { type: 'submit' });

            return response;
        }

        Notify(response.data?.message || 'Successfully Saved', 'success');
        isLeaving.value = true;
        await router.visit(saveAction.value === 'add-new' ? '/purchase/return/add' : '/purchase/return');

        return response;
    }

    function save(action: 'close' | 'add-new') {
        saveAction.value = action;
        formRef.value?.submitForm();
    }

    onMounted(async () => {
        const loaded = await getEditData(recordId.value);

        if (! loaded) {
            router.visit('/purchase/return');

            return;
        }

        pageReady.value = true;
    });
</script>

<template>
    <Head :title="`Edit ${formatedText('purchase.return')}`" />

    <div class="product-form-page purchase-form-page purchase-return-form-page">
        <PurchaseFormChrome
            entity="purchase-return"
            mode="edit"
            :is-busy="isBusy"
            :is-working="isWorking"
            :save-action="saveAction"
            :reference-no="String(formData?.invoice_no || '')"
            @cancel="router.visit('/purchase/return')"
            @save="save"
        >
            <div class="product-form product-form--sectioned purchase-form purchase-return-form">
                <Loader v-if="!pageReady" message="Loading purchase return…" />

                <TheForm
                    v-else
                    v-model:submitting="isSaving"
                    :key="endpoint"
                    :onSubmit="submitWithLines"
                    :formData="formData"
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
            </div>
        </PurchaseFormChrome>
    </div>
</template>
