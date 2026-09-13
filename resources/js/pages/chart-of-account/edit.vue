<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import ModalComponent from '@/components/ModalComponent.vue';
    import TheForm from '@/components/theForm.vue';
    import { ref, watch } from 'vue';
    import Fields from './Fields.vue';

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
        title="Edit Account Details"
        :onClose="modalProps.onClose"
        size="lg"
        content-class="coa-form-modal"
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
            <button type="button" class="coa-modal-cancel" data-bs-dismiss="modal">Cancel</button>
            <button
                type="button"
                class="coa-modal-save"
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
                {{ isSaving ? 'Saving…' : 'Save Changes' }}
            </button>
        </template>
    </ModalComponent>
</template>
