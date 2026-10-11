<script setup lang="ts">
    import { onMounted, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    /**
     * The Documents tab of a customer or supplier: upload a file, list, download and delete. Files are kept
     * privately and counted in the company's storage limit; the server checks type, size and company scope.
     */
    const props = defineProps<{
        kind: 'customer' | 'supplier';
        contactId: number | string;
        canManage?: boolean;
    }>();

    type Attachment = {
        id: number;
        file_name: string;
        ext: string;
        size: number;
        description: string | null;
        uploaded_by: string | null;
        created_at: string | null;
    };

    const { Notify } = useCommons();

    const base = () => `/api/${props.kind === 'customer' ? 'customers' : 'suppliers'}/${props.contactId}/attachments`;

    const items = ref<Attachment[]>([]);
    const loading = ref(true);
    const uploading = ref(false);
    const description = ref('');
    const maxKilobytes = ref(5120);
    const fileInput = ref<HTMLInputElement | null>(null);

    function readableSize(bytes: number): string {
        return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    async function load() {
        loading.value = true;

        try {
            const response = await window.axios.get(base());
            items.value = response.data.data ?? [];
            maxKilobytes.value = response.data.max_kilobytes ?? maxKilobytes.value;
        } catch (error: unknown) {
            Notify(window.axios.isAxiosError(error) ? error.response?.data?.message || 'Unable to load documents' : 'Unable to load documents', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function upload() {
        const file = fileInput.value?.files?.[0];

        if (! file || uploading.value) {
            return;
        }

        uploading.value = true;

        try {
            const body = new FormData();
            body.append('file', file);

            if (description.value.trim() !== '') {
                body.append('description', description.value.trim());
            }

            await window.axios.post(base(), body);
            description.value = '';

            if (fileInput.value) {
                fileInput.value.value = '';
            }

            Notify('Document uploaded', 'success');
            await load();
        } catch (error: unknown) {
            const data = window.axios.isAxiosError(error) ? error.response?.data : null;
            const first = data?.errors ? Object.values(data.errors as Record<string, string[]>)[0]?.[0] : null;
            Notify(first || data?.message || 'Unable to upload the document', 'alert');
        } finally {
            uploading.value = false;
        }
    }

    async function download(item: Attachment) {
        try {
            const response = await window.axios.get(`${base()}/${item.id}/download`, { responseType: 'blob' });
            const url = URL.createObjectURL(response.data);
            const link = document.createElement('a');
            link.href = url;
            link.download = item.file_name;
            link.click();
            URL.revokeObjectURL(url);
        } catch {
            Notify('Unable to download the document', 'alert');
        }
    }

    async function remove(item: Attachment) {
        if (! window.confirm(`Delete ${item.file_name}?`)) {
            return;
        }

        try {
            await window.axios.delete(`${base()}/${item.id}`);
            Notify('Document deleted', 'success');
            await load();
        } catch {
            Notify('Unable to delete the document', 'alert');
        }
    }

    onMounted(load);
    watch(() => props.contactId, load);
</script>

<template>
    <div class="contact-documents">
        <div v-if="canManage" class="d-flex flex-wrap gap-2 align-items-end mb-3">
            <div>
                <label class="form-label" for="contact-document-file">File</label>
                <input
                    id="contact-document-file"
                    ref="fileInput"
                    type="file"
                    class="form-control form-control-sm"
                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt"
                />
            </div>
            <div class="flex-grow-1">
                <label class="form-label" for="contact-document-note">Note (optional)</label>
                <input id="contact-document-note" v-model="description" type="text" maxlength="255" class="form-control form-control-sm" />
            </div>
            <button type="button" class="btn btn-dark btn-sm" :disabled="uploading" @click="upload">
                {{ uploading ? 'Uploading…' : 'Upload' }}
            </button>
        </div>
        <p v-if="canManage" class="text-muted small">
            PDF, image, Word, Excel, CSV or text, up to {{ Math.round(maxKilobytes / 1024) }} MB each. Documents count towards the company storage.
        </p>

        <div v-if="loading" class="text-muted">Loading documents…</div>
        <div v-else-if="items.length === 0" class="customer-view-empty customer-view-empty--inline">
            <p>No documents have been uploaded yet.</p>
        </div>
        <div v-else class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Note</th>
                        <th>Size</th>
                        <th>Uploaded by</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.id">
                        <td>{{ item.file_name }}</td>
                        <td>{{ item.description || '-' }}</td>
                        <td>{{ readableSize(item.size) }}</td>
                        <td>{{ item.uploaded_by || '-' }}</td>
                        <td>{{ item.created_at ? item.created_at.slice(0, 10) : '-' }}</td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="download(item)">Download</button>
                            <button v-if="canManage" type="button" class="btn btn-outline-danger btn-sm" @click="remove(item)">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
