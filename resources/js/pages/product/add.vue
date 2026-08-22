<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useProducts from '@/composables/product';
    import { Head, router, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Fields from './Fields.vue';

    defineOptions({
        layout: {
            title: 'New Product',
            subtitle: 'Create a catalog item with classification, media, and pricing',
            breadcrumbs: [
                {
                    title: 'Product Management',
                    href: '/product',
                },
                {
                    title: 'Add Product',
                    href: 'NULL',
                },
            ],
        },
    });

    const page = usePage();
    const { Notify, handleError, formatedText } = useCommons();
    const { formData, defaultFormData, emptyDetail } = useProducts();

    const formRef = ref<any>(null);
    const isSaving = ref(false);
    const isLeaving = ref(false);
    const pageReady = ref(false);
    const formKey = ref(0);
    const saveAction = ref<'close' | 'add-new'>('close');
    const isWorking = computed(() => isSaving.value || isLeaving.value);
    const isBusy = computed(() => ! pageReady.value || isWorking.value);

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
    } | null);

    const isSuperadmin = computed(() => (
        String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin'
    ));

    function resetForm() {
        formData.value = {
            ...defaultFormData.value,
            productdetail: [emptyDetail()],
            ...(isSuperadmin.value ? {} : { company_id: String(authUser.value?.company_id ?? '') }),
        };
    }

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function submitWithDetails(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(API_ENDPOINTS.products, {
            ...form$?.data,
            type: formData.value?.type ?? form$?.data?.type ?? 'single',
            productdetail: formData.value?.productdetail ?? [],
        });

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
        await router.visit('/product');

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
    <Head :title="`New ${formatedText('product')}`" />

    <div class="product-form-page">
        <div class="product-form">
            <Loader v-if="!pageReady" message="Preparing form…" />

            <TheForm
                v-else
                v-model:submitting="isSaving"
                :key="formKey"
                :onSubmit="submitWithDetails"
                :formData="formData"
                :error="handleFormError"
                :url="API_ENDPOINTS.products"
                ref="formRef"
            >
                <Fields :form-data="formData" :form-ref="formRef" />
            </TheForm>

            <div class="product-form-page__footer">
                <div class="product-form-page__actions">
                    <button
                        type="button"
                        class="btn btn-light"
                        :disabled="isBusy"
                        @click="router.visit('/product')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn btn-outline-primary d-inline-flex align-items-center"
                        :disabled="isBusy"
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
                        :disabled="isBusy"
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
