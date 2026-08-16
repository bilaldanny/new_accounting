<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import ModalComponent from '@/components/ModalComponent.vue';
    import TheForm from '@/components/theForm.vue';
    import { ref, watch } from 'vue';
    import Fields from './Fields.vue';

    const modalProps = defineProps({
        showLoader: { type: Boolean, default: false },
        formData: { type: Object, required: true },
        formRef: { type: Object, default: null },
        endpoint: { type: String, required: true },
        onOpen: { type: Function, default: undefined },
        onClose: { type: Function, default: undefined },
        success: { type: Function, default: undefined },
        error: { type: Function, default: undefined },
        companyId: { type: [String, Number], default: '' },
        isSuperadmin: { type: Boolean, default: false },
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
        formRef.value?.reset?.();
    }

    defineExpose({ reset });
</script>

<template>
    <ModalComponent
        id="TaxAddModal"
        title="Add Tax"
        :on-open="modalProps.onOpen"
        :on-close="modalProps.onClose"
    >
        <Loader v-if="modalProps.showLoader" message="Preparing form…" />

        <TheForm
            v-if="!modalProps.showLoader"
            v-model:submitting="isSaving"
            :form-data="modalProps.formData"
            :success="modalProps.success"
            :error="modalProps.error"
            :url="modalProps.endpoint"
            ref="formRef"
        >
            <Fields
                :form-data="modalProps.formData"
                :form-ref="formRef"
                :company-id="modalProps.companyId"
                :is-superadmin="modalProps.isSuperadmin"
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
