<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useJournalEntries from '@/composables/journalentry';
    import { Head, router } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Fields from './Fields.vue';

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

    <div class="product-form-page purchase-form-page journal-form-page">
        <div class="product-form purchase-form">
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

            <div class="product-form-page__footer">
                <p class="journal-form-page__status mb-0" :class="{ 'is-ok': isBalanced }">
                    {{ isBalanced ? 'Voucher is balanced and ready to save' : 'Add matching debit and credit lines to save' }}
                </p>
                <div class="product-form-page__actions">
                    <button
                        type="button"
                        class="btn btn-light"
                        :disabled="isBusy"
                        @click="router.visit('/journalentry')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn btn-outline-primary d-inline-flex align-items-center"
                        :disabled="isBusy || !isBalanced"
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
                        :disabled="isBusy || !isBalanced"
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
