<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useJournalEntries from '@/composables/journalentry';
    import { Head, router } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Fields from './Fields.vue';
    import JournalFormChrome from './JournalFormChrome.vue';

    const pageProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
    });

    defineOptions({
        layout: {
            title: 'Edit Journal Entry',
            subtitle: 'Update voucher date, narration, and account lines',
            breadcrumbs: [
                {
                    title: 'Journal Entry',
                    href: '/journalentry',
                },
                {
                    title: 'Edit Journal Entry',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify, handleError, formatedText } = useCommons();
    const { formData, getEditData } = useJournalEntries();

    const formRef = ref<any>(null);
    const isSaving = ref(false);
    const isLeaving = ref(false);
    const pageReady = ref(false);
    const saveAction = ref<'close' | 'add-new'>('close');
    const isWorking = computed(() => isSaving.value || isLeaving.value);
    const isBusy = computed(() => ! pageReady.value || isWorking.value);
    const recordId = computed(() => Number(pageProps.id));
    const endpoint = computed(() => `${API_ENDPOINTS.journalEntries}/${recordId.value}`);

    const isBalanced = computed(() => {
        const lines = Array.isArray(formData.value?.taccountdetails) ? formData.value.taccountdetails : [];
        const debit = lines.reduce((sum, line) => sum + Number(line.debit || 0), 0);
        const credit = lines.reduce((sum, line) => sum + Number(line.credit || 0), 0);

        return lines.length > 0 && Math.round(debit * 100) === Math.round(credit * 100);
    });

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function submitJournal(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(endpoint.value, {
            ...form$?.data,
            taccountdetails: formData.value?.taccountdetails ?? [],
            attachments: formData.value?.attachments ?? [],
            voucher_type: formData.value?.voucher_type ?? 'JV',
            comments: formData.value?.comments ?? '',
            total_amount: formData.value?.total_amount ?? 0,
        });

        if (response.data?.errormessage) {
            handleFormError({ response }, { type: 'submit' });

            return response;
        }

        Notify(response.data?.message || 'Successfully Saved', 'success');
        isLeaving.value = true;
        await router.visit(saveAction.value === 'add-new' ? '/journalentry/add' : '/journalentry');

        return response;
    }

    function save(action: 'close' | 'add-new') {
        saveAction.value = action;
        formRef.value?.submitForm();
    }

    onMounted(async () => {
        const loaded = await getEditData(recordId.value);

        if (! loaded) {
            router.visit('/journalentry');

            return;
        }

        pageReady.value = true;
    });
</script>

<template>
    <Head :title="`Edit ${formatedText('journalentry')}`" />

    <div class="product-form-page journal-form-page">
        <JournalFormChrome
            mode="edit"
            :is-busy="isBusy"
            :is-working="isWorking"
            :save-action="saveAction"
            :save-disabled="!isBalanced"
            :voucher-no="String(formData?.voucher_no ?? '')"
            @cancel="router.visit('/journalentry')"
            @save="save"
        >
            <div class="product-form product-form--sectioned">
                <Loader v-if="!pageReady" message="Loading journal entry…" />

                <TheForm
                    v-else
                    v-model:submitting="isSaving"
                    :key="endpoint"
                    :onSubmit="submitJournal"
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
        </JournalFormChrome>
    </div>
</template>
