<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Tax Exemptions',
            subtitle: 'Customers, suppliers and items that are charged no tax',
            breadcrumbs: [
                {
                    title: 'Tax Exemptions',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify } = useCommons();
    const { props } = usePage();

    const authUser = computed(() => props.auth?.user as { rolename?: string; company_id?: number | null; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string) => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes(path);

    const emptyForm = () => ({
        id: null as number | null,
        company_id: authUser.value?.company_id ?? ('' as number | string),
        name: '',
        scope: 'customer',
        contact_id: '' as number | string,
        product_id: '' as number | string,
        tax_id: '' as number | string,
        certificate_no: '',
        reason: '',
        valid_from: '',
        valid_to: '',
        is_active: true,
    });

    const rules = ref<Array<Record<string, any>>>([]);
    const companies = ref<Array<Record<string, any>>>([]);
    const contacts = ref<Array<Record<string, any>>>([]);
    const products = ref<Array<Record<string, any>>>([]);
    const taxes = ref<Array<Record<string, any>>>([]);
    const form = reactive(emptyForm());
    const editing = ref(false);
    const busy = ref(false);
    const search = ref('');
    const scopeFilter = ref('');

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function load() {
        rules.value = (await window.axios.get('/api/tax-exemptions', { params: { search: search.value, scope: scopeFilter.value || undefined, show_record: 200 } })).data?.data?.data ?? [];
    }

    async function loadLookups() {
        if (! form.company_id) {
            contacts.value = [];
            products.value = [];
            taxes.value = [];

            return;
        }

        const params = { company_id: form.company_id };
        const [customers, suppliers, items, taxList] = await Promise.all([
            window.axios.get('/api/fetchcustomers', { params }),
            window.axios.get('/api/fetchsuppliers', { params }),
            window.axios.get('/api/fetchproducts', { params }),
            window.axios.get('/api/fetchtaxes', { params: { ...params, with_groups: 1 } }),
        ]);

        const seen = new Set<number>();
        contacts.value = [...(customers.data ?? []), ...(suppliers.data ?? [])].filter((contact: Record<string, any>) => ! seen.has(contact.id) && seen.add(contact.id));
        products.value = items.data ?? [];
        taxes.value = taxList.data ?? [];
    }

    function startNew() {
        Object.assign(form, emptyForm());
        editing.value = true;
        void loadLookups();
    }

    function edit(rule: Record<string, any>) {
        Object.assign(form, emptyForm(), rule, {
            contact_id: rule.contact_id ?? '',
            product_id: rule.product_id ?? '',
            tax_id: rule.tax_id ?? '',
            certificate_no: rule.certificate_no ?? '',
            reason: rule.reason ?? '',
            valid_from: rule.valid_from ?? '',
            valid_to: rule.valid_to ?? '',
        });
        editing.value = true;
        void loadLookups();
    }

    async function save() {
        busy.value = true;
        const body: Record<string, any> = { ...form };

        for (const key of ['contact_id', 'product_id', 'tax_id', 'valid_from', 'valid_to', 'certificate_no', 'reason']) {
            body[key] = body[key] === '' ? null : body[key];
        }

        try {
            if (form.id) {
                await window.axios.put(`/api/tax-exemptions/${form.id}`, body);
            } else {
                await window.axios.post('/api/tax-exemptions', body);
            }

            Notify('Exemption saved', 'success');
            editing.value = false;
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function remove(rule: Record<string, any>) {
        if (! window.confirm(`Delete ${rule.name}?`)) {
            return;
        }

        try {
            await window.axios.delete(`/api/tax-exemptions/${rule.id}`);
            Notify('Exemption deleted', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    const contactName = (contact: Record<string, any>) => contact.business_name || contact.text || `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim();

    watch(() => form.company_id, loadLookups);

    onMounted(async () => {
        try {
            if (isSuperadmin.value) {
                companies.value = (await window.axios.get('/api/fetchcompanies')).data ?? [];
            }

            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    });
</script>

<template>
    <Head title="Tax Exemptions" />

    <div v-if="editing" class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h6>{{ form.id ? 'Edit exemption' : 'New exemption' }}</h6>
                <button type="button" class="btn-close" aria-label="Close" @click="editing = false" />
            </div>
            <form class="row g-3" @submit.prevent="save">
                <div v-if="isSuperadmin && ! form.id" class="col-md-4">
                    <label class="form-label">Company</label>
                    <select v-model="form.company_id" class="form-select" required>
                        <option value="">Choose a company</option>
                        <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.text ?? company.name }}</option>
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label">Name</label><input v-model="form.name" class="form-control" maxlength="150" required></div>
                <div class="col-md-3">
                    <label class="form-label">Applies to</label>
                    <select v-model="form.scope" class="form-select"><option value="customer">A customer or supplier</option><option value="item">An item</option></select>
                </div>
                <div v-if="form.scope === 'customer'" class="col-md-4">
                    <label class="form-label">Customer / supplier</label>
                    <select v-model="form.contact_id" class="form-select" required><option value="">Choose</option><option v-for="contact in contacts" :key="contact.id" :value="contact.id">{{ contactName(contact) }}</option></select>
                </div>
                <div v-else class="col-md-4">
                    <label class="form-label">Item</label>
                    <select v-model="form.product_id" class="form-select" required><option value="">Choose</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option></select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Exempt from</label>
                    <select v-model="form.tax_id" class="form-select"><option value="">Every tax</option><option v-for="tax in taxes" :key="tax.id" :value="tax.id">{{ tax.name }}</option></select>
                </div>
                <div class="col-md-3"><label class="form-label">Certificate no (optional)</label><input v-model="form.certificate_no" class="form-control" maxlength="100"></div>
                <div class="col-md-3"><label class="form-label">Valid from</label><input v-model="form.valid_from" type="date" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">Valid to</label><input v-model="form.valid_to" type="date" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">Reason (optional)</label><input v-model="form.reason" class="form-control" maxlength="255"></div>
                <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input id="te-active" v-model="form.is_active" type="checkbox" class="form-check-input"><label class="form-check-label" for="te-active">Active</label></div></div>
                <div class="col-12"><button type="submit" class="btn btn-primary btn-sm" :disabled="busy">Save exemption</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form class="d-flex gap-2 mb-3" @submit.prevent="load">
                <input v-model="search" type="search" class="form-control" placeholder="Name or certificate" style="max-width: 260px">
                <select v-model="scopeFilter" class="form-select" style="max-width: 200px" @change="load"><option value="">All</option><option value="customer">Customers</option><option value="item">Items</option></select>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <button v-if="can('/taxexemption/add')" type="button" class="btn btn-outline-primary btn-sm ms-auto" @click="startNew">New exemption</button>
            </form>
            <p v-if="! rules.length" class="text-muted mb-0">No exemptions yet.</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Name</th><th>Applies to</th><th>Tax</th><th>Certificate</th><th>Valid</th><th /></tr></thead>
                    <tbody>
                        <tr v-for="rule in rules" :key="rule.id">
                            <td>{{ rule.name }} <span v-if="! rule.is_active" class="badge bg-secondary">inactive</span></td>
                            <td>{{ rule.scope === 'item' ? `Item: ${rule.product_name}` : `Party: ${rule.contact_name}` }}</td>
                            <td>{{ rule.tax_name ?? 'Every tax' }}</td>
                            <td>{{ rule.certificate_no ?? '-' }}</td>
                            <td>{{ rule.valid_from ?? 'always' }} - {{ rule.valid_to ?? 'open' }}</td>
                            <td class="text-nowrap">
                                <button v-if="can('/taxexemption/:id/edit')" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="edit(rule)">Edit</button>
                                <button v-if="can('/taxexemption/delete') && ! rule.used" type="button" class="btn btn-outline-danger btn-sm" @click="remove(rule)">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
