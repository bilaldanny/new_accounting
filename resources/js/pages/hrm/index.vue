<script setup lang="ts">
    import { Head, router, usePage } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'HR and Payroll',
            subtitle: 'Employees, attendance, leave, salary structures, payroll, payslips and bank salary files',
            breadcrumbs: [{ title: 'HR and Payroll', href: 'NULL' }],
        },
    });

    type Employee = { id: number; employee_no: string; first_name: string; last_name: string | null; designation: string | null; status: string; department: string | null; shift: string | null; join_date: string | null };
    type Run = { id: number; period: string; status: string; totals: Record<string, number>; voucher_id: number | null };
    type Line = { id: number; employee_no: string; name: string; gross: number; attendance_deduction: number; statutory_employee: number; tax: number; loan_deduction: number; net: number };

    const { can, companyId, isSuperadmin, scope, money, run } = useCloseout();
    type Tab = 'employees' | 'attendance' | 'payroll' | 'setup';
    const TAB_PATHS: Record<Tab, string> = { employees: '/hrm', attendance: '/hrm/attendance', payroll: '/hrm/payroll', setup: '/hrm/manage' };
    const page = usePage();

    /** The tab is part of the address (the sidebar links to each), so a tab can be bookmarked and the sidebar highlights it. */
    const tabFromUrl = (url: string): Tab => {
        const path = url.split('?')[0].replace(/\/+$/, '');

        return ANCHORS[path]?.[0] ?? (Object.keys(TAB_PATHS) as Tab[]).find((key) => TAB_PATHS[key] === path) ?? 'employees';
    };

    /** Sidebar entries that open one section of a tab: the tab and the id of the section scrolled into view. */
    const ANCHORS: Record<string, [Tab, string]> = {
        '/hrm/leave': ['attendance', 'hrm-leave'],
        '/hrm/loans': ['payroll', 'hrm-loans'],
        '/hrm/shifts': ['setup', 'hrm-shifts'],
        '/hrm/holidays': ['setup', 'hrm-holidays'],
        '/hrm/documents': ['employees', 'hrm-documents'],
    };

    function scrollToAnchor(url: string) {
        const id = ANCHORS[url.split('?')[0].replace(/\/+$/, '')]?.[1];

        if (id) {
            window.setTimeout(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 350);
        }
    }
    const tab = ref<Tab>(tabFromUrl(page.url));

    watch(() => page.url, (url) => {
        tab.value = tabFromUrl(url);
        scrollToAnchor(url);
    });

    function openTab(next: Tab) {
        tab.value = next;
        router.visit(TAB_PATHS[next], { preserveState: true, preserveScroll: true, only: [] });
    }
    const meta = ref<Record<string, any> | null>(null);
    const employees = ref<Employee[]>([]);
    const tree = ref<any[]>([]);
    const expiring = ref<any[]>([]);
    const attendance = ref<any[]>([]);
    const leaves = ref<any[]>([]);
    const devices = ref<any[]>([]);
    const runs = ref<Run[]>([]);
    const current = ref<(Run & { lines: Line[] }) | null>(null);
    const bonuses = ref<any[]>([]);
    const loans = ref<any[]>([]);
    const rates = ref<any[]>([]);
    const slabs = ref<any[]>([]);
    const components = ref<any[]>([]);
    const shifts = ref<any[]>([]);
    const newToken = ref('');
    const csv = ref('');
    const importResult = ref('');

    const employee = reactive<Record<string, any>>({ id: 0, employee_no: '', first_name: '', last_name: '', designation: '', join_date: '', status: 'active', employment_type: 'permanent', bank_name: '', bank_account: '', email: '', cnic: '' });
    const salary = reactive({ employee_id: '' as number | '', effective_from: '', basic: 0 });
    const punch = reactive({ employee_id: '' as number | '', work_date: '', check_in: '', check_out: '', status: 'present' });
    const leave = reactive({ employee_id: '' as number | '', leave_type_id: '' as number | '', from_date: '', to_date: '', reason: '' });
    const period = ref(new Date().toISOString().slice(0, 7));
    const bonus = reactive({ employee_id: '' as number | '', period: new Date().toISOString().slice(0, 7), title: '', amount: 0 });
    const loan = reactive({ employee_id: '' as number | '', kind: 'advance', amount: 0, installment: 0, start_period: new Date().toISOString().slice(0, 7) });
    const rate = reactive({ code: '', name: '', side: 'employee', basis: 'basic', rate_percent: 0, fixed_amount: 0, base_cap: '' as number | '', effective_from: '' });
    const slab = reactive({ effective_from: '', annual_from: 0, annual_to: '' as number | '', fixed_amount: 0, rate_percent: 0 });
    const component = reactive({ name: '', type: 'earning', calc: 'fixed', is_taxable: true });
    const shift = reactive({ name: '', start_time: '09:00', end_time: '18:00', grace_minutes: 10, weekly_off: '0' });
    const deviceName = ref('');
    const settings = reactive<Record<string, any>>({});

    const canManage = computed(() => can('/hrm/manage'));
    const canAttend = computed(() => can('/hrm/attendance'));
    const canPayroll = computed(() => can('/hrm/payroll'));
    const canApprove = computed(() => can('/hrm/payroll/approve'));
    const canLeaveApprove = computed(() => can('/hrm/leave/approve'));

    const get = async (url: string, params: Record<string, unknown> = {}) => (await run(() => window.axios.get(url, { params: { ...scope(), ...params } })))?.data;

    /** Holidays: the company's list, one at a time or the public holidays of Pakistan for a year in one click. */
    const holidays = ref<{ id: number; holiday_date: string; name: string; kind: string }[]>([]);
    const holiday = reactive({ id: 0, holiday_date: '', name: '' });
    const holidayYear = ref(new Date().getFullYear());
    const canHolidays = computed(() => can('/hrm/holidays'));

    async function loadHolidays() {
        if (canHolidays.value) {
            holidays.value = (await get('/api/hrm/holidays'))?.data ?? [];
        }
    }

    async function saveHoliday() {
        const body = { ...scope(), holiday_date: holiday.holiday_date, name: holiday.name };
        const done = holiday.id
            ? await run(() => window.axios.put(`/api/hrm/holidays/${holiday.id}`, body), 'Holiday saved')
            : await run(() => window.axios.post('/api/hrm/holidays', body), 'Holiday saved');

        if (done) {
            Object.assign(holiday, { id: 0, holiday_date: '', name: '' });
            await loadHolidays();
        }
    }

    async function removeHoliday(id: number) {
        if (await run(() => window.axios.delete(`/api/hrm/holidays/${id}`, { params: scope() }), 'Holiday deleted')) {
            await loadHolidays();
        }
    }

    async function importHolidays() {
        const done = await run(() => window.axios.post('/api/hrm/holidays/import-pakistan', { ...scope(), year: holidayYear.value }));

        if (done) {
            await loadHolidays();
        }
    }

    async function load() {
        void loadHolidays();
        meta.value = (await get('/api/hrm/meta'))?.data ?? null;
        Object.assign(settings, meta.value?.settings ?? {});
        employees.value = (await get('/api/hrm/employees'))?.data ?? [];
        tree.value = (await get('/api/hrm/org-tree'))?.data ?? [];
        expiring.value = (await get('/api/hrm/documents/expiring'))?.data ?? [];

        if (canAttend.value) {
            attendance.value = (await get('/api/hrm/attendance'))?.data ?? [];
            leaves.value = (await get('/api/hrm/leave-requests'))?.data ?? [];
        }

        if (canManage.value) {
            devices.value = (await get('/api/hrm/devices'))?.data ?? [];
        }

        if (canPayroll.value) {
            runs.value = (await get('/api/hrm/payroll'))?.data ?? [];
            bonuses.value = (await get('/api/hrm/bonuses'))?.data ?? [];
            loans.value = (await get('/api/hrm/loans'))?.data ?? [];
        }

        const r = await get('/api/hrm/rates');
        rates.value = r?.data ?? [];
        slabs.value = r?.slabs ?? [];
        components.value = (await get('/api/hrm/components'))?.data ?? [];
        shifts.value = (await get('/api/hrm/shifts'))?.data ?? [];
    }

    async function post(url: string, body: Record<string, unknown>, ok: string, reload = true) {
        const result = await run(() => window.axios.post(url, { ...scope(), ...body }), ok);

        if (result && reload) {
            await load();
        }

        return result;
    }

    async function saveEmployee() {
        const body = { ...employee };
        delete body.id;
        await run(() => (employee.id ? window.axios.put(`/api/hrm/employees/${employee.id}`, { ...scope(), ...body }) : window.axios.post('/api/hrm/employees', { ...scope(), ...body })), 'Employee saved');
        Object.assign(employee, { id: 0, employee_no: '', first_name: '', last_name: '', designation: '', join_date: '' });
        await load();
    }

    const edit = (row: Employee) => Object.assign(employee, row, { id: row.id });

    async function saveSalary() {
        await post(`/api/hrm/employees/${salary.employee_id}/salary`, { effective_from: salary.effective_from, basic: salary.basic, components: [] }, 'Salary structure saved');
    }

    async function openRun(id: number) {
        current.value = (await get(`/api/hrm/payroll/${id}`))?.data ?? null;
    }

    async function createRun() {
        const result = await post('/api/hrm/payroll', { period: period.value }, 'Payroll prepared');

        if (result?.data?.data?.id) {
            await openRun(result.data.data.id);
        }
    }

    async function runAction(path: string, ok: string) {
        if (current.value && (await post(`${path.replace(':id', String(current.value.id))}`, {}, ok))) {
            await openRun(current.value.id);
        }
    }

    async function createDevice() {
        const result = await post('/api/hrm/devices', { name: deviceName.value }, 'Device added');
        newToken.value = result?.data?.data?.token ?? '';
        deviceName.value = '';
    }

    async function importCsv() {
        const result = await run(() => window.axios.post('/api/hrm/attendance/import', { ...scope(), csv: csv.value }));
        const data = result?.data?.data;
        importResult.value = data ? `${data.saved} row(s) saved${data.errors.length ? '; ' + data.errors.join(' ') : ''}` : '';
        await load();
    }

    async function saveSettings() {
        await run(() => window.axios.put('/api/hrm/settings', { ...scope(), ...settings }), 'Settings saved');
    }

    async function decideLeave(id: number, verb: 'approve' | 'reject') {
        await post(`/api/leave-requests/${id}/${verb}`, {}, verb === 'approve' ? 'Leave approved' : 'Leave rejected');
    }

    const payslip = (lineId: number) => window.open(`/api/hrm/payslips/${lineId}`, '_blank', 'noopener');
    const bankFile = (format: string) => current.value && window.open(`/api/hrm/payroll/${current.value.id}/bank-file?format=${format}`, '_blank', 'noopener');

    onMounted(async () => {
        await load();
        scrollToAnchor(page.url);
    });
</script>

<template>
    <Head title="HR and Payroll" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <div v-if="expiring.length" class="alert alert-warning py-2 small">
            {{ expiring.length }} employee document(s) expired or expiring soon:
            <span v-for="doc in expiring.slice(0, 5)" :key="doc.id" class="me-2">{{ doc.employee }} - {{ doc.title }} ({{ doc.expires_on }})</span>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li v-for="item in [['employees', 'Employees'], ['attendance', 'Attendance and leave'], ['payroll', 'Payroll'], ['setup', 'Rates and setup']]" :key="item[0]" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: tab === item[0] }" @click="openTab(item[0] as Tab)">{{ item[1] }}</button>
            </li>
        </ul>

        <section v-if="tab === 'employees'">
            <div v-if="canManage" class="border rounded p-3 mb-3">
                <h6>{{ employee.id ? 'Edit employee' : 'New employee' }}</h6>
                <div class="row g-2">
                    <div class="col-md-2"><input v-model="employee.employee_no" class="form-control form-control-sm" placeholder="Employee no" aria-label="Employee no" /></div>
                    <div class="col-md-2"><input v-model="employee.first_name" class="form-control form-control-sm" placeholder="First name" aria-label="First name" /></div>
                    <div class="col-md-2"><input v-model="employee.last_name" class="form-control form-control-sm" placeholder="Last name" aria-label="Last name" /></div>
                    <div class="col-md-2"><input v-model="employee.designation" class="form-control form-control-sm" placeholder="Designation" aria-label="Designation" /></div>
                    <div class="col-md-2"><input v-model="employee.join_date" type="date" class="form-control form-control-sm" aria-label="Join date" /></div>
                    <div class="col-md-2"><select v-model="employee.status" class="form-select form-select-sm" aria-label="Status"><option v-for="s in meta?.statuses ?? []" :key="s" :value="s">{{ s }}</option></select></div>
                    <div class="col-md-2"><input v-model="employee.cnic" class="form-control form-control-sm" placeholder="CNIC" aria-label="CNIC" /></div>
                    <div class="col-md-2"><input v-model="employee.email" class="form-control form-control-sm" placeholder="Email" aria-label="Email" /></div>
                    <div class="col-md-2"><input v-model="employee.bank_name" class="form-control form-control-sm" placeholder="Bank" aria-label="Bank" /></div>
                    <div class="col-md-2"><input v-model="employee.bank_account" class="form-control form-control-sm" placeholder="Account no" aria-label="Account no" /></div>
                    <div class="col-md-2"><button type="button" class="btn btn-dark btn-sm" @click="saveEmployee">Save employee</button></div>
                </div>
                <hr />
                <div class="row g-2 align-items-end">
                    <div class="col-md-3"><select v-model="salary.employee_id" class="form-select form-select-sm" aria-label="Employee for salary"><option value="">Salary of ...</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.employee_no }} {{ e.first_name }}</option></select></div>
                    <div class="col-md-2"><input v-model="salary.effective_from" type="date" class="form-control form-control-sm" aria-label="Effective from" /></div>
                    <div class="col-md-2"><input v-model.number="salary.basic" type="number" class="form-control form-control-sm" placeholder="Basic" aria-label="Basic salary" /></div>
                    <div class="col-md-2"><button type="button" class="btn btn-outline-dark btn-sm" :disabled="! salary.employee_id" @click="saveSalary">Save salary structure</button></div>
                </div>
            </div>

            <ClientTable empty-text="No employees yet."  :columns="[{ key: 'c0', label: 'No' }, { key: 'c1', label: 'Name' }, { key: 'c2', label: 'Designation' }, { key: 'c3', label: 'Department' }, { key: 'c4', label: 'Shift' }, { key: 'c5', label: 'Status' }, { key: 'c6', label: '' }]" :rows="(employees).map((e) => ({ __s: { e }, c0: (e.employee_no), c1: `${e.first_name} ${e.last_name}`, c2: (e.designation), c3: (e.department ?? '-'), c4: (e.shift ?? '-'), c5: (e.status), c6: '' }))" export-name="no">
<template #cell-c6="{ row: __m }"><template v-for="{ e } in [__m.__s]" :key="0"><button v-if="canManage" type="button" class="btn btn-outline-dark btn-sm" @click="edit(e)">Edit</button></template></template>
</ClientTable>

            <h6 id="hrm-documents" class="mt-4">Organisation and documents</h6>
            <ul class="small">
                <li v-for="dep in tree" :key="String(dep.id)">
                    <strong>{{ dep.name }}</strong>
                    <ul>
                        <li v-for="p in dep.people" :key="p.id">{{ p.employee_no }} {{ p.name }} <span class="text-muted">{{ p.designation }}</span>
                            <ul v-if="p.reports.length"><li v-for="r in p.reports" :key="r.id">{{ r.employee_no }} {{ r.name }}</li></ul>
                        </li>
                    </ul>
                </li>
            </ul>
        </section>

        <section v-if="tab === 'attendance'">
            <div v-if="canAttend" class="row g-3 mb-3">
                <div class="col-lg-6 border rounded p-3">
                    <h6>Mark attendance</h6>
                    <div class="row g-2">
                        <div class="col-md-4"><select v-model="punch.employee_id" class="form-select form-select-sm" aria-label="Employee"><option value="">Employee</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.employee_no }} {{ e.first_name }}</option></select></div>
                        <div class="col-md-3"><input v-model="punch.work_date" type="date" class="form-control form-control-sm" aria-label="Date" /></div>
                        <div class="col-md-2"><input v-model="punch.check_in" placeholder="09:00" class="form-control form-control-sm" aria-label="Check in" /></div>
                        <div class="col-md-2"><input v-model="punch.check_out" placeholder="18:00" class="form-control form-control-sm" aria-label="Check out" /></div>
                        <div class="col-md-3"><select v-model="punch.status" class="form-select form-select-sm" aria-label="Status"><option v-for="s in meta?.attendance_statuses ?? []" :key="s" :value="s">{{ s }}</option></select></div>
                        <div class="col-md-3"><button type="button" class="btn btn-dark btn-sm" @click="post('/api/hrm/attendance', { ...punch }, 'Attendance saved')">Save</button></div>
                    </div>
                </div>
                <div class="col-lg-6 border rounded p-3">
                    <h6>Import CSV (employee_no,date,check_in,check_out)</h6>
                    <textarea v-model="csv" rows="3" class="form-control form-control-sm mb-2" aria-label="Attendance CSV"></textarea>
                    <button type="button" class="btn btn-outline-dark btn-sm" @click="importCsv">Import</button>
                    <button type="button" class="btn btn-outline-dark btn-sm ms-2" @click="post('/api/hrm/attendance/process-punches', {}, 'Device punches processed')">Process device punches</button>
                    <div v-if="importResult" class="small mt-2">{{ importResult }}</div>
                </div>
                <div class="col-lg-6 border rounded p-3">
                    <h6>Request leave</h6>
                    <div class="row g-2">
                        <div class="col-md-4"><select v-model="leave.employee_id" class="form-select form-select-sm" aria-label="Leave employee"><option value="">Employee</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.employee_no }} {{ e.first_name }}</option></select></div>
                        <div class="col-md-3"><select v-model="leave.leave_type_id" class="form-select form-select-sm" aria-label="Leave type"><option value="">Type</option><option v-for="t in meta?.leave_types ?? []" :key="t.id" :value="t.id">{{ t.name }}</option></select></div>
                        <div class="col-md-2"><input v-model="leave.from_date" type="date" class="form-control form-control-sm" aria-label="From" /></div>
                        <div class="col-md-2"><input v-model="leave.to_date" type="date" class="form-control form-control-sm" aria-label="To" /></div>
                        <div class="col-md-1"><button type="button" class="btn btn-dark btn-sm" @click="post('/api/hrm/leave-requests', { ...leave }, 'Leave requested')">Ask</button></div>
                    </div>
                </div>
                <div v-if="canManage" class="col-lg-6 border rounded p-3">
                    <h6>Attendance devices</h6>
                    <div class="d-flex gap-2 mb-2"><input v-model="deviceName" class="form-control form-control-sm" placeholder="Device name" aria-label="Device name" /><button type="button" class="btn btn-outline-dark btn-sm" @click="createDevice">Add</button></div>
                    <div v-if="newToken" class="alert alert-warning py-1 small">Token (shown once): <code>{{ newToken }}</code>. The device posts punches to <code>/api/hrm/device/punch</code> with the header X-Device-Token.</div>
                    <div v-for="d in devices" :key="d.id" class="small">{{ d.name }} <span class="text-muted">last used {{ d.last_used_at ?? 'never' }}</span></div>
                </div>
            </div>

            <h6 id="hrm-leave">Leave requests</h6>
            <ClientTable empty-text="No leave requests."  :columns="[{ key: 'c0', label: 'Employee' }, { key: 'c1', label: 'Type' }, { key: 'c2', label: 'From' }, { key: 'c3', label: 'To' }, { key: 'c4', label: 'Days' }, { key: 'c5', label: 'Status' }, { key: 'c6', label: '' }]" :rows="(leaves).map((l) => ({ __s: { l }, c0: `${l.employee_no} ${l.first_name}`, c1: (l.leave_type), c2: (String(l.from_date).slice(0, 10)), c3: (String(l.to_date).slice(0, 10)), c4: (l.days), c5: (l.status), c6: '' }))" export-name="employee">
<template #cell-c6="{ row: __m }"><template v-for="{ l } in [__m.__s]" :key="0">
                            <template v-if="l.status === 'pending' && canLeaveApprove">
                                <button type="button" class="btn btn-outline-success btn-sm me-1" @click="decideLeave(l.id, 'approve')">Approve</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="decideLeave(l.id, 'reject')">Reject</button>
                            </template>
                        </template></template>
</ClientTable>

            <h6 class="mt-3">Attendance of this month</h6>
            <ClientTable  :columns="[{ key: 'c0', label: 'Date' }, { key: 'c1', label: 'Employee' }, { key: 'c2', label: 'In' }, { key: 'c3', label: 'Out' }, { key: 'c4', label: 'Status' }, { key: 'c5', label: 'Late' }, { key: 'c6', label: 'Overtime' }, { key: 'c7', label: 'Source' }]" :rows="(attendance.slice(0, 100)).map((a) => ({ __s: { a }, c0: (String(a.work_date).slice(0, 10)), c1: `${a.employee_no} ${a.first_name}`, c2: (a.check_in), c3: (a.check_out), c4: (a.status), c5: (a.late_minutes), c6: (a.overtime_minutes), c7: (a.source) }))" export-name="date">
</ClientTable>
        </section>

        <section v-if="tab === 'payroll'">
            <div v-if="canPayroll" class="row g-3 mb-3">
                <div class="col-lg-4 border rounded p-3">
                    <h6>Prepare payroll</h6>
                    <div class="d-flex gap-2"><input v-model="period" type="month" class="form-control form-control-sm" aria-label="Month" /><button type="button" class="btn btn-dark btn-sm" @click="createRun">Prepare</button></div>
                </div>
                <div class="col-lg-4 border rounded p-3">
                    <h6>Bonus</h6>
                    <div class="row g-2">
                        <div class="col-6"><select v-model="bonus.employee_id" class="form-select form-select-sm" aria-label="Bonus employee"><option value="">Employee</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.employee_no }}</option></select></div>
                        <div class="col-6"><input v-model="bonus.period" type="month" class="form-control form-control-sm" aria-label="Bonus month" /></div>
                        <div class="col-6"><input v-model="bonus.title" class="form-control form-control-sm" placeholder="Title" aria-label="Bonus title" /></div>
                        <div class="col-4"><input v-model.number="bonus.amount" type="number" class="form-control form-control-sm" aria-label="Bonus amount" /></div>
                        <div class="col-2"><button type="button" class="btn btn-outline-dark btn-sm" @click="post('/api/hrm/bonuses', { ...bonus }, 'Bonus saved')">Add</button></div>
                    </div>
                </div>
                <div class="col-lg-4 border rounded p-3">
                    <h6>Loan or advance</h6>
                    <div class="row g-2">
                        <div class="col-6"><select v-model="loan.employee_id" class="form-select form-select-sm" aria-label="Loan employee"><option value="">Employee</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.employee_no }}</option></select></div>
                        <div class="col-6"><select v-model="loan.kind" class="form-select form-select-sm" aria-label="Kind"><option value="advance">Advance</option><option value="loan">Loan</option></select></div>
                        <div class="col-4"><input v-model.number="loan.amount" type="number" class="form-control form-control-sm" placeholder="Amount" aria-label="Loan amount" /></div>
                        <div class="col-4"><input v-model.number="loan.installment" type="number" class="form-control form-control-sm" placeholder="Instalment" aria-label="Instalment" /></div>
                        <div class="col-4"><button type="button" class="btn btn-outline-dark btn-sm" @click="post('/api/hrm/loans', { ...loan }, 'Loan saved')">Add</button></div>
                    </div>
                </div>
            </div>

            <ClientTable empty-text="No payroll prepared yet."  :columns="[{ key: 'c0', label: 'Month' }, { key: 'c1', label: 'Status' }, { key: 'c2', label: 'Employees' }, { key: 'c3', label: 'Gross' }, { key: 'c4', label: 'Net' }, { key: 'c5', label: '' }]" :rows="(runs).map((r) => ({ __s: { r }, c0: (r.period), c1: (r.status), c2: (r.totals.employees ?? 0), c3: (money(r.totals.gross)), c4: (money(r.totals.net)), c5: '' }))" export-name="month">
<template #cell-c5="{ row: __m }"><template v-for="{ r } in [__m.__s]" :key="0"><button type="button" class="btn btn-outline-dark btn-sm" @click="openRun(r.id)">Open</button></template></template>
</ClientTable>

            <div v-if="current" class="border rounded p-3">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <h6 class="mb-0 me-3">Payroll {{ current.period }} <span class="badge text-bg-secondary text-capitalize">{{ current.status }}</span></h6>
                    <button v-if="current.status === 'draft'" type="button" class="btn btn-outline-dark btn-sm" @click="runAction('/api/hrm/payroll/:id/recalculate', 'Payroll recalculated')">Recalculate</button>
                    <button v-if="current.status === 'draft' && canApprove" type="button" class="btn btn-success btn-sm" @click="runAction('/api/payroll-runs/:id/approve', 'Payroll approved')">Approve</button>
                    <button v-if="current.status === 'approved' && canApprove" type="button" class="btn btn-dark btn-sm" @click="runAction('/api/hrm/payroll/:id/post', 'Posted to the ledger')">Post to ledger</button>
                    <button v-if="['draft', 'approved'].includes(current.status)" type="button" class="btn btn-outline-danger btn-sm" @click="runAction('/api/hrm/payroll/:id/cancel', 'Payroll cancelled')">Cancel</button>
                    <template v-if="['approved', 'posted'].includes(current.status) && canApprove">
                        <button type="button" class="btn btn-outline-dark btn-sm" @click="bankFile('generic')">Bank file (CSV)</button>
                        <button type="button" class="btn btn-outline-dark btn-sm" @click="bankFile('ibft')">Bank file (IBFT layout)</button>
                    </template>
                </div>
                <ClientTable  :columns="[{ key: 'c0', label: 'No' }, { key: 'c1', label: 'Employee' }, { key: 'c2', label: 'Gross' }, { key: 'c3', label: 'Attendance' }, { key: 'c4', label: 'Deductions' }, { key: 'c5', label: 'Tax' }, { key: 'c6', label: 'Loan' }, { key: 'c7', label: 'Net' }, { key: 'c8', label: '' }]" :rows="(current.lines).map((l) => ({ __s: { l }, c0: (l.employee_no), c1: (l.name), c2: (money(l.gross)), c3: (money(l.attendance_deduction)), c4: (money(l.statutory_employee)), c5: (money(l.tax)), c6: (money(l.loan_deduction)), c7: (money(l.net)), c8: '' }))" export-name="no">
<template #cell-c8="{ row: __m }"><template v-for="{ l } in [__m.__s]" :key="0"><button type="button" class="btn btn-outline-dark btn-sm" @click="payslip(l.id)">Payslip</button></template></template>
</ClientTable>
            </div>

            <h6 id="hrm-loans" class="mt-4">Loans and advances</h6>
            <ClientTable  :columns="[{ key: 'c0', label: 'Employee' }, { key: 'c1', label: 'Kind' }, { key: 'c2', label: 'Amount' }, { key: 'c3', label: 'Instalment' }, { key: 'c4', label: 'Balance' }, { key: 'c5', label: 'Status' }]" :rows="(loans).map((l) => ({ __s: { l }, c0: `${l.employee_no} ${l.first_name}`, c1: (l.kind), c2: (money(l.amount)), c3: (money(l.installment)), c4: (money(l.balance)), c5: (l.status) }))" export-name="employee">
</ClientTable>
        </section>

        <section v-if="tab === 'setup'">
            <div class="alert alert-info small">
                Statutory rates (EOBI, provident fund, social security and similar) and income tax slabs are entered here by you. The application does not ship any rate:
                <strong>rates accountant se verify karein</strong> before the first payroll.
            </div>
            <div v-if="canManage" class="row g-3 mb-3">
                <div class="col-lg-6 border rounded p-3">
                    <h6>Statutory rate</h6>
                    <div class="row g-2">
                        <div class="col-3"><input v-model="rate.code" class="form-control form-control-sm" placeholder="Code" aria-label="Code" /></div>
                        <div class="col-5"><input v-model="rate.name" class="form-control form-control-sm" placeholder="Name" aria-label="Rate name" /></div>
                        <div class="col-4"><select v-model="rate.side" class="form-select form-select-sm" aria-label="Side"><option value="employee">Employee share</option><option value="employer">Employer share</option></select></div>
                        <div class="col-3"><select v-model="rate.basis" class="form-select form-select-sm" aria-label="Basis"><option value="basic">of basic</option><option value="gross">of gross</option></select></div>
                        <div class="col-3"><input v-model.number="rate.rate_percent" type="number" step="0.01" class="form-control form-control-sm" placeholder="%" aria-label="Rate percent" /></div>
                        <div class="col-3"><input v-model.number="rate.fixed_amount" type="number" class="form-control form-control-sm" placeholder="Fixed" aria-label="Fixed amount" /></div>
                        <div class="col-3"><input v-model.number="rate.base_cap" type="number" class="form-control form-control-sm" placeholder="Cap" aria-label="Base cap" /></div>
                        <div class="col-5"><input v-model="rate.effective_from" type="date" class="form-control form-control-sm" aria-label="Effective from" /></div>
                        <div class="col-3"><button type="button" class="btn btn-dark btn-sm" @click="post('/api/hrm/rates', { ...rate, base_cap: rate.base_cap === '' ? null : rate.base_cap }, 'Rate saved')">Save</button></div>
                    </div>
                </div>
                <div class="col-lg-6 border rounded p-3">
                    <h6>Income tax slab (annual)</h6>
                    <div class="row g-2">
                        <div class="col-4"><input v-model="slab.effective_from" type="date" class="form-control form-control-sm" aria-label="Slab effective from" /></div>
                        <div class="col-4"><input v-model.number="slab.annual_from" type="number" class="form-control form-control-sm" placeholder="From" aria-label="Annual from" /></div>
                        <div class="col-4"><input v-model.number="slab.annual_to" type="number" class="form-control form-control-sm" placeholder="To (blank = no limit)" aria-label="Annual to" /></div>
                        <div class="col-4"><input v-model.number="slab.fixed_amount" type="number" class="form-control form-control-sm" placeholder="Fixed tax" aria-label="Fixed tax" /></div>
                        <div class="col-4"><input v-model.number="slab.rate_percent" type="number" step="0.01" class="form-control form-control-sm" placeholder="% above From" aria-label="Slab rate" /></div>
                        <div class="col-4"><button type="button" class="btn btn-dark btn-sm" @click="post('/api/hrm/tax-slabs', { ...slab, annual_to: slab.annual_to === '' ? null : slab.annual_to }, 'Slab saved')">Save</button></div>
                    </div>
                </div>
                <div class="col-lg-6 border rounded p-3">
                    <h6>Salary component</h6>
                    <div class="row g-2">
                        <div class="col-4"><input v-model="component.name" class="form-control form-control-sm" placeholder="Name" aria-label="Component name" /></div>
                        <div class="col-3"><select v-model="component.type" class="form-select form-select-sm" aria-label="Type"><option value="earning">Earning</option><option value="deduction">Deduction</option></select></div>
                        <div class="col-3"><select v-model="component.calc" class="form-select form-select-sm" aria-label="Calculation"><option value="fixed">Fixed</option><option value="percent_basic">% of basic</option></select></div>
                        <div class="col-2"><button type="button" class="btn btn-dark btn-sm" @click="post('/api/hrm/components', { ...component }, 'Component saved')">Save</button></div>
                    </div>
                </div>
                <div class="col-lg-6 border rounded p-3">
                    <h6 id="hrm-shifts">Shift</h6>
                    <div class="row g-2">
                        <div class="col-3"><input v-model="shift.name" class="form-control form-control-sm" placeholder="Name" aria-label="Shift name" /></div>
                        <div class="col-2"><input v-model="shift.start_time" class="form-control form-control-sm" aria-label="Start" /></div>
                        <div class="col-2"><input v-model="shift.end_time" class="form-control form-control-sm" aria-label="End" /></div>
                        <div class="col-2"><input v-model.number="shift.grace_minutes" type="number" class="form-control form-control-sm" aria-label="Grace minutes" /></div>
                        <div class="col-3"><button type="button" class="btn btn-dark btn-sm" @click="post('/api/hrm/shifts', { ...shift }, 'Shift saved')">Save</button></div>
                    </div>
                </div>
                <div class="col-12 border rounded p-3">
                    <h6>HR settings</h6>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2"><label class="form-label small">Days basis</label><select v-model="settings.days_basis" class="form-select form-select-sm"><option value="calendar">Calendar days</option><option value="fixed">Fixed days</option></select></div>
                        <div class="col-md-2"><label class="form-label small">Overtime multiplier</label><input v-model.number="settings.overtime_multiplier" type="number" step="0.1" class="form-control form-control-sm" /></div>
                        <div class="col-md-2"><label class="form-label small">Gratuity days / year</label><input v-model.number="settings.gratuity_days_per_year" type="number" class="form-control form-control-sm" /></div>
                        <div class="col-md-2"><label class="form-label small">Document alert days</label><input v-model.number="settings.document_alert_days" type="number" class="form-control form-control-sm" /></div>
                        <div class="col-md-2 form-check ms-3"><input id="hr-late" v-model="settings.deduct_late" type="checkbox" class="form-check-input" /><label class="form-check-label small" for="hr-late">Deduct late minutes</label></div>
                        <div class="col-md-2 form-check ms-3"><input id="hr-post" v-model="settings.posting_enabled" type="checkbox" class="form-check-input" /><label class="form-check-label small" for="hr-post">Post payroll to the ledger</label></div>
                        <div class="col-md-2"><button type="button" class="btn btn-dark btn-sm" @click="saveSettings">Save settings</button></div>
                    </div>
                    <div v-if="settings.posting_enabled" class="row g-2 mt-2">
                        <div class="col-12 small text-muted">Chart of account ids for the posting voucher. Every account a payroll uses must be mapped before it can be posted.</div>
                        <div v-for="slot in ['salary_expense', 'salary_payable', 'tax_payable', 'statutory_payable', 'loan_receivable', 'accrual_expense', 'accrual_liability']" :key="slot" class="col-md-3">
                            <label class="form-label small text-capitalize" :for="`hr-acc-${slot}`">{{ slot.replace('_', ' ') }} account id</label>
                            <input :id="`hr-acc-${slot}`" v-model.number="settings.accounts[slot]" type="number" class="form-control form-control-sm" />
                        </div>
                    </div>
                </div>
            </div>

            <h6>Statutory rates in force</h6>
            <ClientTable empty-text="No statutory rate entered: none is deducted."  :columns="[{ key: 'c0', label: 'Code' }, { key: 'c1', label: 'Name' }, { key: 'c2', label: 'Side' }, { key: 'c3', label: 'Basis' }, { key: 'c4', label: '%' }, { key: 'c5', label: 'Fixed' }, { key: 'c6', label: 'From' }]" :rows="(rates).map((r) => ({ __s: { r }, c0: (r.code), c1: (r.name), c2: (r.side), c3: (r.basis), c4: (r.rate_percent), c5: (r.fixed_amount), c6: (String(r.effective_from).slice(0, 10)) }))" export-name="code">
</ClientTable>
            <h6>Income tax slabs</h6>
            <ClientTable empty-text="No tax slab entered: no income tax is deducted."  :columns="[{ key: 'c0', label: 'From (annual)' }, { key: 'c1', label: 'To' }, { key: 'c2', label: 'Fixed' }, { key: 'c3', label: '%' }, { key: 'c4', label: 'Effective' }]" :rows="(slabs).map((s) => ({ __s: { s }, c0: (s.annual_from), c1: (s.annual_to ?? 'no limit'), c2: (s.fixed_amount), c3: (s.rate_percent), c4: (String(s.effective_from).slice(0, 10)) }))" export-name="from-annual">
</ClientTable>
            <div class="small text-muted">{{ components.length }} salary component(s), {{ shifts.length }} shift(s).</div>

            <h6 id="hrm-holidays" class="mt-4">Holidays</h6>
            <div v-if="canManage" class="row g-2 align-items-end mb-2" data-test="holiday-form">
                <div class="col-md-2"><label class="form-label small" for="hol-date">Date</label><input id="hol-date" v-model="holiday.holiday_date" type="date" class="form-control form-control-sm" /></div>
                <div class="col-md-4"><label class="form-label small" for="hol-name">Name</label><input id="hol-name" v-model="holiday.name" type="text" maxlength="120" class="form-control form-control-sm" /></div>
                <div class="col-md-2"><button type="button" class="btn btn-dark btn-sm" :disabled="! holiday.holiday_date || holiday.name.trim().length < 2" data-test="holiday-save" @click="saveHoliday">{{ holiday.id ? 'Save holiday' : 'Add holiday' }}</button></div>
                <div class="col-md-4 d-flex gap-2 align-items-end">
                    <div><label class="form-label small" for="hol-year">Year</label><input id="hol-year" v-model.number="holidayYear" type="number" min="2000" max="2100" class="form-control form-control-sm" style="width: 6rem" /></div>
                    <button type="button" class="btn btn-outline-dark btn-sm" data-test="holiday-import" @click="importHolidays">Import Pakistan public holidays</button>
                </div>
            </div>
            <p v-if="canManage" class="small text-muted mb-2">The national days are fixed; Eid, Ashura and Eid Milad un Nabi follow the moon sighting, so check the imported dates each year (each row can be edited or deleted).</p>
            <ClientTable empty-text="No holidays yet." :columns="[{ key: 'c0', label: 'Date' }, { key: 'c1', label: 'Holiday' }, { key: 'c2', label: 'Kind' }, { key: 'c3', label: '' }]" :rows="(holidays).map((h) => ({ __s: { h }, c0: String(h.holiday_date).slice(0, 10), c1: h.name, c2: h.kind, c3: '' }))" export-name="holidays">
                <template #cell-c3="{ row: __m }"><template v-for="{ h } in [__m.__s]" :key="0">
                    <template v-if="canManage">
                        <button type="button" class="btn btn-outline-dark btn-sm me-1" @click="Object.assign(holiday, { id: h.id, holiday_date: String(h.holiday_date).slice(0, 10), name: h.name })">Edit</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" @click="removeHoliday(h.id)">Delete</button>
                    </template>
                </template></template>
            </ClientTable>
        </section>
    </div></div></div>
</template>
