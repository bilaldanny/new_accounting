<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, reactive, ref } from 'vue';
    import LookupSelect from '@/components/LookupSelect.vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Service and Field Jobs',
            subtitle: 'Repair job cards, field jobs and installations: assignment, schedule, parts, invoice and service report',
            breadcrumbs: [{ title: 'Service and Field Jobs', href: 'NULL' }],
        },
    });

    type Job = { id: number; job_no: string; type: string; title: string; status: string; priority: string; scheduled_at: string | null; technician_id: number | null; customer: string | null; technician_first: string | null; technician_last: string | null; is_warranty: boolean; sale_id: number | null };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const meta = ref<{ types: string[]; statuses: string[]; flow: Record<string, string[]>; technicians: { id: number; name: string }[]; products: { id: number; name: string }[] }>({ types: [], statuses: [], flow: {}, technicians: [], products: [] });
    const jobs = ref<Job[]>([]);
    const detail = ref<Record<string, any> | null>(null);
    const filter = reactive({ status: '', type: '', technician_id: '' as number | '', date: '' });
    const form = reactive({ type: 'repair', title: '', contact_id: '' as number | '', description: '', item_description: '', serial_no: '', site_address: '', service_charge: 0, service_product_id: '' as number | '', is_warranty: false, technician_id: '' as number | '', scheduled_at: '' });
    const assign = reactive({ technician_id: '' as number | '', scheduled_at: '' });
    const canManage = computed(() => can('/service/manage'));

    async function load() {
        meta.value = (await run(() => window.axios.get('/api/service/meta', { params: scope() })))?.data?.data ?? meta.value;
        const params = Object.fromEntries(Object.entries({ ...scope(), ...filter }).filter(([, v]) => v !== '' && v !== null));
        jobs.value = (await run(() => window.axios.get('/api/service/jobs', { params })))?.data?.data ?? [];
    }

    async function show(id: number) {
        detail.value = (await run(() => window.axios.get(`/api/service/jobs/${id}`, { params: scope() })))?.data?.data ?? null;
        assign.technician_id = detail.value?.technician_id ?? '';
        assign.scheduled_at = detail.value?.scheduled_at ? String(detail.value.scheduled_at).replace(' ', 'T').slice(0, 16) : '';
    }

    async function create() {
        const body = Object.fromEntries(Object.entries({ ...scope(), ...form }).filter(([, v]) => v !== ''));
        const result = await run(() => window.axios.post('/api/service/jobs', body), 'Job card made');

        if (result) {
            form.title = '';
            await load();
            await show(result.data.data.id);
        }
    }

    async function act(path: string, body: Record<string, unknown>, ok: string) {
        if (detail.value && (await run(() => window.axios.post(`/api/service/jobs/${detail.value!.id}${path}`, { ...scope(), ...body }), ok))) {
            await show(detail.value.id);
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Service and Field Jobs" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div v-if="canManage" class="border rounded p-3 mb-3">
            <h6>New job card</h6>
            <div class="row g-2">
                <div class="col-md-2"><select v-model="form.type" class="form-select form-select-sm" aria-label="Type"><option v-for="t in meta.types" :key="t" :value="t">{{ t }}</option></select></div>
                <div class="col-md-4"><input v-model="form.title" class="form-control form-control-sm" placeholder="What is the job about" aria-label="Job title" /></div>
                <div class="col-md-2"><LookupSelect v-model="form.contact_id" endpoint="/api/fetchcustomers" :params="scope()" placeholder="Customer" aria-label="Customer" /></div>
                <div class="col-md-2"><input v-model="form.item_description" class="form-control form-control-sm" placeholder="Item" aria-label="Item" /></div>
                <div class="col-md-2"><input v-model="form.serial_no" class="form-control form-control-sm" placeholder="Serial no" aria-label="Serial no" /></div>
                <div class="col-md-4"><input v-model="form.site_address" class="form-control form-control-sm" placeholder="Site address" aria-label="Site address" /></div>
                <div class="col-md-2"><input v-model.number="form.service_charge" type="number" class="form-control form-control-sm" placeholder="Service charge" aria-label="Service charge" /></div>
                <div class="col-md-3"><select v-model="form.service_product_id" class="form-select form-select-sm" aria-label="Service item"><option value="">Service item for the charge</option><option v-for="p in meta.products" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                <div class="col-md-3"><select v-model="form.technician_id" class="form-select form-select-sm" aria-label="Technician"><option value="">Technician</option><option v-for="t in meta.technicians" :key="t.id" :value="t.id">{{ t.name }}</option></select></div>
                <div class="col-md-3"><input v-model="form.scheduled_at" type="datetime-local" class="form-control form-control-sm" aria-label="Scheduled at" /></div>
                <div class="col-md-3 form-check ms-2"><input id="svc-warranty" v-model="form.is_warranty" type="checkbox" class="form-check-input" /><label class="form-check-label small" for="svc-warranty">Warranty job (invoiced at zero)</label></div>
                <div class="col-md-2"><button type="button" class="btn btn-dark btn-sm" :disabled="! form.title" @click="create">Make job card</button></div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-2">
            <select v-model="filter.status" class="form-select form-select-sm" style="width: 11rem" aria-label="Status filter" @change="load"><option value="">All statuses</option><option v-for="s in meta.statuses" :key="s" :value="s">{{ s.replace('_', ' ') }}</option></select>
            <select v-model="filter.type" class="form-select form-select-sm" style="width: 10rem" aria-label="Type filter" @change="load"><option value="">All types</option><option v-for="t in meta.types" :key="t" :value="t">{{ t }}</option></select>
            <select v-model="filter.technician_id" class="form-select form-select-sm" style="width: 12rem" aria-label="Technician filter" @change="load"><option value="">All technicians</option><option v-for="t in meta.technicians" :key="t.id" :value="t.id">{{ t.name }}</option></select>
            <input v-model="filter.date" type="date" class="form-control form-control-sm" style="width: 10rem" aria-label="Day filter" @change="load" />
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <ClientTable empty-text="No jobs."  :columns="[{ key: 'c0', label: 'Job' }, { key: 'c1', label: 'Type' }, { key: 'c2', label: 'Title' }, { key: 'c3', label: 'Customer' }, { key: 'c4', label: 'Technician' }, { key: 'c5', label: 'When' }, { key: 'c6', label: 'Status' }]" :rows="(jobs).map((j) => ({ __s: { j }, c0: (j.job_no), c1: (j.type), c2: (j.title), c3: (j.customer ?? '-'), c4: (j.technician_first ? j.technician_first + ' ' + (j.technician_last ?? '') : '-'), c5: (j.scheduled_at ?? '-'), c6: (j.status.replace('_', ' ')) }))" export-name="job">
</ClientTable>
            </div>

            <div v-if="detail" class="col-lg-5 border rounded p-3">
                <h6>{{ detail.job_no }} <span class="badge text-bg-secondary text-capitalize">{{ detail.status.replace('_', ' ') }}</span></h6>
                <p class="mb-1">{{ detail.title }}</p>
                <p class="small text-muted">{{ detail.description }}</p>
                <div v-if="canManage" class="d-flex flex-wrap gap-2 mb-2">
                    <select v-model="assign.technician_id" class="form-select form-select-sm" style="width: 11rem" aria-label="Assign technician"><option value="">Technician</option><option v-for="t in meta.technicians" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                    <input v-model="assign.scheduled_at" type="datetime-local" class="form-control form-control-sm" style="width: 12rem" aria-label="Assign time" />
                    <button type="button" class="btn btn-outline-dark btn-sm" @click="act('/assign', { technician_id: assign.technician_id || null, scheduled_at: assign.scheduled_at || null }, 'Job assigned')">Assign</button>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button v-for="next in meta.flow[detail.status] ?? []" :key="next" type="button" class="btn btn-sm text-capitalize" :class="next === 'cancelled' ? 'btn-outline-danger' : 'btn-dark'" @click="act('/status', { status: next }, 'Job updated')">{{ next.replace('_', ' ') }}</button>
                    <button v-if="canManage && detail.status === 'completed'" type="button" class="btn btn-success btn-sm" @click="act('/invoice', {}, 'Draft invoice made')">Make draft invoice</button>
                </div>
                <div v-if="detail.invoice" class="small mb-2">Invoice {{ detail.invoice.invoice_no }} ({{ detail.invoice.status }}): {{ money(detail.invoice.final_amount) }}, paid {{ money(detail.invoice.paid) }}. Payments are taken on the sale.</div>
                <div v-if="detail.warranty_claim" class="small mb-2">Warranty claim {{ detail.warranty_claim.claim_no }} ({{ detail.warranty_claim.status }})</div>
                <h6 class="small mt-3">Parts</h6>
                <ul class="small"><li v-for="p in detail.parts" :key="p.id">{{ p.name }} x {{ p.quantity }}<span v-if="p.unit_price !== null"> at {{ money(p.unit_price) }}</span></li><li v-if="! detail.parts.length" class="text-muted">None</li></ul>
                <h6 class="small">History</h6>
                <ul class="small mb-2"><li v-for="e in detail.events" :key="e.id">{{ e.created_at }}: {{ e.event }} <span v-if="e.to_status">to {{ e.to_status.replace('_', ' ') }}</span> <span v-if="e.note" class="text-muted">{{ e.note }}</span></li></ul>
                <a class="btn btn-outline-dark btn-sm" :href="`/api/service/jobs/${detail.id}/report`" target="_blank" rel="noopener">Service report</a>
            </div>
        </div>
    </div></div></div>
</template>
