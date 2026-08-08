<script setup lang="ts">
import { reactive, ref, watch } from 'vue';


    const params = defineProps({
        url: String,
        success: Function,
        error: Function,
        onSubmit: Function,
        endpoint: String,
        method: { type: String, default: 'post' },
        formSize: { type: String, default: 'sm' },
        formId: { type: String },
        formData: { type: Object},
        floatPlaceholders: {type: Boolean, default: false}
    });

    const emit = defineEmits(['update:formData', 'update:submitting'])

    const vueform$ = ref(null)
    const isSubmitting = ref(false)

    watch(isSubmitting, (value) => {
        emit('update:submitting', value)
    })

    // make a local copy of formData
    const localValue = reactive({ ...(params.formData ?? {}) })

    watch(
        () => params.formData,
        (newData) => {
            if (!newData) {
                return;
            }

            Object.assign(localValue, newData);
            vueform$.value?.update(localValue);
        },
        { deep: true },
    );

    // when Vueform updates → emit to parent
    const handleUpdate = (val) => {
        emit('update:formData', val)
    }

    const handleSubmit = async (form$: any, formData: FormData) => {
        isSubmitting.value = true;

        if (typeof params.onSubmit === 'function') {
            try {
                await params.onSubmit(form$, formData);
            } catch {
                isSubmitting.value = false;
            }
        }
    }

    const handleSuccess = (response: unknown) => {
        isSubmitting.value = false;
        params.success?.(response);
    }

    const handleError = (error: unknown, details?: unknown) => {
        isSubmitting.value = false;
        params.error?.(error, details);
    }

    const handleFinish = () => {
        isSubmitting.value = false;
    }

    // expose a method so parent can trigger submit
    async function submitForm() {
        if (!vueform$.value || isSubmitting.value) {
            return;
        }

        await vueform$.value.validate();

        if (vueform$.value.invalid) {
            return;
        }

        vueform$.value.submit();
    }

    function reset() {
        isSubmitting.value = false;

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
        validate,
        isSubmitting,
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
        @success="handleSuccess"
        @error="handleError"
        @finish="handleFinish"
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
