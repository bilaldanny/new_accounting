<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import TheForm from '@/components/theForm.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import useIssueNotes from '@/composables/issueNote';
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
            title: 'Edit Issue Note',
            subtitle: 'Update issued quantities for this goods issue',
            breadcrumbs: [
                {
                    title: 'Issue Note',
                    href: '/issuenote',
                },
                {
                    title: 'Edit Issue Note',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify, handleError, formatedText } = useCommons();
    const { formData, getEditData } = useIssueNotes();

    const formRef = ref<any>(null);
    const isSaving = ref(false);
    const isLeaving = ref(false);
    const pageReady = ref(false);
    const saveAction = ref<'close' | 'add-new'>('close');
    const isWorking = computed(() => isSaving.value || isLeaving.value);
    const isBusy = computed(() => ! pageReady.value || isWorking.value);

    const recordId = computed(() => Number(pageProps.id));
    const endpoint = computed(() => `${API_ENDPOINTS.issueNotes}/${recordId.value}`);

    function handleFormError(error: unknown, details?: unknown) {
        handleError(error, details, formRef);
    }

    async function submitWithLines(form$: { data?: Record<string, unknown> }) {
        const response = await window.axios.post(endpoint.value, {
            ...form$?.data,
            _method: 'PUT',
            selllines: formData.value?.selllines ?? [],
        });

        if (response.data?.errormessage) {
            handleFormError({ response }, { type: 'submit' });

            return response;
        }

        Notify(response.data?.message || 'Successfully Saved', 'success');
        isLeaving.value = true;
        await router.visit(saveAction.value === 'add-new' ? '/issuenote/add' : '/issuenote');

        return response;
    }

    function save(action: 'close' | 'add-new') {
        saveAction.value = action;
        formRef.value?.submitForm();
    }

    onMounted(async () => {
        const loaded = await getEditData(recordId.value);

        if (! loaded) {
            router.visit('/issuenote');

            return;
        }

        pageReady.value = true;
    });
</script>

<template>
    <Head :title="`Edit ${formatedText('issuenote')}`" />

    <div class="product-form-page purchase-form-page receiving-note-form-page">
        <div class="product-form purchase-form receiving-note-form">
            <Loader v-if="!pageReady" message="Loading issue note…" />

            <TheForm
                v-else
                v-model:submitting="isSaving"
                :key="endpoint"
                :onSubmit="submitWithLines"
                :formData="formData"
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
                <div class="product-form-page__actions">
                    <button
                        type="button"
                        class="btn btn-light"
                        :disabled="isBusy"
                        @click="router.visit('/issuenote')"
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
