<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Messaging Settings',
            subtitle: 'The provider that sends receipts by WhatsApp and SMS',
            breadcrumbs: [{ title: 'Messaging Settings', href: 'NULL' }],
        },
    });

    type Channel = {
        channel: string; provider: string; endpoint: string | null; sender: string | null; is_active: boolean; has_credentials: boolean;
        configured: boolean; credentials?: string;
        options?: Record<string, string>;
    };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const rows = ref<Channel[]>([]);

    async function load() {
        rows.value = ((await run(() => window.axios.get('/api/messaging-settings', { params: scope() })))?.data?.data ?? []).map((row: Channel) => ({ ...row, credentials: '', options: { ...(row.options ?? {}) } }));
    }

    async function save(row: Channel) {
        const body: Record<string, unknown> = { ...scope(), provider: row.provider, endpoint: row.endpoint || null, sender: row.sender || null, is_active: row.is_active, options: row.options ?? {} };

        if (row.credentials) {
            body.credentials = row.credentials;
        }

        if (await run(() => window.axios.put(`/api/messaging-settings/${row.channel}`, body), 'Saved')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Messaging Settings" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="alert alert-info py-2 small">
            Receipts go by WhatsApp or SMS only through a provider you configure here. The "webhook" provider posts the recipient and the message as JSON to your endpoint
            (for example a gateway or a Zapier hook). With no provider the receipt link is still created but nothing is sent.
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div v-for="row in rows" :key="row.channel" class="border rounded p-3 mb-3">
            <h6 class="text-uppercase">{{ row.channel }} <span class="badge ms-1" :class="row.configured ? 'text-bg-success' : 'text-bg-secondary'">{{ row.configured ? 'ready' : 'not configured' }}</span></h6>
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small" :for="`ms-provider-${row.channel}`">Provider</label>
                    <select :id="`ms-provider-${row.channel}`" v-model="row.provider" class="form-select form-select-sm"><option value="none">None</option><option value="webhook">Webhook</option><option value="whatsapp_cloud">WhatsApp Cloud API (Meta)</option><option value="twilio">Twilio SMS</option></select>
                </div>
                <div class="col-md-4"><label class="form-label small" :for="`ms-endpoint-${row.channel}`">Endpoint URL</label><input :id="`ms-endpoint-${row.channel}`" v-model="row.endpoint" type="url" class="form-control form-control-sm" placeholder="https://" /></div>
                <div class="col-md-3"><label class="form-label small" :for="`ms-cred-${row.channel}`">Bearer token</label><input :id="`ms-cred-${row.channel}`" v-model="row.credentials" type="password" autocomplete="off" class="form-control form-control-sm" :placeholder="row.has_credentials ? 'Keep current' : ''" /></div>
                <div class="col-md-2"><label class="form-label small" :for="`ms-sender-${row.channel}`">Sender</label><input :id="`ms-sender-${row.channel}`" v-model="row.sender" class="form-control form-control-sm" /></div>
                <div class="col-md-1 d-flex align-items-end"><label class="form-check-label small"><input v-model="row.is_active" type="checkbox" class="form-check-input me-1" />On</label></div>
            </div>
            <div v-if="row.provider === 'whatsapp_cloud'" class="row g-2 mt-1">
                <div class="col-md-4"><label class="form-label small" :for="`ms-tpl-${row.channel}`">Approved template name (needed to start a conversation)</label><input :id="`ms-tpl-${row.channel}`" v-model="row.options!.template" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" :for="`ms-lang-${row.channel}`">Template language</label><input :id="`ms-lang-${row.channel}`" v-model="row.options!.language" class="form-control form-control-sm" placeholder="en" /></div>
                <div class="col-md-12 small text-muted">Sender is the WhatsApp phone number id, the token is the permanent access token; leave the endpoint empty for graph.facebook.com.</div>
            </div>
            <div v-if="row.provider === 'twilio'" class="small text-muted mt-1">Token is AccountSid:AuthToken; Sender is the sending number (+...) or a Messaging Service SID; leave the endpoint empty for api.twilio.com.</div>
            <div v-if="row.provider !== 'none' && row.provider !== 'webhook'" class="row g-2 mt-1">
                <div class="col-md-2"><label class="form-label small" :for="`ms-cc-${row.channel}`">Default country code</label><input :id="`ms-cc-${row.channel}`" v-model="row.options!.country_code" class="form-control form-control-sm" placeholder="92" /></div>
            </div>
            <button v-if="can('/messagingsetting/edit')" type="button" class="btn btn-dark btn-sm mt-3" @click="save(row)">Save</button>
        </div>
    </div></div></div>
</template>
