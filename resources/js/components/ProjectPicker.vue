<script setup lang="ts">
    import { onMounted, watch } from 'vue';
    import useVoucherOptions from '@/composables/voucherOptions';

    /**
     * An optional "Project" select for a document form. It draws nothing when the company has no open project, so a company that
     * does not use projects sees the same form as before. The list is the signed-in company's own (the server decides).
     */
    const props = defineProps<{ modelValue: number | string | null | undefined; companyId?: number | string | null; disabled?: boolean }>();
    const emit = defineEmits<{ 'update:modelValue': [value: number | ''] }>();

    const { projects, load } = useVoucherOptions(() => props.companyId);

    onMounted(load);
    watch(() => props.companyId, load);
</script>

<template>
    <div v-if="projects.length" class="project-picker">
        <label class="form-label small mb-1" for="project-picker-select">Project (optional)</label>
        <select
            id="project-picker-select"
            class="form-select form-select-sm"
            :disabled="disabled"
            :value="modelValue ?? ''"
            aria-label="Project"
            @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value === '' ? '' : Number(($event.target as HTMLSelectElement).value))"
        >
            <option value="">No project</option>
            <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.text }}</option>
        </select>
    </div>
</template>
