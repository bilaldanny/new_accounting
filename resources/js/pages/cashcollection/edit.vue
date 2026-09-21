<script setup lang="ts">
    import { Head, router } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCashCollections from '@/composables/cashcollection';
    import useCommons from '@/composables/common';
    import CashCollectionFormChrome from './CashCollectionFormChrome.vue';
    import Fields from './Fields.vue';

    const pageProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
    });

    defineOptions({
        layout: {
            title: 'Edit Cash Collection',
            subtitle: 'Change a pending collection',
            breadcrumbs: [
                {
                    title: 'Cash Collection',
                    href: '/cashcollection',
                },
                {
                    title: 'Edit Cash Collection',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify, handleError, formatedText } = useCommons();
    const { formData, getEditData } = useCashCollections();

    const formRef = ref<any>(null);
    const isSaving = ref(false);
    const isLeaving = ref(false);
    const pageReady = ref(false);
    const saveAction = ref<'close' | 'add-new'>('close');
    const isWorking = computed(() => isSaving.value || isLeaving.value);
    const isBusy = computed(() => ! pageReady.value || isWorking.value);

    const recordId = computed(() => Number(pageProps.id));
    const endpoint = computed(() => `${API_ENDPOINTS.cashCollections}/${recordId.value}`);

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function submitCashCollection(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(endpoint.value, {
            ...form$?.data,
        });

        if (response.data?.errormessage) {
            handleFormError({ response }, { type: 'submit' });

            return response;
        }

        Notify(response.data?.message || 'Successfully Saved', 'success');
        isLeaving.value = true;
        await router.visit(saveAction.value === 'add-new' ? '/cashcollection/add' : '/cashcollection');

        return response;
    }

    function save(action: 'close' | 'add-new') {
        saveAction.value = action;
        formRef.value?.submitForm();
    }

    onMounted(async () => {
        const loaded = await getEditData(recordId.value);

        if (! loaded) {
            router.visit('/cashcollection');

            return;
        }

        pageReady.value = true;
    });
</script>

<template>
    <Head :title="`Edit ${formatedText('cashcollection')}`" />

    <div class="product-form-page purchase-form-page">
        <CashCollectionFormChrome
            mode="edit"
            :is-busy="isBusy"
            :is-working="isWorking"
            :save-action="saveAction"
            @cancel="router.visit('/cashcollection')"
            @save="save"
        >
            <div class="product-form product-form--sectioned purchase-form">
                <Loader v-if="!pageReady" message="Loading cash collections…" />

                <TheForm
                    v-else
                    v-model:submitting="isSaving"
                    :key="endpoint"
                    :onSubmit="submitCashCollection"
                    :formData="formData"
                    :error="handleFormError"
                    :url="endpoint"
                    ref="formRef"
                >
                    <Fields
                        type="edit"
                        :form-data="formData"
                        :form-ref="formRef"
                    />
                </TheForm>
            </div>
        </CashCollectionFormChrome>
    </div>
</template>
