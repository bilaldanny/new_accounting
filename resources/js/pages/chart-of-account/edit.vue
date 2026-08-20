<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import ModalComponent from '@/components/ModalComponent.vue';
    import useCommons from '@/composables/common';
    import { usePage } from '@inertiajs/vue3';
    import TheForm from '@/components/theForm.vue';
    import { ref, watch } from 'vue';
    import Fields from './Fields.vue';

    const { props } = usePage();
    const { formatedText } = useCommons();

    const modalProps = defineProps({
        showLoader: { type: Boolean, default: false },
        formData: { type: Object },
        formRef: { type: Object },
        recordId: { type: Number, default: null },
        endpoint: { type: String },
        controlAccounts: { type: Array, default: () => [] },
        onClose: { type: Function },
        onSubmit: { type: Function },
        success: { type: Function },
        error: { type: Function },
    });

    const formRef = ref(null);
    const isSaving = ref(false);

    watch(() => modalProps.showLoader, (loading) => {
        if (loading) {
            isSaving.value = false;
        }
    });

    watch(formRef, (instance) => {
        if (modalProps.formRef) {
            modalProps.formRef.value = instance;
        }
    });
</script>

<template>
    <ModalComponent
        id="EditModal"
        :title="`Edit ${formatedText(props.routeName)}`"
        :onClose="modalProps.onClose"
        size="xl"
    >
        <Loader v-if="modalProps.showLoader" message="Preparing form…" />
        <TheForm
            v-if="! modalProps.showLoader"
            v-model:submitting="isSaving"
            :key="modalProps.endpoint"
            :onSubmit="modalProps.onSubmit"
            :formData="modalProps.formData"
            :success="modalProps.success"
            :error="modalProps.error"
            :url="modalProps.endpoint"
            ref="formRef"
        >
            <Fields
                type="edit"
                :record-id="modalProps.recordId"
                :form-data="modalProps.formData"
                :form-ref="formRef"
                :control-accounts="modalProps.controlAccounts"
            />
        </TheForm>

        <template #footer>
            <button type="button" class="btn btn-light waves-effect" data-bs-dismiss="modal">Close</button>

            <button
                type="button"
                class="btn btn-primary d-inline-flex align-items-center"
                :disabled="modalProps.showLoader || isSaving"
                :aria-busy="isSaving"
                @click="formRef?.submitForm()"
            >
                <span
                    v-if="isSaving"
                    class="spinner-border spinner-border-sm me-1"
                    role="status"
                    aria-hidden="true"
                ></span>
                {{ isSaving ? 'Saving…' : 'Save' }}
            </button>
        </template>
    </ModalComponent>
</template>
