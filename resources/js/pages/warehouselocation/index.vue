<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Warehouse Locations',
            subtitle: 'Zones, racks, shelves and bins inside a warehouse. Master data only: no stock is held per location yet.',
            breadcrumbs: [
                {
                    title: 'Warehouse Locations',
                    href: 'NULL',
                },
            ],
        },
    });

    const { Notify } = useCommons();
    const { props } = usePage();

    const authUser = computed(() => props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string) => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes(path);

    const types = ['zone', 'rack', 'shelf', 'bin'];
    const typeLabels: Record<string, string> = { zone: 'Zone', rack: 'Rack', shelf: 'Shelf', bin: 'Bin' };

    const emptyForm = () => ({ id: null as number | null, warehouse_id: '' as number | string, type: 'zone', code: '', name: '', parent_id: '' as number | string, active: true });

    const warehouses = ref<Array<Record<string, any>>>([]);
    const locations = ref<Array<Record<string, any>>>([]);
    const form = reactive(emptyForm());
    const editing = ref(false);
    const busy = ref(false);
    const filterWarehouse = ref<number | string>('');

    const parentOptions = computed(() => locations.value.filter((location) => location.warehouse_id === form.warehouse_id && location.id !== form.id && types.indexOf(location.type) < types.indexOf(form.type)));

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function load() {
        locations.value = (await window.axios.get('/api/warehouse-locations', { params: { warehouse_id: filterWarehouse.value || undefined, show_record: 300 } })).data?.data?.data ?? [];
    }

    function startNew() {
        Object.assign(form, emptyForm(), { warehouse_id: filterWarehouse.value });
        editing.value = true;
    }

    function edit(location: Record<string, any>) {
        Object.assign(form, emptyForm(), location, { parent_id: location.parent_id ?? '' });
        editing.value = true;
    }

    async function save() {
        busy.value = true;
        const body: Record<string, any> = { ...form, parent_id: form.parent_id === '' ? null : form.parent_id };

        try {
            if (form.id) {
                await window.axios.put(`/api/warehouse-locations/${form.id}`, body);
            } else {
                await window.axios.post('/api/warehouse-locations', body);
            }

            Notify('Location saved', 'success');
            editing.value = false;
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    async function remove(location: Record<string, any>) {
        if (! window.confirm(`Delete ${location.path}?`)) {
            return;
        }

        try {
            await window.axios.delete(`/api/warehouse-locations/${location.id}`);
            Notify('Location deleted', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    }

    watch(filterWarehouse, load);

    onMounted(async () => {
        try {
            warehouses.value = (await window.axios.get('/api/fetchwarehouses')).data ?? [];
            await load();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        }
    });
</script>

<template>
    <Head title="Warehouse Locations" />

    <div v-if="editing" class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h6>{{ form.id ? 'Edit location' : 'New location' }}</h6>
                <button type="button" class="btn-close" aria-label="Close" @click="editing = false" />
            </div>
            <form class="row g-3" @submit.prevent="save">
                <div class="col-md-3">
                    <label class="form-label">Warehouse</label>
                    <select v-model="form.warehouse_id" class="form-select" :disabled="Boolean(form.id)" required>
                        <option value="">Choose a warehouse</option>
                        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.text ?? warehouse.name }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Kind</label>
                    <select v-model="form.type" class="form-select"><option v-for="type in types" :key="type" :value="type">{{ typeLabels[type] }}</option></select>
                </div>
                <div class="col-md-2"><label class="form-label">Code</label><input v-model="form.code" class="form-control" maxlength="40" required></div>
                <div class="col-md-3"><label class="form-label">Name</label><input v-model="form.name" class="form-control" maxlength="150" required></div>
                <div class="col-md-2 d-flex align-items-end"><div class="form-check"><input id="loc-active" v-model="form.active" type="checkbox" class="form-check-input"><label class="form-check-label" for="loc-active">Active</label></div></div>
                <div class="col-md-4">
                    <label class="form-label">Inside</label>
                    <select v-model="form.parent_id" class="form-select"><option value="">Top level</option><option v-for="location in parentOptions" :key="location.id" :value="location.id">{{ typeLabels[location.type] }}: {{ location.path }}</option></select>
                </div>
                <div class="col-12"><button type="submit" class="btn btn-primary btn-sm" :disabled="busy">Save location</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="d-flex gap-2 mb-3">
                <select v-model="filterWarehouse" class="form-select" style="max-width: 280px"><option value="">All warehouses</option><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.text ?? warehouse.name }}</option></select>
                <button v-if="can('/warehouselocation/add')" type="button" class="btn btn-outline-primary btn-sm ms-auto" @click="startNew">New location</button>
            </div>
            <p v-if="! locations.length" class="text-muted mb-0">No locations yet.</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Warehouse</th><th>Path</th><th>Kind</th><th>Code</th><th /></tr></thead>
                    <tbody>
                        <tr v-for="location in locations" :key="location.id">
                            <td>{{ location.warehouse_name }}</td>
                            <td>{{ location.path }} <span v-if="! location.active" class="badge bg-secondary">inactive</span></td>
                            <td>{{ typeLabels[location.type] }}</td><td>{{ location.code }}</td>
                            <td class="text-nowrap">
                                <button v-if="can('/warehouselocation/:id/edit')" type="button" class="btn btn-outline-secondary btn-sm me-1" @click="edit(location)">Edit</button>
                                <button v-if="can('/warehouselocation/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(location)">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
