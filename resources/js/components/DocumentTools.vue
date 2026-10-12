<script setup lang="ts">
    import { computed, onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    /**
     * What can be done with one sale on its view page beyond the plain print: print it with a Document Center template in a chosen
     * language and paper (the browser can save the page as PDF), export its lines as CSV or Excel, send it, attach files and fill
     * its custom fields. Everything goes through /api/documents/{id}/..., which applies the sale's own permissions.
     */
    const props = defineProps<{ id: number; canEdit: boolean }>();

    type Template = { id: number; name: string; doc_type: string; language: string; paper: string; is_default: boolean };
    type Attachment = { id: number; file_name: string; size: number; description: string | null };
    type Field = { id: number; field_key: string; label: string; type: string; options: string[]; value: string | null; is_required: boolean };

    const { run } = useCloseout();
    const templates = ref<Template[]>([]);
    const attachments = ref<Attachment[]>([]);
    const fields = ref<Field[]>([]);
    const templateId = ref<number | ''>('');
    const language = ref('en');
    const paper = ref('');
    const open = ref(false);
    const file = ref<File | null>(null);
    const description = ref('');

    const base = computed(() => `/api/documents/${props.id}`);

    function printUrl(): string {
        const query = new URLSearchParams({ print: '1', language: language.value });

        if (templateId.value !== '') {
            query.set('template_id', String(templateId.value));
        }

        if (paper.value) {
            query.set('paper', paper.value);
        }

        return `${base.value}/render?${query.toString()}`;
    }

    async function load() {
        const [t, a, f] = await Promise.all([
            run(() => window.axios.get('/api/document-templates')),
            run(() => window.axios.get(`${base.value}/attachments`)),
            run(() => window.axios.get(`${base.value}/custom-fields`)),
        ]);
        templates.value = (t?.data?.data ?? []).filter((item: Template) => item.doc_type === 'invoice' || item.doc_type === 'receipt');
        attachments.value = a?.data?.data ?? [];
        fields.value = f?.data?.data ?? [];
    }

    async function upload() {
        if (!file.value) {
            return;
        }

        const form = new FormData();
        form.append('file', file.value);

        if (description.value) {
            form.append('description', description.value);
        }

        if (await run(() => window.axios.post(`${base.value}/attachments`, form), 'Document uploaded')) {
            file.value = null;
            description.value = '';
            await load();
        }
    }

    async function removeAttachment(item: Attachment) {
        if (await run(() => window.axios.delete(`${base.value}/attachments/${item.id}`), 'Document deleted')) {
            await load();
        }
    }

    async function saveFields() {
        const values: Record<string, string | null> = {};

        for (const field of fields.value) {
            values[field.field_key] = field.value;
        }

        await run(() => window.axios.put(`${base.value}/custom-fields`, { values }), 'Custom fields saved');
        await load();
    }

    onMounted(load);
</script>

<template>
    <div class="border rounded p-3 mt-3 document-tools">
        <button type="button" class="btn btn-link p-0" :aria-expanded="open" @click="open = !open">Documents, export and attachments</button>

        <div v-if="open" class="mt-3">
            <div class="row g-2 align-items-end mb-3">
                <div class="col-auto">
                    <label class="form-label small" for="dt-template">Template</label>
                    <select id="dt-template" v-model="templateId" class="form-select form-select-sm">
                        <option value="">Default design</option>
                        <option v-for="item in templates" :key="item.id" :value="item.id">{{ item.name }} ({{ item.doc_type }})</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small" for="dt-language">Language</label>
                    <select id="dt-language" v-model="language" class="form-select form-select-sm"><option value="en">English</option><option value="ur">Urdu</option></select>
                </div>
                <div class="col-auto">
                    <label class="form-label small" for="dt-paper">Paper</label>
                    <select id="dt-paper" v-model="paper" class="form-select form-select-sm">
                        <option value="">Template paper</option><option value="a4">A4</option><option value="a5">A5</option><option value="thermal80">Thermal 80 mm</option><option value="thermal58">Thermal 58 mm</option>
                    </select>
                </div>
                <div class="col-auto d-flex gap-2">
                    <a class="btn btn-outline-dark btn-sm" :href="printUrl()" target="_blank" rel="noopener">Print / save as PDF</a>
                    <a class="btn btn-outline-secondary btn-sm" :href="`${base}/export?format=csv`">CSV</a>
                    <a class="btn btn-outline-secondary btn-sm" :href="`${base}/export?format=xlsx`">Excel</a>
                </div>
            </div>

            <div v-if="fields.length" class="mb-3">
                <h6>Custom fields</h6>
                <div v-for="field in fields" :key="field.id" class="row g-2 mb-1 align-items-center">
                    <label class="col-sm-3 col-form-label small" :for="`cf-${field.field_key}`">{{ field.label }}<span v-if="field.is_required"> *</span></label>
                    <div class="col-sm-6">
                        <select v-if="field.type === 'select'" :id="`cf-${field.field_key}`" v-model="field.value" class="form-select form-select-sm" :disabled="!canEdit">
                            <option :value="null">-</option><option v-for="option in field.options" :key="option" :value="option">{{ option }}</option>
                        </select>
                        <input v-else-if="field.type === 'checkbox'" :id="`cf-${field.field_key}`" type="checkbox" class="form-check-input" :checked="field.value === '1'" :disabled="!canEdit" @change="field.value = ($event.target as HTMLInputElement).checked ? '1' : '0'" />
                        <input v-else :id="`cf-${field.field_key}`" v-model="field.value" :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'" class="form-control form-control-sm" :disabled="!canEdit" />
                    </div>
                </div>
                <button v-if="canEdit" type="button" class="btn btn-dark btn-sm mt-2" @click="saveFields">Save custom fields</button>
            </div>

            <div>
                <h6>Attachments</h6>
                <div v-for="item in attachments" :key="item.id" class="d-flex gap-2 align-items-center mb-1">
                    <a :href="`${base}/attachments/${item.id}`">{{ item.file_name }}</a>
                    <span class="text-muted small">{{ Math.round(item.size / 1024) }} KB {{ item.description }}</span>
                    <button v-if="canEdit" type="button" class="btn btn-outline-danger btn-sm py-0" @click="removeAttachment(item)">Delete</button>
                </div>
                <div v-if="!attachments.length" class="text-muted small">No attachments.</div>
                <div v-if="canEdit" class="d-flex gap-2 mt-2">
                    <input type="file" class="form-control form-control-sm" aria-label="File to attach" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    <input v-model="description" type="text" class="form-control form-control-sm" placeholder="Description" maxlength="255" aria-label="Attachment description" />
                    <button type="button" class="btn btn-outline-dark btn-sm" :disabled="!file" @click="upload">Upload</button>
                </div>
            </div>
        </div>
    </div>
</template>
