<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Budgets',
            subtitle: 'Planned amounts by branch, cost center and account, for a month or a whole year. Planning only: nothing is posted to the ledger.',
            breadcrumbs: [
                {
                    title: 'Budgets',
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

    const thisYear = new Date().getFullYear();

    const emptyForm = () => ({
        id: null as number | null,
        company_id: authUser.value?.company_id ?? ('' as number | string),
        branch_id: '' as number | string,
        cost_center_id: '' as number | string,
        coa_id: '' as number | string,
        year: thisYear as number | string,
        month: '' as number | string,
        amount: '' as number | string,
        note: '',
    });

    const budgets = ref<Array<Record<string, any>>>([]);
    const companies = ref<Array<Record<string, any>>>([]);
    const branches = ref<Array<Record<string, any>>>([]);
    const costCenters = ref<Array<Record<string, any>>>([]);
    const accounts = ref<Array<Record<string, any>>>([]);
    const form = reactive(emptyForm());
    const editing = ref(false);
    const busy = ref(false);
    const filters = reactive({ year: thisYear as number | string, trashed: false });

    const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const money = (value: unknown): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const budgetAccounts = computed(() => accounts.value.filter((account) => ['4', '5', '6'].includes(String(account.code ?? '').charAt(0))));

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function load() {
        budgets.value = (await window.axios.get('/api/budgets', { params: { year: filters.year || undefined, trashed: filters.trashed ? 1 : undefined, show_record: 200 } })).data?.data?.data ?? [];
    }

    async function loadLookups() {
        const company = form.company_id;
        branches.value = company ? (await window.axios.get('/api/fetchbranches', { params: { company_id: company } })).data ?? [] : [];
        costCenters.value = company ? (await window.axios.get('/api/fetchcostcenters', { params: { company_id: company } })).data ?? [] : [];
        accounts.value = company ? (await window.axios.get('/api/fetchchildaccounts', { params: { company_id: company } })).data ?? [] : [];
    }

    function startNew() {
        Object.assign(form, emptyForm());
        editing.value = true;
    }

    function edit(budget: Record<string, any>) {
        Object.assign(form, emptyForm(), budget, {
            branch_id: budget.branch_id ?? '', cost_center_id: budget.cost_center_id ?? '', coa_id: budget.coa_id ?? '', month: budget.month ?? '', note: budget.note ?? '',
        });
        editing.value = true;
    }

    async function save() {
        busy.value = true;
        const body: Record<string, any> = { ...form };

        for (const key of ['branch_id', 'cost_center_id', 'coa_id', 'month', 'note']) {
            body[key] = body[key] === '' ? null : body[key];
        }

        try {
            if (form.id) {
                await window.axios.put(`/api/budgets/${form.id}`, body);
            } else {
                await window.axios.post('/api/budgets', body);
            }

            Notify('Budget saved', 'success');
            editing.value = false;
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function remove(budget: Record<string, any>) {
        if (! window.confirm(`Delete the ${budget.period} budget of ${money(budget.amount)}?`)) {
            return;
        }

        try {
            await window.axios.delete(`/api/budgets/${budget.id}`);
            Notify('Budget deleted', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    async function restore(budget: Record<string, any>) {
        try {
            await window.axios.post(`/api/budgets/${budget.id}/restore`);
            Notify('Budget restored', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    watch(() => form.company_id, loadLookups);

    onMounted(async () => {
        try {
            if (isSuperadmin.value) {
                companies.value = (await window.axios.get('/api/fetchcompanies')).data ?? [];
            }

            await Promise.all([load(), loadLookups()]);
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    });
</script>

<template>
    <Head title="Budgets" />

    <div v-if="editing" class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h6>{{ form.id ? 'Edit budget' : 'New budget' }}</h6>
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
                <div class="col-md-2"><label class="form-label">Year</label><input v-model="form.year" type="number" min="2000" max="2100" class="form-control" required></div>
                <div class="col-md-3">
                    <label class="form-label">Month</label>
                    <select v-model="form.month" class="form-select"><option value="">Whole year (a twelfth each month)</option><option v-for="(name, index) in months" :key="name" :value="index + 1">{{ name }}</option></select>
                </div>
                <div class="col-md-3"><label class="form-label">Amount</label><input v-model="form.amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
                <div class="col-md-4">
                    <label class="form-label">Branch (optional)</label>
                    <select v-model="form.branch_id" class="form-select"><option value="">Whole company</option><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.text ?? branch.name }}</option></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cost center (optional)</label>
                    <select v-model="form.cost_center_id" class="form-select"><option value="">Any / none</option><option v-for="center in costCenters" :key="center.id" :value="center.id">{{ center.text }}</option></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Account (optional)</label>
                    <select v-model="form.coa_id" class="form-select"><option value="">All expenses</option><option v-for="account in budgetAccounts" :key="account.id" :value="account.id">{{ account.text }}</option></select>
                </div>
                <div class="col-md-8"><label class="form-label">Note</label><input v-model="form.note" class="form-control" maxlength="255"></div>
                <div class="col-12">
                    <p class="text-muted small">A budget on an account compares with that account (an expense budget with what was spent, a revenue budget with what was earned). With no account it covers all expenses of the branch or cost center. Nothing is posted to the ledger.</p>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="busy">Save budget</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form class="d-flex flex-wrap gap-2 align-items-center mb-3" @submit.prevent="load">
                <input v-model="filters.year" type="number" min="2000" max="2100" class="form-control" placeholder="Year" style="max-width: 120px">
                <div class="form-check"><input id="bud-trash" v-model="filters.trashed" type="checkbox" class="form-check-input" @change="load"><label class="form-check-label" for="bud-trash">Show deleted</label></div>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <button v-if="can('/budget/add')" type="button" class="btn btn-outline-primary btn-sm ms-auto" @click="startNew">New budget</button>
            </form>
            <p v-if="! budgets.length" class="text-muted mb-0">No budgets found.</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Period</th><th>Branch</th><th>Cost center</th><th>Account</th><th class="text-end">Amount</th><th>Note</th><th /></tr></thead>
                    <tbody>
                        <tr v-for="budget in budgets" :key="budget.id">
                            <td>{{ budget.period }}</td><td>{{ budget.branch_name ?? 'Whole company' }}</td><td>{{ budget.cost_center_name ?? '-' }}</td><td>{{ budget.account_name ?? 'All expenses' }}</td>
                            <td class="text-end">{{ money(budget.amount) }}</td><td>{{ budget.note ?? '' }}</td>
                            <td class="text-nowrap">
                                <template v-if="! budget.deleted">
                                    <button v-if="can('/budget/:id/edit')" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="edit(budget)">Edit</button>
                                    <button v-if="can('/budget/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(budget)">Delete</button>
                                </template>
                                <button v-else-if="can('/budget/restore')" type="button" class="btn btn-outline-primary btn-sm" @click="restore(budget)">Restore</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
