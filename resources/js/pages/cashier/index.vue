<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Cashiers',
            subtitle: 'Cashier codes and PINs for the POS, with targets and performance',
            breadcrumbs: [{ title: 'Cashiers', href: 'NULL' }],
        },
    });

    type Cashier = { id: number; name: string; code: string; daily_target: number | null; monthly_target: number | null; is_active: boolean; user_id: number | null };
    type Performance = { id: number; name: string; code: string; today: number; today_sales: number; daily_percent: number | null; month: number; monthly_percent: number | null };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const rows = ref<Cashier[]>([]);
    const performance = ref<Performance[]>([]);
    const editing = ref<number | null>(null);
    const blank = () => ({ name: '', code: '', pin: '', daily_target: '' as number | '', monthly_target: '' as number | '', is_active: true });
    const form = ref(blank());

    async function load() {
        rows.value = (await run(() => window.axios.get('/api/cashiers', { params: scope() })))?.data?.data ?? [];
        performance.value = (await run(() => window.axios.get('/api/cashiers/performance', { params: scope() })))?.data?.data ?? [];
    }

    function edit(row: Cashier) {
        editing.value = row.id;
        form.value = { name: row.name, code: row.code, pin: '', daily_target: row.daily_target ?? '', monthly_target: row.monthly_target ?? '', is_active: row.is_active };
    }

    async function save() {
        const body: Record<string, unknown> = { ...scope(), ...form.value };

        for (const key of ['pin', 'daily_target', 'monthly_target']) {
            if (body[key] === '') {
                delete body[key];
            }
        }

        const request = editing.value === null ? () => window.axios.post('/api/cashiers', body) : () => window.axios.put(`/api/cashiers/${editing.value}`, body);

        if (await run(request, 'Saved')) {
            editing.value = null;
            form.value = blank();
            await load();
        }
    }

    async function remove(row: Cashier) {
        if (window.confirm(`Delete cashier ${row.name}?`) && await run(() => window.axios.delete(`/api/cashiers/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Cashiers" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div v-if="can('/cashier/add') || (editing !== null && can('/cashier/:id/edit'))" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label small" for="cs-name">Name</label><input id="cs-name" v-model="form.name" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="cs-code">Code</label><input id="cs-code" v-model="form.code" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="cs-pin">PIN (4-8 digits)</label><input id="cs-pin" v-model="form.pin" type="password" autocomplete="new-password" class="form-control form-control-sm" :placeholder="editing === null ? '' : 'Keep current'" /></div>
                <div class="col-md-2"><label class="form-label small" for="cs-daily">Daily target</label><input id="cs-daily" v-model.number="form.daily_target" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="cs-monthly">Monthly target</label><input id="cs-monthly" v-model.number="form.monthly_target" type="number" min="0" step="any" class="form-control form-control-sm" /></div>
                <div class="col-md-1 d-flex align-items-end"><label class="form-check-label small"><input v-model="form.is_active" type="checkbox" class="form-check-input me-1" />Active</label></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3 me-2" @click="save">{{ editing === null ? 'Add cashier' : 'Save cashier' }}</button>
            <button v-if="editing !== null" type="button" class="btn btn-outline-dark btn-sm mt-3" @click="editing = null; form = blank()">Cancel</button>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <ClientTable empty-text="No cashiers."  :columns="[{ key: 'c0', label: 'Name' }, { key: 'c1', label: 'Code' }, { key: 'c2', label: 'Active' }, { key: 'c3', label: '' }]" :rows="(rows).map((row) => ({ __s: { row }, c0: (row.name), c1: (row.code), c2: (row.is_active ? 'Yes' : 'No'), c3: '' }))" export-name="name">
<template #cell-c3="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                                <button v-if="can('/cashier/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(row)">Edit</button>
                                <button v-if="can('/cashier/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                            </template></template>
</ClientTable>
            </div>
            <div class="col-lg-6">
                <ClientTable empty-text="No activity."  :columns="[{ key: 'c0', label: 'Cashier' }, { key: 'c1', label: 'Today' }, { key: 'c2', label: 'Day %' }, { key: 'c3', label: 'Month' }, { key: 'c4', label: 'Month %' }]" :rows="(performance).map((row) => ({ __s: { row }, c0: (row.name), c1: '', c2: (row.daily_percent ?? '-'), c3: (money(row.month)), c4: (row.monthly_percent ?? '-') }))" export-name="cashier">
<template #cell-c1="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">{{ money(row.today) }} <small class="text-muted">({{ row.today_sales }})</small></template></template>
</ClientTable>
            </div>
        </div>
    </div></div></div>
</template>
