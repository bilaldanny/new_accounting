<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { Copy, History, KeyRound, Trash2 } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    /**
     * Built as one self-contained page, the same shape as "Settings > API Keys" (apikey/index.vue) —
     * the closest existing precedent for "a secret shown once on create, then a plain list" — rather
     * than this app's usual separate add/edit Vueform pages, since a webhook's secret needs that exact
     * reveal-once UX and nothing else about it is a multi-step form.
     */
    defineOptions({
        layout: {
            title: 'Webhooks',
            subtitle: 'Endpoints this app notifies when an event happens',
            breadcrumbs: [
                { title: 'Webhooks', href: 'NULL' },
            ],
        },
    });

    const EVENTS = [
        { value: 'invoice.created', label: 'Invoice created' },
        { value: 'subscription.renewed', label: 'Subscription renewed' },
        { value: 'subscription.cancelled', label: 'Subscription cancelled' },
    ];

    type WebhookRow = {
        id: number;
        url: string;
        events: string[];
        is_active: boolean;
        last_triggered_at: string | null;
        last_response_code: number | null;
        failure_count: number;
    };

    type Delivery = {
        id: number;
        event: string;
        response_code: number | null;
        success: boolean;
        attempted_at: string | null;
    };

    const page = usePage();
    const { Notify } = useCommons();

    const loading = ref(true);
    const saving = ref(false);
    const webhooks = ref<WebhookRow[]>([]);
    const url = ref('');
    const selectedEvents = ref<string[]>([]);
    const newSecret = ref('');
    const deliveries = ref<Delivery[] | null>(null);
    const deliveriesForUrl = ref('');

    const user = computed(() => page.props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(user.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string): boolean => isSuperadmin.value || (user.value?.permission_paths ?? []).includes(path);
    const canAdd = computed(() => can('/webhooks/add'));
    const canDelete = computed(() => can('/webhooks/delete'));

    function errorMessage(error: unknown): string {
        if (! window.axios.isAxiosError(error)) {
            return 'Unexpected error occurred';
        }

        const errors = Object.values(error.response?.data?.errors ?? {}).flat() as string[];

        return errors[0] || error.response?.data?.message || 'The request failed.';
    }

    async function load() {
        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.webhooks);
            webhooks.value = response.data?.data?.data ?? response.data?.data ?? [];
        } catch {
            Notify('Unable to load webhooks.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function createWebhook() {
        if (! url.value.trim() || ! selectedEvents.value.length) {
            Notify('Give the webhook a URL and at least one event.', 'alert');

            return;
        }

        saving.value = true;

        try {
            const response = await window.axios.post(API_ENDPOINTS.webhooks, {
                url: url.value.trim(),
                events: selectedEvents.value,
            });
            newSecret.value = String(response.data?.secret ?? '');
            url.value = '';
            selectedEvents.value = [];
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            saving.value = false;
        }
    }

    async function copySecret() {
        try {
            await navigator.clipboard.writeText(newSecret.value);
            Notify('Copied', 'success');
        } catch {
            Notify('Select the secret and copy it by hand.', 'info');
        }
    }

    async function toggleActive(webhook: WebhookRow) {
        try {
            await window.axios.post(`${API_ENDPOINTS.webhooks}/${webhook.id}`, {
                _method: 'PUT',
                url: webhook.url,
                events: webhook.events,
                is_active: ! webhook.is_active,
            });
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        }
    }

    async function remove(webhook: WebhookRow) {
        if (! window.confirm(`Delete the webhook for "${webhook.url}"?`)) {
            return;
        }

        try {
            await window.axios.delete(`${API_ENDPOINTS.webhooks}/${webhook.id}`);
            Notify('Webhook deleted', 'success');
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        }

        await load();
    }

    async function viewDeliveries(webhook: WebhookRow) {
        deliveriesForUrl.value = webhook.url;
        deliveries.value = null;

        try {
            const response = await window.axios.get(API_ENDPOINTS.webhookDeliveries(webhook.id));
            deliveries.value = response.data?.data ?? [];
        } catch {
            Notify('Unable to load deliveries.', 'alert');
            deliveries.value = [];
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Webhooks" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__body p-3 p-md-4">
                <div class="alert alert-info small">
                    <strong>How it works.</strong> When one of the events below happens, this app POSTs a signed JSON payload to your URL
                    (header <code>X-Webhook-Signature</code>, an HMAC-SHA256 of the body using your secret). The secret is shown only once.
                </div>

                <div v-if="newSecret" class="alert alert-success" data-test="new-webhook-secret">
                    <p class="mb-2 fw-semibold d-flex align-items-center gap-2"><KeyRound class="h-4 w-4" /> Webhook secret: copy it now, it will not be shown again.</p>
                    <div class="input-group input-group-sm">
                        <input :value="newSecret" type="text" class="form-control font-monospace" readonly aria-label="The new webhook secret" @focus="($event.target as HTMLInputElement).select()">
                        <button type="button" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" @click="copySecret"><Copy class="h-4 w-4" /> Copy</button>
                        <button type="button" class="btn btn-outline-secondary" @click="newSecret = ''">Done</button>
                    </div>
                </div>

                <form v-if="canAdd" class="row g-2 align-items-end mb-4" @submit.prevent="createWebhook">
                    <div class="col-md-5">
                        <label class="form-label" for="webhook-url">Endpoint URL</label>
                        <input id="webhook-url" v-model="url" type="url" maxlength="500" class="form-control form-control-sm" placeholder="https://example.com/hooks/accunivo">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Events</label>
                        <div class="d-flex flex-wrap gap-3">
                            <div v-for="event in EVENTS" :key="event.value" class="form-check">
                                <input :id="`event-${event.value}`" v-model="selectedEvents" class="form-check-input" type="checkbox" :value="event.value">
                                <label class="form-check-label small" :for="`event-${event.value}`">{{ event.label }}</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="saving">{{ saving ? 'Saving…' : 'Add webhook' }}</button>
                    </div>
                </form>

                <Loader v-if="loading" message="Loading webhooks…" />

                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Events</th>
                                <th>Status</th>
                                <th>Last triggered</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="webhook in webhooks" :key="webhook.id">
                                <td class="font-monospace small">{{ webhook.url }}</td>
                                <td>
                                    <span v-for="event in webhook.events" :key="event" class="badge bg-secondary-subtle text-secondary me-1">{{ event }}</span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm" :class="webhook.is_active ? 'btn-outline-success' : 'btn-outline-secondary'" @click="toggleActive(webhook)">
                                        {{ webhook.is_active ? 'Active' : 'Inactive' }}
                                    </button>
                                    <span v-if="webhook.failure_count > 0" class="badge bg-danger-subtle text-danger ms-1">{{ webhook.failure_count }} failed</span>
                                </td>
                                <td>{{ webhook.last_triggered_at || 'Never' }} <span v-if="webhook.last_response_code" class="text-muted">({{ webhook.last_response_code }})</span></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-outline-secondary btn-sm me-1" title="Deliveries" @click="viewDeliveries(webhook)">
                                        <History class="h-4 w-4" />
                                    </button>
                                    <button v-if="canDelete" type="button" class="btn btn-outline-danger btn-sm" title="Delete" @click="remove(webhook)">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="! webhooks.length">
                                <td colspan="5" class="text-center text-muted">No webhooks yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="deliveries !== null" class="mt-4">
                    <h3 class="h6">Recent deliveries — {{ deliveriesForUrl }}</h3>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Event</th>
                                    <th>Response</th>
                                    <th>Attempted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="delivery in deliveries" :key="delivery.id">
                                    <td>{{ delivery.event }}</td>
                                    <td>
                                        <span :class="delivery.success ? 'badge bg-success-subtle text-success' : 'badge bg-danger-subtle text-danger'">
                                            {{ delivery.response_code ?? 'No response' }}
                                        </span>
                                    </td>
                                    <td>{{ delivery.attempted_at }}</td>
                                </tr>
                                <tr v-if="! deliveries.length">
                                    <td colspan="3" class="text-center text-muted">No deliveries yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
