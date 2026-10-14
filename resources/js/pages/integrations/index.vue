<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Integrations',
            subtitle: 'Couriers, payment gateway, bank feed and the API reference',
            breadcrumbs: [{ title: 'Integrations', href: 'NULL' }],
        },
    });

    type Account = { id: number; kind: string; provider: string; is_active: boolean; has_credentials: boolean; options: Record<string, string> };

    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const accounts = ref<Account[]>([]);
    const providers = ref<Record<string, string[]>>({});
    const form = ref({ kind: 'courier', provider: 'postex', credentials: '', endpoint: '', is_active: true });
    const hint: Record<string, string> = {
        postex: 'JSON: {"token": "..."}', leopards: 'JSON: {"api_key": "...", "api_password": "..."}', stripe: 'JSON: {"secret_key": "sk_...", "webhook_secret": "whsec_..."}', http_json: 'JSON: {"token": "..."} and the feed address below',
    };

    async function load() {
        const response = await run(() => window.axios.get('/api/integrations/accounts', { params: scope() }));
        accounts.value = response?.data?.data ?? [];
        providers.value = response?.data?.providers ?? {};
    }

    async function save() {
        let credentials: Record<string, unknown> | undefined;

        if (form.value.credentials.trim() !== '') {
            try {
                credentials = JSON.parse(form.value.credentials);
            } catch {
                window.alert('The credentials must be valid JSON.');

                return;
            }
        }

        const body = { ...scope(), kind: form.value.kind, provider: form.value.provider, credentials, is_active: form.value.is_active, options: form.value.endpoint ? { endpoint: form.value.endpoint } : {} };

        if (await run(() => window.axios.put('/api/integrations/accounts', body), 'Saved')) {
            form.value.credentials = '';
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Integrations" />

    <div class="container-fluid py-3">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        <div class="alert alert-warning py-2 small">These adapters are built to the providers' published documentation and tested against recorded responses; none has been used against a live account yet. Try each with a test account first.</div>

        <div v-if="can('/integrations/manage')" class="border rounded p-3 mb-4">
            <h6>Add or update an account</h6>
            <div class="row g-2">
                <div class="col-md-2"><select v-model="form.kind" class="form-select form-select-sm" aria-label="Kind" @change="form.provider = providers[form.kind]?.[0] ?? ''"><option v-for="(list, kind) in providers" :key="kind" :value="kind">{{ kind }}</option></select></div>
                <div class="col-md-2"><select v-model="form.provider" class="form-select form-select-sm" aria-label="Provider"><option v-for="provider in providers[form.kind] ?? []" :key="provider" :value="provider">{{ provider }}</option></select></div>
                <div class="col-md-4"><input v-model="form.credentials" class="form-control form-control-sm" :placeholder="hint[form.provider] ?? 'JSON credentials'" aria-label="Credentials" autocomplete="off" /></div>
                <div v-if="form.kind === 'bankfeed'" class="col-md-3"><input v-model="form.endpoint" class="form-control form-control-sm" placeholder="https://feed address" aria-label="Feed address" /></div>
                <div class="col-md-1"><label class="small"><input v-model="form.is_active" type="checkbox" class="me-1" />On</label></div>
                <div class="col-md-1"><button type="button" class="btn btn-dark btn-sm" @click="save">Save</button></div>
            </div>
        </div>

        <table class="table table-sm mb-4">
            <thead><tr><th>Kind</th><th>Provider</th><th>Credentials</th><th>Status</th></tr></thead>
            <tbody>
                <tr v-for="account in accounts" :key="account.id"><td class="text-capitalize">{{ account.kind }}</td><td>{{ account.provider }}</td><td>{{ account.has_credentials ? 'stored (hidden)' : 'missing' }}</td><td>{{ account.is_active ? 'on' : 'off' }}</td></tr>
                <tr v-if="! accounts.length"><td colspan="4" class="text-center text-muted">No integration is set up.</td></tr>
            </tbody>
        </table>

        <div class="border rounded p-3">
            <h6>API reference</h6>
            <a class="btn btn-outline-dark btn-sm me-2" href="/api/docs/openapi" target="_blank">OpenAPI (Swagger) JSON</a>
            <a class="btn btn-outline-dark btn-sm" href="/api/docs/postman" target="_blank">Postman collection</a>
            <div class="small text-muted mt-2">Generated from the routes. Sign in first; use an API key as the bearer token.</div>
        </div>
    </div>
</template>
