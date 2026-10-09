<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Asset Categories',
            subtitle: 'Depreciation defaults and the ledger accounts each kind of fixed asset posts to',
            breadcrumbs: [
                {
                    title: 'Asset Categories',
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

    const accountFields = [
        { key: 'asset_coa_id', label: 'Asset account', required: true },
        { key: 'accumulated_coa_id', label: 'Accumulated depreciation account', required: true },
        { key: 'expense_coa_id', label: 'Depreciation expense account', required: true },
        { key: 'cwip_coa_id', label: 'Capital work in progress account', required: false },
        { key: 'revaluation_coa_id', label: 'Revaluation surplus account', required: false },
        { key: 'impairment_coa_id', label: 'Impairment loss account', required: false },
        { key: 'disposal_coa_id', label: 'Gain / loss on disposal account', required: false },
        { key: 'transfer_coa_id', label: 'Inter-branch transfer account', required: false },
    ];

    const emptyForm = () => ({
        id: null as number | null,
        company_id: authUser.value?.company_id ?? ('' as number | string),
        name: '',
        method: 'straight_line',
        useful_life_months: '' as number | string,
        rate: '' as number | string,
        salvage_percent: 0 as number | string,
        active: true,
        asset_coa_id: '' as number | string,
        accumulated_coa_id: '' as number | string,
        expense_coa_id: '' as number | string,
        cwip_coa_id: '' as number | string,
        revaluation_coa_id: '' as number | string,
        impairment_coa_id: '' as number | string,
        disposal_coa_id: '' as number | string,
        transfer_coa_id: '' as number | string,
    });

    const categories = ref<Array<Record<string, any>>>([]);
    const companies = ref<Array<Record<string, any>>>([]);
    const accounts = ref<Array<Record<string, any>>>([]);
    const form = reactive(emptyForm());
    const editing = ref(false);
    const busy = ref(false);

    const methodLabels: Record<string, string> = { straight_line: 'Straight-line', declining_balance: 'Declining balance', wdv: 'WDV (written-down value)' };

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function load() {
        categories.value = (await window.axios.get('/api/asset-categories', { params: { show_record: 100 } })).data?.data?.data ?? [];
    }

    async function loadAccounts() {
        accounts.value = form.company_id ? (await window.axios.get('/api/fetchchildaccounts', { params: { company_id: form.company_id } })).data ?? [] : [];
    }

    function startNew() {
        Object.assign(form, emptyForm());
        editing.value = true;
    }

    function edit(category: Record<string, any>) {
        Object.assign(form, emptyForm(), category, {
            useful_life_months: category.useful_life_months ?? '',
            rate: category.rate ?? '',
            cwip_coa_id: category.cwip_coa_id ?? '',
            revaluation_coa_id: category.revaluation_coa_id ?? '',
            impairment_coa_id: category.impairment_coa_id ?? '',
            disposal_coa_id: category.disposal_coa_id ?? '',
            transfer_coa_id: category.transfer_coa_id ?? '',
        });
        editing.value = true;
    }

    function payload() {
        const body: Record<string, any> = { ...form };

        for (const key of ['useful_life_months', 'rate', 'cwip_coa_id', 'revaluation_coa_id', 'impairment_coa_id', 'disposal_coa_id', 'transfer_coa_id']) {
            body[key] = body[key] === '' ? null : body[key];
        }

        return body;
    }

    async function save() {
        busy.value = true;

        try {
            if (form.id) {
                await window.axios.put(`/api/asset-categories/${form.id}`, payload());
            } else {
                await window.axios.post('/api/asset-categories', payload());
            }

            Notify('Category saved', 'success');
            editing.value = false;
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function remove(category: Record<string, any>) {
        if (! window.confirm(`Delete the category "${category.name}"?`)) {
            return;
        }

        try {
            await window.axios.delete(`/api/asset-categories/${category.id}`);
            Notify('Category deleted', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    watch(() => form.company_id, loadAccounts);

    onMounted(async () => {
        try {
            if (isSuperadmin.value) {
                companies.value = (await window.axios.get('/api/fetchcompanies')).data ?? [];
            }

            await load();
            await loadAccounts();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    });
</script>

<template>
    <Head title="Asset Categories" />

    <div v-if="editing" class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h6>{{ form.id ? 'Edit category' : 'New category' }}</h6>
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
                <div class="col-md-4">
                    <label class="form-label">Name</label>
                    <input v-model="form.name" class="form-control" maxlength="150" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Depreciation method</label>
                    <select v-model="form.method" class="form-select">
                        <option v-for="(label, key) in methodLabels" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <div v-if="form.method !== 'wdv'" class="col-md-3">
                    <label class="form-label">Useful life (months)</label>
                    <input v-model="form.useful_life_months" type="number" min="1" class="form-control">
                </div>
                <div v-if="form.method !== 'straight_line'" class="col-md-3">
                    <label class="form-label">{{ form.method === 'wdv' ? 'Yearly rate %' : 'Declining factor (1 to 5)' }}</label>
                    <input v-model="form.rate" type="number" step="0.01" min="0.01" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Salvage value %</label>
                    <input v-model="form.salvage_percent" type="number" step="0.01" min="0" max="99" class="form-control">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check"><input id="cat-active" v-model="form.active" type="checkbox" class="form-check-input"><label class="form-check-label" for="cat-active">Active</label></div>
                </div>

                <div v-for="field in accountFields" :key="field.key" class="col-md-4">
                    <label class="form-label">{{ field.label }}{{ field.required ? '' : ' (optional)' }}</label>
                    <select v-model="(form as Record<string, any>)[field.key]" class="form-select" :required="field.required">
                        <option value="">{{ field.required ? 'Choose an account' : 'None' }}</option>
                        <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.text }}</option>
                    </select>
                </div>

                <div class="col-12"><button type="submit" class="btn btn-primary btn-sm" :disabled="busy">Save category</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between mb-3">
                <span class="text-muted">Accounts are picked from the chart of accounts; create them there first.</span>
                <button v-if="can('/assetcategory/add')" type="button" class="btn btn-primary btn-sm" @click="startNew">New category</button>
            </div>
            <p v-if="! categories.length" class="text-muted mb-0">No categories yet.</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Name</th><th>Method</th><th class="text-end">Life (months)</th><th class="text-end">Rate</th><th>Asset account</th><th>Accumulated depreciation</th><th>Expense</th><th class="text-end">Assets</th><th /></tr></thead>
                    <tbody>
                        <tr v-for="category in categories" :key="category.id">
                            <td>{{ category.name }} <span v-if="! category.active" class="badge bg-secondary">inactive</span></td>
                            <td>{{ methodLabels[category.method] }}</td>
                            <td class="text-end">{{ category.useful_life_months ?? '-' }}</td>
                            <td class="text-end">{{ category.rate ?? '-' }}</td>
                            <td>{{ category.asset_account }}</td><td>{{ category.accumulated_account }}</td><td>{{ category.expense_account }}</td>
                            <td class="text-end">{{ category.asset_count }}</td>
                            <td class="text-nowrap">
                                <button v-if="can('/assetcategory/:id/edit')" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="edit(category)">Edit</button>
                                <button v-if="can('/assetcategory/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(category)">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
