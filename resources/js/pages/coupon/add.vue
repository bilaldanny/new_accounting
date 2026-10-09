<script setup lang="ts">
    import { Head, router } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import SimpleFormChrome from '@/components/SimpleFormChrome.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useCoupons from '@/composables/coupon';
    import Fields from './Fields.vue';

    defineOptions({
        layout: {
            title: 'New Coupon',
            subtitle: 'Add a discount code',
            breadcrumbs: [
                { title: 'Coupons', href: '/coupons' },
                { title: 'Add Coupon', href: 'NULL' },
            ],
        },
    });

    const { Notify, handleError, formatedText } = useCommons();
    const { formData, defaultFormData, emptyForm } = useCoupons();

    const formRef = ref<any>(null);
    const isSaving = ref(false);
    const isLeaving = ref(false);
    const pageReady = ref(false);
    const formKey = ref(0);
    const saveAction = ref<'close' | 'add-new'>('close');
    const isWorking = computed(() => isSaving.value || isLeaving.value);
    const isBusy = computed(() => ! pageReady.value || isWorking.value);

    function resetForm() {
        formData.value = { ...emptyForm(), ...defaultFormData.value };
    }

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function submitCoupon(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(API_ENDPOINTS.coupons, { ...form$?.data });

        if (response.data?.errormessage) {
            handleFormError({ response }, { type: 'submit' });

            return response;
        }

        Notify(response.data?.message || 'Successfully Saved', 'success');

        if (saveAction.value === 'add-new') {
            resetForm();
            formKey.value += 1;
            window.scrollTo({ top: 0, behavior: 'smooth' });

            return response;
        }

        isLeaving.value = true;
        await router.visit('/coupons');

        return response;
    }

    function save(action: 'close' | 'add-new') {
        saveAction.value = action;
        formRef.value?.submitForm();
    }

    onMounted(() => {
        resetForm();
        pageReady.value = true;
    });
</script>

<template>
    <Head :title="`New ${formatedText('coupons')}`" />

    <div class="product-form-page purchase-form-page">
        <SimpleFormChrome
            mode="add"
            list-title="Coupons"
            :is-busy="isBusy"
            :is-working="isWorking"
            :save-action="saveAction"
            @cancel="router.visit('/coupons')"
            @save="save"
        >
            <div class="product-form product-form--sectioned purchase-form">
                <Loader v-if="!pageReady" message="Preparing form…" />

                <TheForm
                    v-else
                    v-model:submitting="isSaving"
                    :key="formKey"
                    :onSubmit="submitCoupon"
                    :formData="formData"
                    :error="handleFormError"
                    :url="API_ENDPOINTS.coupons"
                    ref="formRef"
                >
                    <Fields :form-data="formData" :form-ref="formRef" />
                </TheForm>
            </div>
        </SimpleFormChrome>
    </div>
</template>
