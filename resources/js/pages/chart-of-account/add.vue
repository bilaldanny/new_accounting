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
        :title="`Add ${formatedText(props.routeName)}`"
        :onOpen="modalProps.onOpen"
        :onClose="modalProps.onClose"
        size="xl"
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
