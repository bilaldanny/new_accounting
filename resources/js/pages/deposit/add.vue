<script setup lang="ts">
    import { Head, router, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useDeposits from '@/composables/deposit';
    import DepositFormChrome from './DepositFormChrome.vue';
    import Fields from './Fields.vue';

    defineOptions({
        layout: {
            title: 'New Deposit',
            subtitle: 'Record a balanced bank, cash, or online deposit',
            breadcrumbs: [
                {
                    title: 'Deposit',
                    href: '/deposit',
                },
                {
                    title: 'Add Deposit',
                    href: 'NULL',
                },
            ],
        },
    });

    const page = usePage();
    const { Notify, handleError, formatedText } = useCommons();
    const { formData, defaultFormData, emptyForm } = useDeposits();

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

    const isBalanced = computed(() => {
        const lines = Array.isArray(formData.value?.taccountdetails) ? formData.value.taccountdetails : [];
        const debit = lines.reduce((sum, line) => sum + Number(line.debit || 0), 0);
        const credit = lines.reduce((sum, line) => sum + Number(line.credit || 0), 0);

        return lines.length > 0 && Math.round(debit * 100) === Math.round(credit * 100);
    });

    function resetForm() {
        formData.value = {
            ...emptyForm(),
            ...defaultFormData.value,
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

    async function submitDeposit(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(API_ENDPOINTS.deposits, {
            ...form$?.data,
            taccountdetails: formData.value?.taccountdetails ?? [],
            attachments: formData.value?.attachments ?? [],
            voucher_type: formData.value?.voucher_type ?? 'BD',
            comments: formData.value?.comments ?? '',
            cheque_no: formData.value?.cheque_no ?? '',
            total_amount: formData.value?.total_amount ?? 0,
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
        await router.visit('/deposit');

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
    <Head :title="`New ${formatedText('deposit')}`" />

    <div class="product-form-page journal-form-page">
        <DepositFormChrome
            mode="add"
            :is-busy="isBusy"
            :is-working="isWorking"
            :save-action="saveAction"
            :save-disabled="!isBalanced"
            @cancel="router.visit('/deposit')"
            @save="save"
        >
            <div class="product-form product-form--sectioned">
                <Loader v-if="!pageReady" message="Preparing form…" />

                <TheForm
                    v-else
                    v-model:submitting="isSaving"
                    :key="formKey"
                    :onSubmit="submitDeposit"
                    :formData="formData"
                    :show-required="[]"
                    :error="handleFormError"
                    :url="API_ENDPOINTS.deposits"
                    ref="formRef"
                >
                    <Fields :form-data="formData" :form-ref="formRef" />
                </TheForm>
            </div>
        </DepositFormChrome>
    </div>
</template>
