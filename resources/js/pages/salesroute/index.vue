<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Sales Routes',
            subtitle: 'Van-sales routes, their customer stops and the day\'s visit sheet',
            breadcrumbs: [{ title: 'Sales Routes', href: 'NULL' }],
        },
    });

    type Route = { id: number; name: string; weekday: number | null; rider_id: number | null; warehouse_id: number | null; is_active: boolean; stops: { id: number; contact_id: number }[] };
    type Sheet = { id: number; name: string; stops: { contact_id: number; customer: string | null; status: string; transaction_id: number | null }[] };

    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const { can, companyId, isSuperadmin, scope, run } = useCloseout();
    const routes = ref<Route[]>([]);
    const sheet = ref<Sheet[]>([]);
    const date = ref(new Date().toISOString().slice(0, 10));
    const editing = ref<number | null>(null);
    const blank = () => ({ name: '', weekday: '' as number | '', rider_id: '' as number | '', warehouse_id: '' as number | '', is_active: true, stops: '' });
    const form = ref(blank());

    async function load() {
        routes.value = (await run(() => window.axios.get('/api/sales-routes', { params: scope() })))?.data?.data ?? [];
        sheet.value = (await run(() => window.axios.get('/api/sales-routes/sheet', { params: { ...scope(), date: date.value } })))?.data?.data ?? [];
    }

    function edit(row: Route) {
        editing.value = row.id;
        form.value = { name: row.name, weekday: row.weekday ?? '', rider_id: row.rider_id ?? '', warehouse_id: row.warehouse_id ?? '', is_active: row.is_active, stops: row.stops.map((stop) => stop.contact_id).join(', ') };
    }

    async function save() {
        const body: Record<string, unknown> = {
            ...scope(), name: form.value.name, is_active: form.value.is_active,
            weekday: form.value.weekday === '' ? null : form.value.weekday, rider_id: form.value.rider_id === '' ? null : form.value.rider_id,
            warehouse_id: form.value.warehouse_id === '' ? null : form.value.warehouse_id,
            stops: form.value.stops.split(/[\s,]+/).filter(Boolean).map((id) => ({ contact_id: Number(id) })),
        };
        const request = editing.value === null ? () => window.axios.post('/api/sales-routes', body) : () => window.axios.put(`/api/sales-routes/${editing.value}`, body);

        if (await run(request, 'Route saved')) {
            editing.value = null;
            form.value = blank();
            await load();
        }
    }

    async function remove(row: Route) {
        if (window.confirm(`Delete route ${row.name}?`) && await run(() => window.axios.delete(`/api/sales-routes/${row.id}`), 'Deleted')) {
            await load();
        }
    }

    async function visit(route: Sheet, contactId: number, status: 'visited' | 'skipped' | 'sold') {
        const body: Record<string, unknown> = { contact_id: contactId, status, date: date.value };

        if (status === 'sold') {
            const sale = window.prompt('Invoice id of the sale made on this visit', '');

            if (! sale) {
                return;
            }

            body.transaction_id = Number(sale);
        }

        if (await run(() => window.axios.post(`/api/sales-routes/${route.id}/visit`, body), 'Visit recorded')) {
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Sales Routes" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
        </div>

        <div v-if="can('/salesroute/add') || (editing !== null && can('/salesroute/:id/edit'))" class="border rounded p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label small" for="rt-name">Name</label><input id="rt-name" v-model="form.name" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="rt-day">Weekday</label>
                    <select id="rt-day" v-model="form.weekday" class="form-select form-select-sm"><option value="">Any day</option><option v-for="(day, index) in days" :key="day" :value="index">{{ day }}</option></select>
                </div>
                <div class="col-md-2"><label class="form-label small" for="rt-rider">Rider id</label><input id="rt-rider" v-model.number="form.rider_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><label class="form-label small" for="rt-wh">Van warehouse id</label><input id="rt-wh" v-model.number="form.warehouse_id" type="number" class="form-control form-control-sm" /></div>
                <div class="col-md-3 d-flex align-items-end"><label class="form-check-label small"><input v-model="form.is_active" type="checkbox" class="form-check-input me-1" />Active</label></div>
                <div class="col-12"><label class="form-label small" for="rt-stops">Customer ids in visiting order (comma separated)</label><input id="rt-stops" v-model="form.stops" class="form-control form-control-sm" /></div>
            </div>
            <button type="button" class="btn btn-dark btn-sm mt-3 me-2" @click="save">{{ editing === null ? 'Add route' : 'Save route' }}</button>
            <button v-if="editing !== null" type="button" class="btn btn-outline-dark btn-sm mt-3" @click="editing = null; form = blank()">Cancel</button>
        </div>

        <div class="row g-3">
            <div class="col-lg-5">
                <h6>Routes</h6>
                <ClientTable empty-text="No routes."  :columns="[{ key: 'c0', label: 'Name' }, { key: 'c1', label: 'Day' }, { key: 'c2', label: 'Stops' }, { key: 'c3', label: '' }]" :rows="(routes).map((row) => ({ __s: { row }, c0: (row.name), c1: (row.weekday === null ? 'Any' : days[row.weekday]), c2: (row.stops.length), c3: '' }))" export-name="name">
<template #cell-c3="{ row: __m }"><template v-for="{ row } in [__m.__s]" :key="0">
                                <button v-if="can('/salesroute/:id/edit')" type="button" class="btn btn-outline-dark btn-sm me-1" @click="edit(row)">Edit</button>
                                <button v-if="can('/salesroute/delete')" type="button" class="btn btn-outline-danger btn-sm" @click="remove(row)">Delete</button>
                            </template></template>
</ClientTable>
            </div>
            <div class="col-lg-7">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h6 class="mb-0">Visit sheet</h6>
                    <input v-model="date" type="date" class="form-control form-control-sm" style="width: auto" aria-label="Sheet date" @change="load" />
                </div>
                <div v-for="route in sheet" :key="route.id" class="border rounded p-2 mb-2">
                    <strong>{{ route.name }}</strong>
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <tr v-for="stop in route.stops" :key="stop.contact_id">
                                <td>{{ stop.customer ?? '#' + stop.contact_id }}</td>
                                <td class="text-capitalize">{{ stop.status }}</td>
                                <td class="text-end text-nowrap">
                                    <template v-if="can('/salesroute/visit')">
                                        <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="visit(route, stop.contact_id, 'visited')">Visited</button>
                                        <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="visit(route, stop.contact_id, 'sold')">Sold</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="visit(route, stop.contact_id, 'skipped')">Skip</button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="! sheet.length" class="text-muted small">No route is planned for this day.</p>
            </div>
        </div>
    </div></div></div>
</template>
