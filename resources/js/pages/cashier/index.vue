<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
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

    <div class="container-fluid py-3">
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
                <table class="table table-sm align-middle">
                    <thead><tr><th>Name</th><th>Code</th><th>Active</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td>{{ row.name }}</td><td>{{ row.code }}</td><td>{{ row.is_active ? 'Yes' : 'No' }}</td>
                            <td class="text-end text-nowrap">
                                <button v-if="can('/cashier/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(row)">Edit</button>
                                <button v-if="can('/cashier/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                            </td>
                        </tr>
                        <tr v-if="! rows.length"><td colspan="4" class="text-center text-muted">No cashiers.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-lg-6">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Cashier</th><th class="text-end">Today</th><th class="text-end">Day %</th><th class="text-end">Month</th><th class="text-end">Month %</th></tr></thead>
                    <tbody>
                        <tr v-for="row in performance" :key="row.id">
                            <td>{{ row.name }}</td><td class="text-end">{{ money(row.today) }} <small class="text-muted">({{ row.today_sales }})</small></td>
                            <td class="text-end">{{ row.daily_percent ?? '-' }}</td><td class="text-end">{{ money(row.month) }}</td><td class="text-end">{{ row.monthly_percent ?? '-' }}</td>
                        </tr>
                        <tr v-if="! performance.length"><td colspan="5" class="text-center text-muted">No activity.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
