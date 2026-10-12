<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref } from 'vue';
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
    const tab = ref<'employees' | 'attendance' | 'payroll' | 'setup'>('employees');
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

    async function load() {
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

    onMounted(load);
</script>

<template>
    <Head title="HR and Payroll" />

    <div class="container-fluid py-3">
        <input v-if="isSuperadmin" v-model.number="companyId" type="number" class="form-control form-control-sm mb-3" style="width: 10rem" placeholder="Company id" aria-label="Company id" @change="load" />

        <div v-if="expiring.length" class="alert alert-warning py-2 small">
            {{ expiring.length }} employee document(s) expired or expiring soon:
            <span v-for="doc in expiring.slice(0, 5)" :key="doc.id" class="me-2">{{ doc.employee }} - {{ doc.title }} ({{ doc.expires_on }})</span>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li v-for="item in [['employees', 'Employees'], ['attendance', 'Attendance and leave'], ['payroll', 'Payroll'], ['setup', 'Rates and setup']]" :key="item[0]" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: tab === item[0] }" @click="tab = item[0] as typeof tab">{{ item[1] }}</button>
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

            <table class="table table-sm">
                <thead><tr><th>No</th><th>Name</th><th>Designation</th><th>Department</th><th>Shift</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="e in employees" :key="e.id">
                        <td>{{ e.employee_no }}</td><td>{{ e.first_name }} {{ e.last_name }}</td><td>{{ e.designation }}</td><td>{{ e.department ?? '-' }}</td><td>{{ e.shift ?? '-' }}</td><td class="text-capitalize">{{ e.status }}</td>
                        <td class="text-end"><button v-if="canManage" type="button" class="btn btn-outline-dark btn-sm" @click="edit(e)">Edit</button></td>
                    </tr>
                    <tr v-if="! employees.length"><td colspan="7" class="text-center text-muted">No employees yet.</td></tr>
                </tbody>
            </table>

            <h6 class="mt-4">Organisation</h6>
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

            <h6>Leave requests</h6>
            <table class="table table-sm">
                <thead><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="l in leaves" :key="l.id">
                        <td>{{ l.employee_no }} {{ l.first_name }}</td><td>{{ l.leave_type }}</td><td>{{ String(l.from_date).slice(0, 10) }}</td><td>{{ String(l.to_date).slice(0, 10) }}</td><td>{{ l.days }}</td><td class="text-capitalize">{{ l.status }}</td>
                        <td class="text-end">
                            <template v-if="l.status === 'pending' && canLeaveApprove">
                                <button type="button" class="btn btn-outline-success btn-sm me-1" @click="decideLeave(l.id, 'approve')">Approve</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="decideLeave(l.id, 'reject')">Reject</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="! leaves.length"><td colspan="7" class="text-center text-muted">No leave requests.</td></tr>
                </tbody>
            </table>

            <h6 class="mt-3">Attendance of this month</h6>
            <table class="table table-sm">
                <thead><tr><th>Date</th><th>Employee</th><th>In</th><th>Out</th><th>Status</th><th>Late</th><th>Overtime</th><th>Source</th></tr></thead>
                <tbody>
                    <tr v-for="a in attendance.slice(0, 100)" :key="a.id"><td>{{ String(a.work_date).slice(0, 10) }}</td><td>{{ a.employee_no }} {{ a.first_name }}</td><td>{{ a.check_in }}</td><td>{{ a.check_out }}</td><td>{{ a.status }}</td><td>{{ a.late_minutes }}</td><td>{{ a.overtime_minutes }}</td><td>{{ a.source }}</td></tr>
                </tbody>
            </table>
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

            <table class="table table-sm">
                <thead><tr><th>Month</th><th>Status</th><th class="text-end">Employees</th><th class="text-end">Gross</th><th class="text-end">Net</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="r in runs" :key="r.id">
                        <td>{{ r.period }}</td><td class="text-capitalize">{{ r.status }}</td><td class="text-end">{{ r.totals.employees ?? 0 }}</td><td class="text-end">{{ money(r.totals.gross) }}</td><td class="text-end">{{ money(r.totals.net) }}</td>
                        <td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="openRun(r.id)">Open</button></td>
                    </tr>
                    <tr v-if="! runs.length"><td colspan="6" class="text-center text-muted">No payroll prepared yet.</td></tr>
                </tbody>
            </table>

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
                <table class="table table-sm mb-0">
                    <thead><tr><th>No</th><th>Employee</th><th class="text-end">Gross</th><th class="text-end">Attendance</th><th class="text-end">Deductions</th><th class="text-end">Tax</th><th class="text-end">Loan</th><th class="text-end">Net</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="l in current.lines" :key="l.id">
                            <td>{{ l.employee_no }}</td><td>{{ l.name }}</td><td class="text-end">{{ money(l.gross) }}</td><td class="text-end">{{ money(l.attendance_deduction) }}</td><td class="text-end">{{ money(l.statutory_employee) }}</td><td class="text-end">{{ money(l.tax) }}</td><td class="text-end">{{ money(l.loan_deduction) }}</td><td class="text-end">{{ money(l.net) }}</td>
                            <td class="text-end"><button type="button" class="btn btn-outline-dark btn-sm" @click="payslip(l.id)">Payslip</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h6 class="mt-4">Loans and advances</h6>
            <table class="table table-sm">
                <thead><tr><th>Employee</th><th>Kind</th><th class="text-end">Amount</th><th class="text-end">Instalment</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
                <tbody><tr v-for="l in loans" :key="l.id"><td>{{ l.employee_no }} {{ l.first_name }}</td><td>{{ l.kind }}</td><td class="text-end">{{ money(l.amount) }}</td><td class="text-end">{{ money(l.installment) }}</td><td class="text-end">{{ money(l.balance) }}</td><td>{{ l.status }}</td></tr></tbody>
            </table>
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
                    <h6>Shift</h6>
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
            <table class="table table-sm">
                <thead><tr><th>Code</th><th>Name</th><th>Side</th><th>Basis</th><th class="text-end">%</th><th class="text-end">Fixed</th><th>From</th></tr></thead>
                <tbody>
                    <tr v-for="r in rates" :key="r.id"><td>{{ r.code }}</td><td>{{ r.name }}</td><td>{{ r.side }}</td><td>{{ r.basis }}</td><td class="text-end">{{ r.rate_percent }}</td><td class="text-end">{{ r.fixed_amount }}</td><td>{{ String(r.effective_from).slice(0, 10) }}</td></tr>
                    <tr v-if="! rates.length"><td colspan="7" class="text-center text-muted">No statutory rate entered: none is deducted.</td></tr>
                </tbody>
            </table>
            <h6>Income tax slabs</h6>
            <table class="table table-sm">
                <thead><tr><th>From (annual)</th><th>To</th><th class="text-end">Fixed</th><th class="text-end">%</th><th>Effective</th></tr></thead>
                <tbody>
                    <tr v-for="s in slabs" :key="s.id"><td>{{ s.annual_from }}</td><td>{{ s.annual_to ?? 'no limit' }}</td><td class="text-end">{{ s.fixed_amount }}</td><td class="text-end">{{ s.rate_percent }}</td><td>{{ String(s.effective_from).slice(0, 10) }}</td></tr>
                    <tr v-if="! slabs.length"><td colspan="5" class="text-center text-muted">No tax slab entered: no income tax is deducted.</td></tr>
                </tbody>
            </table>
            <div class="small text-muted">{{ components.length }} salary component(s), {{ shifts.length }} shift(s).</div>
        </section>
    </div>
</template>
