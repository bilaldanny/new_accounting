<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { Copy, KeyRound, Trash2 } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'API Keys',
            subtitle: 'Keys that let another program call this application',
            breadcrumbs: [
                {
                    title: 'API Keys',
                    href: 'NULL',
                },
            ],
        },
    });

    type ApiKey = {
        id: number;
        name: string;
        owner_name: string | null;
        owner_email: string | null;
        is_mine: boolean;
        created_at: string | null;
        last_used_at: string | null;
        expires_at: string | null;
        is_expired: boolean;
    };

    const page = usePage();
    const { Notify } = useCommons();

    const loading = ref(true);
    const saving = ref(false);
    const keys = ref<ApiKey[]>([]);
    const lifetimes = ref<number[]>([30, 90, 180, 365]);
    const maxPerUser = ref(20);
    const name = ref('');
    const expiresInDays = ref<number | ''>('');
    const newToken = ref('');

    const user = computed(() => page.props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(user.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string): boolean => isSuperadmin.value || (user.value?.permission_paths ?? []).includes(path);
    const canAdd = computed(() => can('/apikeys/add'));
    const canRevoke = computed(() => can('/apikeys/delete'));
    const showOwner = computed(() => keys.value.some((key) => ! key.is_mine));

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
            const response = await window.axios.get(API_ENDPOINTS.apiKeys);
            keys.value = response.data?.data ?? [];
            lifetimes.value = response.data?.lifetimes ?? lifetimes.value;
            maxPerUser.value = response.data?.max_per_user ?? maxPerUser.value;
        } catch {
            Notify('Unable to load the API keys.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function createKey() {
        if (name.value.trim().length < 2) {
            Notify('Give the key a name (at least 2 characters) so you can tell it apart later.', 'alert');

            return;
        }

        saving.value = true;

        try {
            const response = await window.axios.post(API_ENDPOINTS.apiKeys, {
                name: name.value.trim(),
                expires_in_days: expiresInDays.value === '' ? null : expiresInDays.value,
            });
            newToken.value = String(response.data?.token ?? '');
            name.value = '';
            expiresInDays.value = '';
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            saving.value = false;
        }
    }

    async function copyToken() {
        try {
            await navigator.clipboard.writeText(newToken.value);
            Notify('Copied', 'success');
        } catch {
            Notify('Select the key and copy it by hand.', 'info');
        }
    }

    async function revoke(key: ApiKey) {
        if (! window.confirm(`Revoke "${key.name}"? Anything using it stops working at once.`)) {
            return;
        }

        try {
            await window.axios.delete(`${API_ENDPOINTS.apiKeys}/${key.id}`);
            Notify('API key revoked', 'success');
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        }

        await load();
    }

    onMounted(load);
</script>

<template>
    <Head title="API Keys" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__body p-3 p-md-4">
                <div class="alert alert-info small">
                    <strong>How it works.</strong> A key acts as the person who made it: it can do exactly what they can, no more, and it is
                    shown only once. Send it with every request as <code>Authorization: Bearer &lt;key&gt;</code>. Keys cannot create other
                    keys, so make them here. Revoke a key that is lost or no longer needed.
                </div>

                <div v-if="newToken" class="alert alert-success" data-test="new-key">
                    <p class="mb-2 fw-semibold d-flex align-items-center gap-2"><KeyRound class="h-4 w-4" /> Your new key: copy it now, it will not be shown again.</p>
                    <div class="input-group input-group-sm">
                        <input :value="newToken" type="text" class="form-control font-monospace" readonly aria-label="The new API key" @focus="($event.target as HTMLInputElement).select()">
                        <button type="button" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" @click="copyToken"><Copy class="h-4 w-4" /> Copy</button>
                        <button type="button" class="btn btn-outline-secondary" @click="newToken = ''">Done</button>
                    </div>
                </div>

                <form v-if="canAdd" class="row g-2 align-items-end mb-4" @submit.prevent="createKey">
                    <div class="col-md-5">
                        <label class="form-label" for="apikey-name">Name</label>
                        <input id="apikey-name" v-model="name" type="text" maxlength="100" class="form-control form-control-sm" placeholder="e.g. Accounting export">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="apikey-expiry">Expires</label>
                        <select id="apikey-expiry" v-model="expiresInDays" class="form-select form-select-sm">
                            <option value="">Never</option>
                            <option v-for="days in lifetimes" :key="days" :value="days">In {{ days }} days</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="saving">{{ saving ? 'Creating…' : 'Create API key' }}</button>
                        <span class="text-muted small ms-2">Up to {{ maxPerUser }} per person</span>
                    </div>
                </form>

                <Loader v-if="loading" message="Loading API keys…" />

                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th v-if="showOwner">Owner</th>
                                <th>Created</th>
                                <th>Last used</th>
                                <th>Expires</th>
                                <th v-if="canRevoke" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="key in keys" :key="key.id">
                                <td>{{ key.name }}</td>
                                <td v-if="showOwner">{{ key.owner_name || '—' }} <small class="text-muted">{{ key.owner_email }}</small></td>
                                <td>{{ key.created_at || '—' }}</td>
                                <td>{{ key.last_used_at || 'Never' }}</td>
                                <td>
                                    <span v-if="key.is_expired" class="badge bg-danger-subtle text-danger">Expired {{ key.expires_at }}</span>
                                    <span v-else>{{ key.expires_at || 'Never' }}</span>
                                </td>
                                <td v-if="canRevoke" class="text-end">
                                    <button type="button" class="btn btn-outline-danger btn-sm" title="Revoke" @click="revoke(key)">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="! keys.length">
                                <td :colspan="4 + (showOwner ? 1 : 0) + (canRevoke ? 1 : 0)" class="text-center text-muted">No API keys yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
