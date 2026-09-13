<script setup lang="ts">
    import { Head, router, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useProducts from '@/composables/product';
    import Fields from './Fields.vue';
    import ProductFormChrome from './ProductFormChrome.vue';

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
        <ProductFormChrome
            mode="add"
            :is-busy="isBusy"
            :is-working="isWorking"
            :save-action="saveAction"
            @cancel="router.visit('/product')"
            @save="save"
        >
            <div class="product-form product-form--sectioned">
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
            </div>
        </ProductFormChrome>
    </div>
</template>
