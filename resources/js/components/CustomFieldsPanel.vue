<script setup lang="ts">
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    /**
     * The custom fields (Document Center) of a customer, supplier or product: shown with their values and saved on their own, so the
     * record's own form is untouched. Nothing is shown when the company has defined no field for the record.
     */
    const props = defineProps<{ entity: 'contact' | 'product'; id: number; canEdit: boolean }>();

    type Field = { id: number; field_key: string; label: string; type: string; options: string[]; value: string | null; is_required: boolean };

    const { run } = useCloseout();
    const fields = ref<Field[]>([]);

    async function load() {
        const response = await run(() => window.axios.get(`/api/custom-values/${props.entity}/${props.id}`));
        fields.value = response?.data?.data ?? [];
    }

    async function save() {
        const values: Record<string, string | null> = {};

        for (const field of fields.value) {
            values[field.field_key] = field.value;
        }

        const response = await run(() => window.axios.put(`/api/custom-values/${props.entity}/${props.id}`, { values }), 'Custom fields saved');
        fields.value = response?.data?.data ?? fields.value;
    }

    onMounted(load);
</script>

<template>
    <div v-if="fields.length" class="border rounded p-3 mt-3">
        <h6>Custom fields</h6>
        <div v-for="field in fields" :key="field.id" class="row g-2 mb-1 align-items-center">
            <label class="col-sm-3 col-form-label small" :for="`cfp-${field.field_key}`">{{ field.label }}<span v-if="field.is_required"> *</span></label>
            <div class="col-sm-6">
                <select v-if="field.type === 'select'" :id="`cfp-${field.field_key}`" v-model="field.value" class="form-select form-select-sm" :disabled="!canEdit"><option :value="null">-</option><option v-for="option in field.options" :key="option" :value="option">{{ option }}</option></select>
                <input v-else-if="field.type === 'checkbox'" :id="`cfp-${field.field_key}`" type="checkbox" class="form-check-input" :checked="field.value === '1'" :disabled="!canEdit" @change="field.value = ($event.target as HTMLInputElement).checked ? '1' : '0'" />
                <input v-else :id="`cfp-${field.field_key}`" v-model="field.value" :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'" class="form-control form-control-sm" :disabled="!canEdit" />
            </div>
        </div>
        <button v-if="canEdit" type="button" class="btn btn-dark btn-sm mt-2" @click="save">Save custom fields</button>
    </div>
</template>
