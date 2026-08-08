<script setup lang="ts">
import { reactive, ref } from 'vue';


    const params = defineProps({
        url: String,
        success: Function,
        error: Function,
        onSubmit: Function,
        endpoint: String,
        method: { type: String, default: 'post' },
        formSize: { type: String, default: 'lg' },
        formId: { type: String },
        formData: { type: Object},
        floatPlaceholders: {type: Boolean, default: false}
    });

    const emit = defineEmits(['update:formData'])

    const vueform$ = ref(null)

    // make a local copy of formData
    const localValue = reactive({ ...params.formData })

    // when Vueform updates → emit to parent
    const handleUpdate = (val) => {
        emit('update:formData', val)
    }

    const handleSubmit = async (form$: any, formData: FormData) => {
        if (typeof params.onSubmit === 'function') {
            await params.onSubmit(form$, formData)
        }
    }

    // expose a method so parent can trigger submit
    function submitForm() {
        if (vueform$.value) {
            vueform$.value.submit() // or .submitForm() depending on Vueform API
        }
    }

    function reset() {

        if(vueform$.value){
            vueform$.value.reset()
        }

        // replace all keys inside localValue
        Object.assign(localValue, params.formData)

        if(vueform$.value){
            vueform$.value.update(localValue);
            vueform$.value.clean();
        }
    }

    function update(value) {
        if(vueform$.value){
            vueform$.value.update(value);
        }
    }

    async function validate() {
        if(vueform$.value){
            await vueform$.value.validate();
            return !vueform$.value.invalid;
        }
        return false;
    }

    defineExpose({
        submitForm,
        reset,
        update,
        validate
    })

</script>

<template>
    <Vueform
        :endpoint="params.onSubmit ? false : url"
        :id="params.formId"
        :size="params.formSize"
        sync
        :model-value="localValue"
        @update:model-value="handleUpdate"
        @submit="handleSubmit"
        @success="params.success"
        @error="params.error"
        ref="vueform$"
        method="post"
        :show-required="['label', 'placeholder', 'floating']"
        :display-errors="false"
        :float-placeholders="params.floatPlaceholders"
        enctype="multipart/form-data"
    >
        <slot />
    </Vueform>
</template>
