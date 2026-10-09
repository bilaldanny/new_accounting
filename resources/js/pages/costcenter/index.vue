<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Cost Centers',
            subtitle: 'Places costs and revenue are attributed to, whatever the branch',
            breadcrumbs: [
                {
                    title: 'Cost Centers',
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
        code: '',
        name: '',
        type: 'cost',
        parent_id: '' as number | string,
        branch_id: '' as number | string,
        department_id: '' as number | string,
        active: true,
    });

    const centers = ref<Array<Record<string, any>>>([]);
    const companies = ref<Array<Record<string, any>>>([]);
    const branches = ref<Array<Record<string, any>>>([]);
    const departments = ref<Array<Record<string, any>>>([]);
    const form = reactive(emptyForm());
    const editing = ref(false);
    const busy = ref(false);
    const search = ref('');

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function load() {
        centers.value = (await window.axios.get('/api/cost-centers', { params: { search: search.value, show_record: 200 } })).data?.data?.data ?? [];
    }

    async function loadLookups() {
        branches.value = form.company_id ? (await window.axios.get('/api/fetchbranches', { params: { company_id: form.company_id } })).data ?? [] : [];
        departments.value = form.company_id ? (await window.axios.get('/api/fetchdepartments', { params: { company_id: form.company_id } })).data ?? [] : [];
    }

    function startNew() {
        Object.assign(form, emptyForm());
        editing.value = true;
    }

    function edit(center: Record<string, any>) {
        Object.assign(form, emptyForm(), center, { parent_id: center.parent_id ?? '', branch_id: center.branch_id ?? '', department_id: center.department_id ?? '' });
        editing.value = true;
    }

    async function save() {
        busy.value = true;
        const body: Record<string, any> = { ...form };

        for (const key of ['parent_id', 'branch_id', 'department_id']) {
            body[key] = body[key] === '' ? null : body[key];
        }

        try {
            if (form.id) {
                await window.axios.put(`/api/cost-centers/${form.id}`, body);
            } else {
                await window.axios.post('/api/cost-centers', body);
            }

            Notify('Cost center saved', 'success');
            editing.value = false;
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function remove(center: Record<string, any>) {
        if (! window.confirm(`Delete ${center.code} - ${center.name}?`)) {
            return;
        }

        try {
            await window.axios.delete(`/api/cost-centers/${center.id}`);
            Notify('Cost center deleted', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    const parentOptions = computed(() => centers.value.filter((center) => center.id !== form.id && center.company_id === form.company_id));

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
    <Head title="Cost Centers" />

    <div v-if="editing" class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h6>{{ form.id ? 'Edit cost center' : 'New cost center' }}</h6>
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
                <div class="col-md-2"><label class="form-label">Code</label><input v-model="form.code" class="form-control" maxlength="40" required></div>
                <div class="col-md-4"><label class="form-label">Name</label><input v-model="form.name" class="form-control" maxlength="150" required></div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select v-model="form.type" class="form-select"><option value="cost">Cost center</option><option value="profit">Profit center</option></select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Under</label>
                    <select v-model="form.parent_id" class="form-select"><option value="">Top level</option><option v-for="center in parentOptions" :key="center.id" :value="center.id">{{ center.text }}</option></select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Branch (optional)</label>
                    <select v-model="form.branch_id" class="form-select"><option value="">Any branch</option><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.text ?? branch.name }}</option></select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Department (optional)</label>
                    <select v-model="form.department_id" class="form-select"><option value="">None</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.text ?? department.name }}</option></select>
                </div>
                <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input id="cc-active" v-model="form.active" type="checkbox" class="form-check-input"><label class="form-check-label" for="cc-active">Active</label></div></div>
                <div class="col-12"><button type="submit" class="btn btn-primary btn-sm" :disabled="busy">Save cost center</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form class="d-flex gap-2 mb-3" @submit.prevent="load">
                <input v-model="search" type="search" class="form-control" placeholder="Code or name" style="max-width: 260px">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <button v-if="can('/costcenter/add')" type="button" class="btn btn-outline-primary btn-sm ms-auto" @click="startNew">New cost center</button>
            </form>
            <p v-if="! centers.length" class="text-muted mb-0">No cost centers yet.</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Under</th><th>Branch</th><th>Department</th><th /></tr></thead>
                    <tbody>
                        <tr v-for="center in centers" :key="center.id">
                            <td>{{ center.code }}</td><td>{{ center.name }} <span v-if="! center.active" class="badge bg-secondary">inactive</span></td>
                            <td>{{ center.type === 'profit' ? 'Profit' : 'Cost' }}</td><td>{{ center.parent_name ?? '-' }}</td><td>{{ center.branch_name ?? '-' }}</td><td>{{ center.department_name ?? '-' }}</td>
                            <td class="text-nowrap">
                                <button v-if="can('/costcenter/:id/edit')" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="edit(center)">Edit</button>
                                <button v-if="can('/costcenter/delete') && ! center.used" type="button" class="btn btn-outline-danger btn-sm" @click="remove(center)">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
