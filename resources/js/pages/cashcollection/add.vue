<script setup lang="ts">
    import { Head, router, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCashCollections from '@/composables/cashcollection';
    import useCommons from '@/composables/common';
    import CashCollectionFormChrome from './CashCollectionFormChrome.vue';
    import Fields from './Fields.vue';

    defineOptions({
        layout: {
            title: 'New Cash Collection',
            subtitle: 'Record cash collected from a customer',
            breadcrumbs: [
                {
                    title: 'Cash Collection',
                    href: '/cashcollection',
                },
                {
                    title: 'Add Cash Collection',
                    href: 'NULL',
                },
            ],
        },
    });

    const page = usePage();
    const { Notify, handleError, formatedText } = useCommons();
    const { formData, defaultFormData, emptyForm } = useCashCollections();

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

    const roleName = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, ''));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');

    function resetForm() {
        formData.value = {
            ...emptyForm(),
            ...defaultFormData.value,
            ...(isSuperadmin.value
                ? {}
                : { company_id: String(authUser.value?.company_id ?? '') }),
        };
    }

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function submitCashCollection(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(API_ENDPOINTS.cashCollections, {
            ...form$?.data,
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
        await router.visit(response.data?.id ? `/cashcollection/${response.data.id}/view` : '/cashcollection');

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
    <Head :title="`New ${formatedText('cashcollection')}`" />

    <div class="product-form-page purchase-form-page">
        <CashCollectionFormChrome
            mode="add"
            :is-busy="isBusy"
            :is-working="isWorking"
            :save-action="saveAction"
            @cancel="router.visit('/cashcollection')"
            @save="save"
        >
            <div class="product-form product-form--sectioned purchase-form">
                <Loader v-if="!pageReady" message="Preparing form…" />

                <TheForm
                    v-else
                    v-model:submitting="isSaving"
                    :key="formKey"
                    :onSubmit="submitCashCollection"
                    :formData="formData"
                    :error="handleFormError"
                    :url="API_ENDPOINTS.cashCollections"
                    ref="formRef"
                >
                    <Fields :form-data="formData" :form-ref="formRef" />
                </TheForm>
            </div>
        </CashCollectionFormChrome>
    </div>
</template>
