<script setup lang="ts">
    import { onMounted, reactive, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    const props = defineProps({
        companyId: { type: [String, Number], default: '' },
    });

    const { Notify } = useCommons();

    const form = reactive({
        tax_inclusive_pricing: false,
        enabled: false,
        environment: 'sandbox',
        pos_id: '',
        api_url: '',
        username: '',
        password: '',
        api_token: '',
        clear_credentials: false,
    });
    const registration = reactive({ ntn_no: '', strn_no: '', has_password: false, has_api_token: false, credentials_complete: false });
    const submissions = ref<Array<Record<string, any>>>([]);
    const busy = ref(false);

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function load() {
        if (! props.companyId) {
            return;
        }

        try {
            const params = { company_id: props.companyId };
            const data = (await window.axios.get('/api/fbr-settings', { params })).data?.data ?? {};

            Object.assign(form, {
                tax_inclusive_pricing: Boolean(data.tax_inclusive_pricing),
                enabled: Boolean(data.enabled),
                environment: data.environment ?? 'sandbox',
                pos_id: data.pos_id ?? '',
                api_url: data.api_url ?? '',
                username: data.username ?? '',
                password: '',
                api_token: '',
                clear_credentials: false,
            });
            Object.assign(registration, { ntn_no: data.ntn_no ?? '', strn_no: data.strn_no ?? '', has_password: Boolean(data.has_password), has_api_token: Boolean(data.has_api_token), credentials_complete: Boolean(data.credentials_complete) });
            submissions.value = (await window.axios.get('/api/fbr-submissions', { params })).data?.data?.data ?? [];
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    async function save() {
        busy.value = true;

        try {
            const response = await window.axios.put('/api/fbr-settings', { ...form, company_id: props.companyId });
            Notify('Tax and FBR settings saved', 'success');

            if (response.data?.warning) {
                Notify(response.data.warning, 'alert');
            }

            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    watch(() => props.companyId, load);
    onMounted(load);
</script>

<template>
    <div v-if="companyId" class="card mt-4" data-test="tax-registration-card">
        <div class="card-body">
            <h6 class="mb-3">Tax registration</h6>
            <p class="mb-2">
                <strong>NTN:</strong> {{ registration.ntn_no || 'not set' }} &nbsp; <strong>STRN:</strong> {{ registration.strn_no || 'not set' }}
                <small class="d-block text-muted">Kept on the company record (Company &gt; edit) and printed on the sales invoice when Invoice Settings allow it.</small>
            </p>

            <form class="row g-3" @submit.prevent="save">
                <div class="col-12">
                    <div class="form-check">
                        <input id="tax-inclusive-default" v-model="form.tax_inclusive_pricing" type="checkbox" class="form-check-input">
                        <label class="form-check-label" for="tax-inclusive-default">Prices include tax by default on new sales and purchases</label>
                    </div>
                </div>

                <div class="col-12">
                    <hr>
                    <h6>FBR e-invoicing</h6>
                    <div class="alert alert-danger mb-0 border-2" role="alert" data-test="fbr-warning">
                        <strong>Ye FBR integration structure hai, lekin LIVE/COMPLIANT nahi hai.</strong>
                        Agar aapka business FBR POS Fiscalization / Tier-1 Retailer scheme mein mandate hai, to apne tax consultant se verify karein aur live integration ke liye alag se confirm karein.
                        <span class="d-block mt-1 small">Nothing is sent to FBR, and the numbers shown as <code>FBR-STUB-...</code> are not FBR invoice numbers.</span>
                    </div>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input id="fbr-enabled" v-model="form.enabled" type="checkbox" class="form-check-input">
                        <label class="form-check-label" for="fbr-enabled">Record sales for FBR (stub only)</label>
                    </div>
                    <div v-if="form.enabled && ! registration.credentials_complete" class="alert alert-warning mt-2 mb-0" role="alert" data-test="fbr-stub-mode">
                        <strong>Stub mode.</strong> FBR credentials (username, password, API token and POS id) are not complete, so this only records a test FBR-STUB entry for each sale. It is not connected to FBR.
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Environment</label>
                    <select v-model="form.environment" class="form-select"><option value="sandbox">Sandbox</option><option value="production">Production</option></select>
                </div>
                <div class="col-md-3"><label class="form-label">POS id</label><input v-model="form.pos_id" class="form-control" maxlength="100"></div>
                <div class="col-md-6"><label class="form-label">API URL</label><input v-model="form.api_url" class="form-control" maxlength="255"></div>
                <div class="col-md-4"><label class="form-label">Username</label><input v-model="form.username" class="form-control" maxlength="150" autocomplete="off"></div>
                <div class="col-md-4">
                    <label class="form-label">Password</label>
                    <input v-model="form.password" type="password" class="form-control" autocomplete="new-password" :placeholder="registration.has_password ? 'saved (leave empty to keep)' : ''">
                </div>
                <div class="col-md-4">
                    <label class="form-label">API token</label>
                    <input v-model="form.api_token" type="password" class="form-control" autocomplete="new-password" :placeholder="registration.has_api_token ? 'saved (leave empty to keep)' : ''">
                </div>
                <div v-if="registration.has_password || registration.has_api_token" class="col-12">
                    <div class="form-check">
                        <input id="fbr-clear" v-model="form.clear_credentials" type="checkbox" class="form-check-input">
                        <label class="form-check-label" for="fbr-clear">Remove the saved credentials</label>
                    </div>
                </div>
                <div class="col-12"><button type="submit" class="btn btn-primary btn-sm" :disabled="busy">Save tax and FBR settings</button></div>
            </form>

            <div v-if="submissions.length" class="table-responsive mt-4">
                <h6>Latest FBR records</h6>
                <table class="table table-sm align-middle">
                    <thead><tr><th>Invoice</th><th>Status</th><th>Number</th><th>Tries</th></tr></thead>
                    <tbody>
                        <tr v-for="row in submissions" :key="row.id">
                            <td>{{ row.invoice_no }}</td>
                            <td>{{ row.status === 'stub' ? 'stub (not sent to FBR)' : row.status }}</td>
                            <td>{{ row.fbr_invoice_number ?? '-' }}</td>
                            <td>{{ row.attempts }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
