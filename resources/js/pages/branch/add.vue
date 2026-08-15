<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import ModalComponent from '@/components/ModalComponent.vue';
    import { usePage } from '@inertiajs/vue3';
    import TheForm from '@/components/theForm.vue';
    import { ref, watch } from 'vue';
    import Fields from './Fields.vue';

    const {props} = usePage();

    const modalProps = defineProps({
        showLoader:{type: Boolean,default:false},
        formData:{type: Object},
        formRef:{type: Object},
        endpoint:{type: String},
        onOpen:{type: Function},
        onClose:{type: Function},
        onSubmit:{type: Function},
        success:{type: Function},
        error:{type: Function},
    });

    const formRef = ref(null)
    const isSaving = ref(false)

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
        if(formRef.value){
            formRef.value.reset()
        }
    }

    defineExpose({
    reset,
    })

</script>

<template>
    <ModalComponent
        id="AddModal"
        :title="`Add ${props.routeName}`"
        :onOpen="modalProps.onOpen"
        :onClose="modalProps.onClose"
        size="xl"
    >
        <Loader v-if="modalProps.showLoader" message="Preparing form…" />
        <TheForm
            v-if="!modalProps.showLoader"
            v-model:submitting="isSaving"
            :onSubmit="modalProps.onSubmit"
            :formData="modalProps.formData"
            :success="modalProps.success"
            :error="modalProps.error"
            :url="modalProps.endpoint"
            ref="formRef"
        >
            <Fields :formData="modalProps.formData" :formRef="formRef" />
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
