<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useSells from '@/composables/sell';
    import { Head, router, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Fields from './Fields.vue';

    defineOptions({
        layout: {
            title: 'New Sell',
            subtitle: 'Create a customer invoice with products, shipping, and totals',
            breadcrumbs: [
                {
                    title: 'Sell Management',
                    href: '/sell',
                },
                {
                    title: 'Add Sell',
                    href: 'NULL',
                },
            ],
        },
    });

    const page = usePage();
    const { Notify, handleError, formatedText } = useCommons();
    const { formData, defaultFormData, emptyForm } = useSells();

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
        branch_id?: number | string | null;
    } | null);

    const roleName = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, ''));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');

    function resetForm() {
        const queryStatus = new URLSearchParams(window.location.search).get('status');
        const allowedStatus = ['final', 'draft', 'quotation'];
        const status = queryStatus && allowedStatus.includes(queryStatus) ? queryStatus : 'final';

        formData.value = {
            ...emptyForm(),
            ...defaultFormData.value,
            status,
            ...(isSuperadmin.value
                ? {}
                : isCompanyadmin.value
                    ? { company_id: String(authUser.value?.company_id ?? '') }
                    : {
                        company_id: String(authUser.value?.company_id ?? ''),
                        branch_id: String(authUser.value?.branch_id ?? ''),
                    }),
        };
    }

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function submitWithLines(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(API_ENDPOINTS.sells, {
            ...form$?.data,
            selllines: formData.value?.selllines ?? [],
            is_direct: formData.value?.is_direct ?? false,
            status: formData.value?.status ?? 'final',
            discount_type: formData.value?.discount_type ?? 'none',
            discount_amount: formData.value?.discount_amount ?? 0,
            shipping_charges: formData.value?.shipping_charges ?? 0,
            shipping_details: formData.value?.shipping_details ?? '',
            shipping_address: formData.value?.shipping_address ?? '',
            shipping_status: formData.value?.shipping_status || null,
            delivered_to: formData.value?.delivered_to ?? '',
            billty_no: formData.value?.billty_no ?? '',
            billty_date: formData.value?.billty_date || null,
            packing: formData.value?.packing ?? '',
            billty_image: formData.value?.billty_image ?? '',
            additional_note: formData.value?.additional_note ?? '',
            final_amount: formData.value?.final_amount ?? 0,
            total_item: formData.value?.total_item ?? 0,
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
        await router.visit('/sell');

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
    <Head :title="`New ${formatedText('sell')}`" />

    <div class="product-form-page purchase-form-page">
        <div class="product-form purchase-form">
            <Loader v-if="!pageReady" message="Preparing form…" />

            <TheForm
                v-else
                v-model:submitting="isSaving"
                :key="formKey"
                :onSubmit="submitWithLines"
                :formData="formData"
                :error="handleFormError"
                :url="API_ENDPOINTS.sells"
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
                        @click="router.visit('/sell')"
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
