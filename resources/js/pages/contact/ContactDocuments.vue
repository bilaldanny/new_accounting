<script setup lang="ts">
    import { onMounted, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    /**
     * The Documents tab of a customer or supplier. Two parts: the files that were attached to the party's sales or purchases (read from
     * the documents themselves), and the files uploaded to the account itself (upload, list, download, delete). Uploaded files are kept
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

    type DocumentFile = {
        key: string;
        transaction_id: number;
        invoice_no: string;
        document: string;
        date: string;
        amount: number;
        what: string;
        file_name: string;
        url: string | null;
        is_image: boolean;
    };

    const { Notify } = useCommons();

    const base = () => `/api/${props.kind === 'customer' ? 'customers' : 'suppliers'}/${props.contactId}/attachments`;

    const attached = ref<DocumentFile[]>([]);
    const attachedLoading = ref(true);
    const items = ref<Attachment[]>([]);
    const loading = ref(true);
    const uploading = ref(false);
    const description = ref('');
    const maxKilobytes = ref(5120);
    const fileInput = ref<HTMLInputElement | null>(null);

    function readableSize(bytes: number): string {
        return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    function extension(name: string): string {
        return (name.split('.').pop() ?? '').slice(0, 4).toUpperCase() || 'FILE';
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

    async function loadAttached() {
        attachedLoading.value = true;

        try {
            attached.value = (await window.axios.get(`/api/contacts/${props.contactId}/document-attachments`)).data.data ?? [];
        } catch {
            attached.value = [];
        } finally {
            attachedLoading.value = false;
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

    onMounted(() => {
        load();
        loadAttached();
    });

    watch(() => props.contactId, () => {
        load();
        loadAttached();
    });
</script>

<template>
    <div class="cd" data-test="contact-documents">
        <section class="cd__section" data-test="attached-files">
            <header class="cd__head">
                <div>
                    <h4 class="cd__title">Attached to {{ kind === 'customer' ? 'sales' : 'purchases' }}</h4>
                    <p class="cd__subtitle">The bills, invoices and delivery papers attached when a {{ kind === 'customer' ? 'sale' : 'purchase' }} was recorded</p>
                </div>
                <span class="cd__count">{{ attached.length }}</span>
            </header>

            <div v-if="attachedLoading" class="cd__empty">Loading attachments...</div>
            <div v-else-if="attached.length === 0" class="cd__empty">
                <strong>No attachment on any {{ kind === 'customer' ? 'sale' : 'purchase' }} yet</strong>
                <span>Attach a file on the {{ kind === 'customer' ? 'sale' : 'purchase' }} form and it appears here.</span>
            </div>
            <div v-else class="cd__grid">
                <a v-for="file in attached" :key="file.key" class="cd__card" :href="file.url ?? '#'" target="_blank" rel="noopener" :title="`Open ${file.file_name}`">
                    <span class="cd__thumb">
                        <img v-if="file.is_image && file.url" :src="file.url" alt="" loading="lazy">
                        <span v-else class="cd__ext">{{ extension(file.file_name) }}</span>
                    </span>
                    <span class="cd__meta">
                        <strong>{{ file.document }} {{ file.invoice_no }}</strong>
                        <span>{{ file.what }} &middot; {{ file.date }}</span>
                        <span class="cd__file">{{ file.file_name }}</span>
                    </span>
                    <span class="cd__open">Open</span>
                </a>
            </div>
        </section>

        <section class="cd__section">
            <header class="cd__head">
                <div>
                    <h4 class="cd__title">Files uploaded to this account</h4>
                    <p class="cd__subtitle">Agreements, CNIC and tax papers, price lists: anything that belongs to the party itself</p>
                </div>
                <span class="cd__count">{{ items.length }}</span>
            </header>

            <form v-if="canManage" class="cd__upload" @submit.prevent="upload">
                <div class="cd__field">
                    <label for="contact-document-file">File</label>
                    <input id="contact-document-file" ref="fileInput" type="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt">
                </div>
                <div class="cd__field cd__field--grow">
                    <label for="contact-document-note">Note (optional)</label>
                    <input id="contact-document-note" v-model="description" type="text" maxlength="255" class="form-control form-control-sm" placeholder="What is this file?">
                </div>
                <button type="submit" class="cd__button" :disabled="uploading">{{ uploading ? 'Uploading...' : 'Upload' }}</button>
            </form>
            <p v-if="canManage" class="cd__hint">PDF, image, Word, Excel, CSV or text, up to {{ Math.round(maxKilobytes / 1024) }} MB each. Documents count towards the company storage.</p>

            <div v-if="loading" class="cd__empty">Loading documents...</div>
            <div v-else-if="items.length === 0" class="cd__empty">
                <strong>No documents have been uploaded yet</strong>
            </div>
            <div v-else class="cd__table-wrap">
                <table class="cd__table">
                    <thead>
                        <tr><th>File</th><th>Note</th><th class="num">Size</th><th>Uploaded by</th><th>Date</th><th /></tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.id">
                            <td><span class="cd__ext cd__ext--small">{{ extension(item.file_name) }}</span> <strong>{{ item.file_name }}</strong></td>
                            <td>{{ item.description || '-' }}</td>
                            <td class="num">{{ readableSize(item.size) }}</td>
                            <td>{{ item.uploaded_by || '-' }}</td>
                            <td>{{ item.created_at ? item.created_at.slice(0, 10) : '-' }}</td>
                            <td class="num">
                                <button type="button" class="cd__link" @click="download(item)">Download</button>
                                <button v-if="canManage" type="button" class="cd__link cd__link--danger" @click="remove(item)">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

<style scoped>
.cd {
    --cd-accent: #199683;
    --cd-accent-dark: #0f766e;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.cd__section {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

.cd__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.cd__title {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
}

.cd__subtitle,
.cd__hint {
    margin: 0.15rem 0 0;
    font-size: 0.85rem;
    color: #64748b;
}

.cd__count {
    min-width: 2rem;
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    background: rgba(25, 150, 131, 0.12);
    color: var(--cd-accent-dark);
    font-weight: 700;
    text-align: center;
}

.cd__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr));
    gap: 0.85rem;
}

.cd__card {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.95rem;
    background: #fff;
    color: inherit;
    text-decoration: none;
    transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
}

.cd__card:hover {
    border-color: rgba(25, 150, 131, 0.5);
    box-shadow: 0 8px 22px rgba(15, 118, 110, 0.12);
    transform: translateY(-1px);
}

.cd__thumb {
    display: flex;
    flex: 0 0 3.4rem;
    align-items: center;
    justify-content: center;
    width: 3.4rem;
    height: 3.4rem;
    overflow: hidden;
    border-radius: 0.75rem;
    background: #f1f5f9;
}

.cd__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.cd__ext {
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    color: var(--cd-accent-dark);
}

.cd__ext--small {
    display: inline-block;
    margin-right: 0.35rem;
    padding: 0.1rem 0.4rem;
    border-radius: 0.4rem;
    background: rgba(25, 150, 131, 0.12);
    font-size: 0.68rem;
}

.cd__meta {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
    font-size: 0.8rem;
    color: #64748b;
}

.cd__meta strong {
    color: #0f172a;
    font-size: 0.9rem;
}

.cd__file {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #94a3b8;
}

.cd__open {
    padding: 0.3rem 0.75rem;
    border-radius: 999px;
    background: rgba(25, 150, 131, 0.1);
    color: var(--cd-accent-dark);
    font-size: 0.8rem;
    font-weight: 700;
}

.cd__upload {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.75rem;
    padding: 0.85rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.95rem;
    background: #f8fafc;
}

.cd__field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    min-width: 14rem;
}

.cd__field--grow {
    flex: 1;
}

.cd__field label {
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}

.cd__button {
    padding: 0.5rem 1.2rem;
    border: 0;
    border-radius: 999px;
    background: linear-gradient(135deg, #199683, #0f766e);
    color: #fff;
    font-weight: 700;
    box-shadow: 0 6px 16px rgba(15, 118, 110, 0.25);
}

.cd__button:disabled {
    opacity: 0.6;
}

.cd__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    padding: 1.75rem 1rem;
    border: 1px dashed #cbd5e1;
    border-radius: 0.95rem;
    background: #f8fafc;
    color: #64748b;
    text-align: center;
}

.cd__table-wrap {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 0.95rem;
    background: #fff;
}

.cd__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.cd__table th {
    padding: 0.7rem 0.9rem;
    text-align: left;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.cd__table td {
    padding: 0.65rem 0.9rem;
    border-bottom: 1px solid #eef2f6;
    vertical-align: middle;
}

.cd__table tbody tr:last-child td {
    border-bottom: 0;
}

.cd__table .num {
    text-align: right;
    white-space: nowrap;
}

.cd__link {
    margin-left: 0.5rem;
    padding: 0;
    border: 0;
    background: none;
    color: var(--cd-accent-dark);
    font-weight: 600;
}

.cd__link--danger {
    color: #b91c1c;
}
</style>
