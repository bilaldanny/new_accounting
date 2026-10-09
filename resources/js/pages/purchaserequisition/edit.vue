<script setup lang="ts">
    import { Head, router } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import usePurchaseRequisitions from '@/composables/purchaseRequisition';
    import Fields from './Fields.vue';
    import PurchaseRequisitionFormChrome from './PurchaseRequisitionFormChrome.vue';

    const pageProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
    });

    defineOptions({
        layout: {
            title: 'Edit Purchase Requisition',
            subtitle: 'Update the requested products, quantities or note',
            breadcrumbs: [
                {
                    title: 'Purchase Requisition',
                    href: '/purchaserequisition',
                },
                {
                    title: 'Edit Purchase Requisition',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify, handleError, formatedText } = useCommons();
    const { formData, getEditData } = usePurchaseRequisitions();

    const formRef = ref<any>(null);
    const isSaving = ref(false);
    const isLeaving = ref(false);
    const pageReady = ref(false);
    const saveAction = ref<'close' | 'add-new'>('close');
    const isWorking = computed(() => isSaving.value || isLeaving.value);
    const isBusy = computed(() => ! pageReady.value || isWorking.value);
    const recordId = computed(() => Number(pageProps.id));
    const endpoint = computed(() => `${API_ENDPOINTS.purchaseRequisitions}/${recordId.value}`);

    const isReady = computed(() => Array.isArray(formData.value?.lines) && formData.value.lines.length > 0);

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function submitRequisition(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(endpoint.value, {
            ...form$?.data,
            lines: formData.value?.lines ?? [],
        });

        if (response.data?.errormessage) {
            handleFormError({ response }, { type: 'submit' });

            return response;
        }

        Notify(response.data?.message || 'Successfully Saved', 'success');
        isLeaving.value = true;
        await router.visit(saveAction.value === 'add-new' ? '/purchaserequisition/add' : '/purchaserequisition');

        return response;
    }

    function save(action: 'close' | 'add-new') {
        saveAction.value = action;
        formRef.value?.submitForm();
    }

    onMounted(async () => {
        const loaded = await getEditData(recordId.value);

        if (! loaded) {
            router.visit('/purchaserequisition');

            return;
        }

        if (formData.value?.is_editable === false) {
            Notify('Only a draft, pending or rejected requisition can be edited.', 'alert');
            router.visit(`/purchaserequisition/${recordId.value}/view`);

            return;
        }

        pageReady.value = true;
    });
</script>

<template>
    <Head :title="`Edit ${formatedText('purchaserequisition')}`" />

    <div class="product-form-page journal-form-page">
        <PurchaseRequisitionFormChrome
            mode="edit"
            :is-busy="isBusy"
            :is-working="isWorking"
            :save-action="saveAction"
            :save-disabled="!isReady"
            :requisition-no="String(formData?.requisition_no ?? '')"
            @cancel="router.visit('/purchaserequisition')"
            @save="save"
        >
            <div class="product-form product-form--sectioned">
                <Loader v-if="!pageReady" message="Loading requisition…" />

                <TheForm
                    v-else
                    v-model:submitting="isSaving"
                    :key="endpoint"
                    :onSubmit="submitRequisition"
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
            </div>
        </PurchaseRequisitionFormChrome>
    </div>
</template>
