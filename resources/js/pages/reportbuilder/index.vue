<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, reactive, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Report Builder',
            subtitle: 'Your own reports from the available datasets, and scheduled emailed reports',
            breadcrumbs: [{ title: 'Reports', href: 'NULL' }, { title: 'Report Builder', href: 'NULL' }],
        },
    });

    type Column = { key: string; label: string; type: string; filter: boolean; group: boolean };
    type Dataset = { key: string; label: string; has_date: boolean; columns: Column[] };
    type Filter = { column: string; operator: string; value: string };
    type Saved = { id: number; name: string; dataset: string; definition: Record<string, unknown>; is_shared: boolean; is_mine: boolean };
    type Schedule = { id: number; name: string; report_type: string; report_key: string | null; custom_report_id: number | null; frequency: string; weekday: number | null; day_of_month: number | null; recipients: string[]; is_active: boolean; last_run_at: string | null; last_status: string | null };

    const { companyId, isSuperadmin, scope, can, money, run } = useCloseout();
    const canManage = computed(() => can('/reportbuilder/manage'));

    const datasets = ref<Dataset[]>([]);
    const operators = ref<string[]>([]);
    const saved = ref<Saved[]>([]);
    const schedules = ref<Schedule[]>([]);
    const standardReports = ref<{ key: string; title: string }[]>([]);
    const tab = ref<'builder' | 'schedules'>('builder');

    const form = reactive({
        id: 0,
        name: '',
        dataset: '',
        columns: [] as string[],
        filters: [] as Filter[],
        group_by: '',
        sort_by: '',
        sort_dir: 'asc',
        from: '',
        to: '',
        is_shared: false,
    });

    const result = ref<{ columns: { key: string; label: string; type: string }[]; data: Record<string, unknown>[]; truncated: boolean } | null>(null);

    const dataset = computed(() => datasets.value.find((item) => item.key === form.dataset) ?? null);

    function definition() {
        return { columns: form.columns, filters: form.filters, group_by: form.group_by || null, sort_by: form.sort_by || null, sort_dir: form.sort_dir, from: form.from || null, to: form.to || null };
    }

    function reset() {
        Object.assign(form, { id: 0, name: '', columns: [], filters: [], group_by: '', sort_by: '', sort_dir: 'asc', from: '', to: '', is_shared: false });
        result.value = null;
    }

    function chooseDataset() {
        form.columns = [];
        form.filters = [];
        form.group_by = '';
        form.sort_by = '';
        result.value = null;
    }

    async function loadAll() {
        const meta = await run(() => window.axios.get('/api/report-builder/meta'));
        datasets.value = meta?.data?.data?.datasets ?? [];
        operators.value = meta?.data?.data?.operators ?? [];

        if (!form.dataset && datasets.value.length) {
            form.dataset = datasets.value[0].key;
        }

        const list = await run(() => window.axios.get('/api/report-builder', { params: scope() }));
        saved.value = list?.data?.data ?? [];

        const plan = await run(() => window.axios.get('/api/report-schedules', { params: scope() }));
        schedules.value = plan?.data?.data ?? [];
        standardReports.value = plan?.data?.reports ?? [];
    }

    async function preview() {
        const response = await run(() => window.axios.post('/api/report-builder/preview', { ...scope(), dataset: form.dataset, definition: definition() }));

        if (response) {
            result.value = { columns: response.data.columns, data: response.data.data, truncated: response.data.truncated };
        }
    }

    async function save() {
        const body = { ...scope(), name: form.name, dataset: form.dataset, definition: definition(), is_shared: form.is_shared };
        const response = form.id
            ? await run(() => window.axios.put(`/api/report-builder/${form.id}`, body), 'Report saved')
            : await run(() => window.axios.post('/api/report-builder', body), 'Report saved');

        if (response) {
            reset();
            await loadAll();
        }
    }

    function edit(item: Saved) {
        const def = item.definition as { columns?: string[]; filters?: Filter[]; group_by?: string | null; sort_by?: string | null; sort_dir?: string; from?: string | null; to?: string | null };

        Object.assign(form, {
            id: item.id, name: item.name, dataset: item.dataset, columns: def.columns ?? [], filters: (def.filters ?? []).map((filter) => ({ ...filter, value: String(filter.value ?? '') })),
            group_by: def.group_by ?? '', sort_by: def.sort_by ?? '', sort_dir: def.sort_dir ?? 'asc', from: def.from ?? '', to: def.to ?? '', is_shared: item.is_shared,
        });
    }

    async function open(item: Saved) {
        const response = await run(() => window.axios.get(`/api/report-builder/${item.id}/run`, { params: scope() }));

        if (response) {
            result.value = { columns: response.data.columns, data: response.data.data, truncated: response.data.truncated };
        }
    }

    function download(item: Saved) {
        const params = new URLSearchParams({ format: 'csv', ...(isSuperadmin.value && companyId.value !== '' ? { company_id: String(companyId.value) } : {}) });
        window.location.href = `/api/report-builder/${item.id}/run?${params.toString()}`;
    }

    async function remove(item: Saved) {
        if (await run(() => window.axios.delete(`/api/report-builder/${item.id}`, { params: scope() }), 'Report deleted')) {
            await loadAll();
        }
    }

    function addFilter() {
        const first = dataset.value?.columns.find((column) => column.filter);
        form.filters.push({ column: first?.key ?? '', operator: '=', value: '' });
    }

    function cell(value: unknown, type: string): string {
        if (value === null || value === undefined) {
            return '';
        }

        return type === 'number' ? money(value as number) : String(value);
    }

    const schedule = reactive({ name: '', report_type: 'standard', report_key: '', custom_report_id: 0, frequency: 'daily', weekday: 1, day_of_month: 1, recipients: '' });

    async function addSchedule() {
        const body = {
            ...scope(), name: schedule.name, report_type: schedule.report_type, frequency: schedule.frequency,
            report_key: schedule.report_type === 'standard' ? schedule.report_key : null,
            custom_report_id: schedule.report_type === 'custom' ? schedule.custom_report_id : null,
            weekday: schedule.frequency === 'weekly' ? schedule.weekday : null,
            day_of_month: schedule.frequency === 'monthly' ? schedule.day_of_month : null,
            recipients: schedule.recipients.split(/[,\s;]+/).filter(Boolean),
        };

        if (await run(() => window.axios.post('/api/report-schedules', body), 'Schedule saved')) {
            Object.assign(schedule, { name: '', recipients: '' });
            await loadAll();
        }
    }

    async function toggleSchedule(item: Schedule) {
        await run(() => window.axios.put(`/api/report-schedules/${item.id}`, { ...scope(), is_active: !item.is_active }));
        await loadAll();
    }

    async function runSchedule(item: Schedule) {
        const response = await run(() => window.axios.post(`/api/report-schedules/${item.id}/run`, { ...scope() }));

        if (response) {
            await loadAll();
        }
    }

    async function removeSchedule(item: Schedule) {
        await run(() => window.axios.delete(`/api/report-schedules/${item.id}`, { params: scope() }), 'Schedule deleted');
        await loadAll();
    }

    onMounted(loadAll);
</script>

<template>
    <Head title="Report Builder" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="loadAll" />

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'builder' }" @click="tab = 'builder'">Builder</button></li>
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'schedules' }" @click="tab = 'schedules'">Scheduled reports</button></li>
        </ul>

        <div v-if="tab === 'builder'" class="row g-3">
            <div class="col-lg-5">
                <div class="border rounded p-3">
                    <h6>{{ form.id ? 'Edit report' : 'New report' }}</h6>
                    <label class="form-label small" for="rb-name">Name</label>
                    <input id="rb-name" v-model="form.name" type="text" class="form-control form-control-sm mb-2" maxlength="120" />

                    <label class="form-label small" for="rb-dataset">Dataset</label>
                    <select id="rb-dataset" v-model="form.dataset" class="form-select form-select-sm mb-2" :disabled="form.id > 0" @change="chooseDataset">
                        <option v-for="item in datasets" :key="item.key" :value="item.key">{{ item.label }}</option>
                    </select>

                    <div v-if="dataset" class="mb-2">
                        <div class="form-label small">Columns</div>
                        <div v-for="column in dataset.columns" :key="column.key" class="form-check form-check-inline">
                            <input :id="`rb-col-${column.key}`" v-model="form.columns" type="checkbox" class="form-check-input" :value="column.key" />
                            <label class="form-check-label small" :for="`rb-col-${column.key}`">{{ column.label }}</label>
                        </div>
                    </div>

                    <div v-if="dataset" class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small" for="rb-group">Group by</label>
                            <select id="rb-group" v-model="form.group_by" class="form-select form-select-sm">
                                <option value="">No grouping</option>
                                <option v-for="column in dataset.columns.filter((c) => c.group)" :key="column.key" :value="column.key">{{ column.label }}</option>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label small" for="rb-sort">Sort by</label>
                            <select id="rb-sort" v-model="form.sort_by" class="form-select form-select-sm">
                                <option value="">Default</option>
                                <option v-for="column in dataset.columns" :key="column.key" :value="column.key">{{ column.label }}</option>
                            </select>
                        </div>
                        <div class="col-2">
                            <label class="form-label small" for="rb-dir">Order</label>
                            <select id="rb-dir" v-model="form.sort_dir" class="form-select form-select-sm"><option value="asc">Asc</option><option value="desc">Desc</option></select>
                        </div>
                    </div>

                    <div v-if="dataset?.has_date" class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small" for="rb-from">From</label><input id="rb-from" v-model="form.from" type="date" class="form-control form-control-sm" /></div>
                        <div class="col-6"><label class="form-label small" for="rb-to">To</label><input id="rb-to" v-model="form.to" type="date" class="form-control form-control-sm" /></div>
                    </div>

                    <div v-if="dataset" class="mb-2">
                        <div class="d-flex justify-content-between"><span class="form-label small">Filters</span><button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="addFilter">Add</button></div>
                        <div v-for="(filter, index) in form.filters" :key="index" class="d-flex gap-1 mt-1">
                            <select v-model="filter.column" class="form-select form-select-sm" :aria-label="`Filter ${index + 1} column`"><option v-for="column in dataset.columns.filter((c) => c.filter)" :key="column.key" :value="column.key">{{ column.label }}</option></select>
                            <select v-model="filter.operator" class="form-select form-select-sm" style="width: 6rem" :aria-label="`Filter ${index + 1} operator`"><option v-for="operator in operators.filter((o) => o !== 'between')" :key="operator" :value="operator">{{ operator }}</option></select>
                            <input v-model="filter.value" type="text" class="form-control form-control-sm" :aria-label="`Filter ${index + 1} value`" />
                            <button type="button" class="btn btn-outline-danger btn-sm py-0" @click="form.filters.splice(index, 1)">x</button>
                        </div>
                    </div>

                    <div class="form-check mb-2"><input id="rb-shared" v-model="form.is_shared" type="checkbox" class="form-check-input" /><label class="form-check-label small" for="rb-shared">Share with the company</label></div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-dark btn-sm" @click="preview">Preview</button>
                        <button v-if="canManage" type="button" class="btn btn-dark btn-sm" :disabled="form.name.trim().length < 2" @click="save">Save</button>
                        <button v-if="form.id" type="button" class="btn btn-link btn-sm" @click="reset">Cancel edit</button>
                    </div>
                </div>

                <div class="border rounded p-3 mt-3">
                    <h6>Saved reports</h6>
                    <div v-for="item in saved" :key="item.id" class="d-flex align-items-center gap-2 mb-1">
                        <span class="flex-grow-1">{{ item.name }} <span class="text-muted small">{{ item.is_shared ? 'shared' : 'private' }}</span></span>
                        <button type="button" class="btn btn-outline-dark btn-sm py-0" @click="open(item)">Run</button>
                        <button type="button" class="btn btn-outline-dark btn-sm py-0" @click="download(item)">CSV</button>
                        <button v-if="canManage" type="button" class="btn btn-outline-secondary btn-sm py-0" @click="edit(item)">Edit</button>
                        <button v-if="canManage" type="button" class="btn btn-outline-danger btn-sm py-0" @click="remove(item)">Delete</button>
                    </div>
                    <div v-if="! saved.length" class="text-muted small">No saved reports yet.</div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="border rounded p-3">
                    <h6>Result</h6>
                    <div v-if="! result" class="text-muted small">Preview or run a report to see its rows.</div>
                    <div v-else class="table-responsive">
                        <ClientTable empty-text="No rows."  :columns="[{ key: 'c0', label: '{{ column.label }}' }]" :rows="(result.data).map((row) => ({ __s: { row }, c0: (cell(row[column.key], column.type)) }))" export-name="column-label">
</ClientTable>
                        <div v-if="result.truncated" class="small text-warning">Only the first rows are shown; narrow the filters to see the rest.</div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="row g-3">
            <div class="col-lg-5">
                <div v-if="canManage" class="border rounded p-3">
                    <h6>New schedule</h6>
                    <label class="form-label small" for="rs-name">Name</label>
                    <input id="rs-name" v-model="schedule.name" type="text" class="form-control form-control-sm mb-2" maxlength="120" />
                    <label class="form-label small" for="rs-type">Report</label>
                    <select id="rs-type" v-model="schedule.report_type" class="form-select form-select-sm mb-2"><option value="standard">A standard report</option><option value="custom">A custom report</option></select>
                    <select v-if="schedule.report_type === 'standard'" v-model="schedule.report_key" class="form-select form-select-sm mb-2" aria-label="Standard report">
                        <option value="">Choose a report</option>
                        <option v-for="report in standardReports" :key="report.key" :value="report.key">{{ report.title }}</option>
                    </select>
                    <select v-else v-model.number="schedule.custom_report_id" class="form-select form-select-sm mb-2" aria-label="Custom report">
                        <option :value="0">Choose a custom report</option>
                        <option v-for="item in saved" :key="item.id" :value="item.id">{{ item.name }}</option>
                    </select>
                    <div class="row g-2 mb-2">
                        <div class="col-4"><label class="form-label small" for="rs-freq">How often</label><select id="rs-freq" v-model="schedule.frequency" class="form-select form-select-sm"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></div>
                        <div v-if="schedule.frequency === 'weekly'" class="col-4"><label class="form-label small" for="rs-wd">Weekday</label><select id="rs-wd" v-model.number="schedule.weekday" class="form-select form-select-sm"><option v-for="(day, index) in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']" :key="day" :value="index">{{ day }}</option></select></div>
                        <div v-if="schedule.frequency === 'monthly'" class="col-4"><label class="form-label small" for="rs-dom">Day of month</label><input id="rs-dom" v-model.number="schedule.day_of_month" type="number" min="1" max="31" class="form-control form-control-sm" /></div>
                    </div>
                    <label class="form-label small" for="rs-to">Send to (emails, comma separated)</label>
                    <input id="rs-to" v-model="schedule.recipients" type="text" class="form-control form-control-sm mb-2" />
                    <button type="button" class="btn btn-dark btn-sm" :disabled="schedule.name.trim().length < 2 || ! schedule.recipients.trim()" @click="addSchedule">Save schedule</button>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="border rounded p-3">
                    <h6>Scheduled reports</h6>
                    <div v-for="item in schedules" :key="item.id" class="border-bottom py-2">
                        <div class="d-flex align-items-center gap-2">
                            <strong class="flex-grow-1">{{ item.name }}</strong>
                            <span class="badge" :class="item.is_active ? 'text-bg-success' : 'text-bg-secondary'">{{ item.is_active ? 'active' : 'paused' }}</span>
                            <button v-if="canManage" type="button" class="btn btn-outline-dark btn-sm py-0" @click="runSchedule(item)">Send now</button>
                            <button v-if="canManage" type="button" class="btn btn-outline-secondary btn-sm py-0" @click="toggleSchedule(item)">{{ item.is_active ? 'Pause' : 'Resume' }}</button>
                            <button v-if="canManage" type="button" class="btn btn-outline-danger btn-sm py-0" @click="removeSchedule(item)">Delete</button>
                        </div>
                        <div class="small text-muted">{{ item.report_type === 'custom' ? 'Custom report' : item.report_key }} - {{ item.frequency }} - to {{ item.recipients.join(', ') }}</div>
                        <div v-if="item.last_status" class="small">Last: {{ item.last_run_at }} - {{ item.last_status }}</div>
                    </div>
                    <div v-if="! schedules.length" class="text-muted small">No scheduled reports.</div>
                </div>
            </div>
        </div>
    </div></div></div>
</template>
