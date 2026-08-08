<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import ModalComponent from '@/components/ModalComponent.vue';
    import { usePage } from '@inertiajs/vue3';
    import TheForm from '@/components/theForm.vue';
    import { ref } from 'vue';
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

    const formRef = ref(null) // create the ref

    function submitFromParent() {
        if (formRef.value) {
            formRef.value.submitForm() // call exposed method from child
        }
    }

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
    <ModalComponent id="EditModal" :title="`Edit ${props.routeName}`" :onOpen="modalProps.onOpen">
        <Loader v-if="modalProps.showLoader"/>
        <TheForm
            v-if="!modalProps.showLoader"
            :onSubmit="modalProps.onSubmit"
            :formData="modalProps.formData"
            :success="modalProps.success"
            :error="modalProps.error"
            :url="modalProps.endpoint"
            ref="formRef"
        >
            <Fields type="edit"/>
        </TheForm>

        <!-- ✅ Footer slot must be here -->
        <template #footer>
            <button type="button" class="btn btn-light waves-effect" data-bs-dismiss="modal">Close</button>

            <button
                type="submit"
                class="btn btn-primary"
                @click="$refs.formRef.submitForm()"
            >
                Save
            </button>
        </template>
    </ModalComponent>
</template>
