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
        endpoint: { type: String },
        controlAccounts: { type: Array, default: () => [] },
        formKey: { type: Number, default: 0 },
        onOpen: { type: Function },
        onClose: { type: Function },
        onSubmit: { type: Function },
        success: { type: Function },
        error: { type: Function },
        onParentChange: { type: Function },
        onAccountTypeChange: { type: Function },
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

    function reset() {
        formRef.value?.reset();
    }

    defineExpose({ reset });
</script>

<template>
    <ModalComponent
        id="AddModal"
        title="Add New Account"
        :onOpen="modalProps.onOpen"
        :onClose="modalProps.onClose"
        size="lg"
        content-class="coa-form-modal"
    >
        <Loader v-if="modalProps.showLoader" message="Preparing form…" />
        <TheForm
            v-if="! modalProps.showLoader"
            :key="modalProps.formKey"
            v-model:submitting="isSaving"
            :onSubmit="modalProps.onSubmit"
            :formData="modalProps.formData"
            :success="modalProps.success"
            :error="modalProps.error"
            :url="modalProps.endpoint"
            ref="formRef"
        >
            <Fields
                :form-data="modalProps.formData"
                :form-ref="formRef"
                :control-accounts="modalProps.controlAccounts"
                :on-parent-change="modalProps.onParentChange"
                :on-account-type-change="modalProps.onAccountTypeChange"
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
                {{ isSaving ? 'Saving…' : 'Create Account' }}
            </button>
        </template>
    </ModalComponent>
</template>
