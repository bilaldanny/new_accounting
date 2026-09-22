<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { Copy, KeyRound, RotateCcw, Trash2 } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Portal Users',
            subtitle: 'Accounts for customers and suppliers to see their own balance',
            breadcrumbs: [
                {
                    title: 'Portal Users',
                    href: 'NULL',
                },
            ],
        },
    });

    type PortalUser = { id: number; email: string; contact_id: number; contact_name: string | null; contact_code: string | null; contact_type: string | null; created_at: string | null };
    type ContactOption = { id: number; text?: string; name?: string; business_name?: string };

    const page = usePage();
    const { Notify } = useCommons();

    const loading = ref(true);
    const saving = ref(false);
    const users = ref<PortalUser[]>([]);
    const contacts = ref<ContactOption[]>([]);
    const contactId = ref<number | ''>('');
    const email = ref('');
    const secret = ref<{ email: string; password: string } | null>(null);

    const user = computed(() => page.props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(user.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string): boolean => isSuperadmin.value || (user.value?.permission_paths ?? []).includes(path);
    const canAdd = computed(() => can('/portalusers/add'));
    const canRemove = computed(() => can('/portalusers/delete'));

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
            const response = await window.axios.get(API_ENDPOINTS.portalUsers);
            users.value = response.data?.data ?? [];

            if (canAdd.value) {
                const [customers, suppliers] = await Promise.all([
                    window.axios.get(API_ENDPOINTS.fetchCustomers).catch(() => ({ data: [] })),
                    window.axios.get(API_ENDPOINTS.fetchSuppliers).catch(() => ({ data: [] })),
                ]);
                contacts.value = [...(customers.data ?? []), ...(suppliers.data ?? [])];
            }
        } catch {
            Notify('Unable to load the portal users.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function create() {
        if (contactId.value === '' || email.value.trim() === '') {
            Notify('Choose the customer or supplier and enter the email they will sign in with.', 'alert');

            return;
        }

        saving.value = true;

        try {
            const response = await window.axios.post(API_ENDPOINTS.portalUsers, { contact_id: contactId.value, email: email.value.trim() });
            secret.value = { email: email.value.trim(), password: String(response.data?.password ?? '') };
            email.value = '';
            contactId.value = '';
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            saving.value = false;
        }
    }

    async function reset(portalUser: PortalUser) {
        if (! window.confirm(`Give ${portalUser.email} a new password? The old one stops working.`)) {
            return;
        }

        try {
            const response = await window.axios.post(`${API_ENDPOINTS.portalUsers}/${portalUser.id}/reset-password`);
            secret.value = { email: portalUser.email, password: String(response.data?.password ?? '') };
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        }
    }

    async function remove(portalUser: PortalUser) {
        if (! window.confirm(`Remove ${portalUser.email}? They can no longer sign in.`)) {
            return;
        }

        try {
            await window.axios.delete(`${API_ENDPOINTS.portalUsers}/${portalUser.id}`);
            Notify('Portal user removed', 'success');
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        }

        await load();
    }

    async function copy() {
        try {
            await navigator.clipboard.writeText(secret.value?.password ?? '');
            Notify('Copied', 'success');
        } catch {
            Notify('Select the password and copy it by hand.', 'info');
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Portal Users" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__body p-3 p-md-4">
                <div class="alert alert-info small">
                    A portal user signs in with their email and sees only their own balance and recent invoices and payments: nothing else in the
                    system. A password is generated, shown once and never stored in a readable form.
                </div>

                <div v-if="secret" class="alert alert-success" data-test="portal-secret">
                    <p class="mb-2 fw-semibold d-flex align-items-center gap-2"><KeyRound class="h-4 w-4" /> Password for {{ secret.email }}: give it to them now, it will not be shown again.</p>
                    <div class="input-group input-group-sm">
                        <input :value="secret.password" type="text" class="form-control font-monospace" readonly aria-label="The generated password" @focus="($event.target as HTMLInputElement).select()">
                        <button type="button" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" @click="copy"><Copy class="h-4 w-4" /> Copy</button>
                        <button type="button" class="btn btn-outline-secondary" @click="secret = null">Done</button>
                    </div>
                </div>

                <form v-if="canAdd" class="row g-2 align-items-end mb-4" @submit.prevent="create">
                    <div class="col-md-5">
                        <label class="form-label" for="portal-contact">Customer or supplier</label>
                        <select id="portal-contact" v-model="contactId" class="form-select form-select-sm">
                            <option value="">Choose…</option>
                            <option v-for="contact in contacts" :key="contact.id" :value="contact.id">{{ contact.text ?? contact.business_name ?? contact.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="portal-email">Email they sign in with</label>
                        <input id="portal-email" v-model="email" type="email" maxlength="255" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="saving">{{ saving ? 'Creating…' : 'Create portal user' }}</button>
                    </div>
                </form>

                <Loader v-if="loading" message="Loading portal users…" />

                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Customer / supplier</th><th>Email</th><th>Created</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                            <tr v-for="portalUser in users" :key="portalUser.id">
                                <td>{{ portalUser.contact_name || '—' }} <small class="text-muted">{{ portalUser.contact_code }}</small></td>
                                <td>{{ portalUser.email }}</td>
                                <td>{{ portalUser.created_at || '—' }}</td>
                                <td class="text-end">
                                    <button v-if="canAdd" type="button" class="btn btn-outline-secondary btn-sm me-1" title="New password" @click="reset(portalUser)"><RotateCcw class="h-4 w-4" /></button>
                                    <button v-if="canRemove" type="button" class="btn btn-outline-danger btn-sm" title="Remove" @click="remove(portalUser)"><Trash2 class="h-4 w-4" /></button>
                                </td>
                            </tr>
                            <tr v-if="! users.length"><td colspan="4" class="text-center text-muted">No portal users yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
