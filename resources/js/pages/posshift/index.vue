<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, reactive, ref } from 'vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'POS Shifts',
            subtitle: 'Open and close the cash drawer, record cash in and out, and read the X and Z reports',
            breadcrumbs: [
                {
                    title: 'POS Shifts',
                    href: 'NULL',
                },
            ],
        },
    });

    type Report = {
        opened_at: string;
        until: string;
        sales_count: number;
        sales_total: number;
        payments: Record<string, number>;
        cash_payments: number;
        pay_in: number;
        pay_out: number;
        opening_float: number;
        expected_cash: number;
    };

    type Shift = {
        id: number;
        branch_name: string | null;
        cashier: string | null;
        status: string;
        opening_float: number;
        opened_at: string;
        closed_at: string | null;
        expected_cash: number | null;
        counted_cash: number | null;
        variance: number | null;
        report_type?: 'X' | 'Z';
        report?: Report | null;
        movements?: Array<{ id: number; type: string; amount: number; reason: string }>;
    };

    const { Notify } = useCommons();
    const { props } = usePage();

    const authUser = computed(() => props.auth?.user as { rolename?: string; permission_paths?: string[]; branch_id?: number | string | null } | null);
    const isSuperadmin = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string): boolean => isSuperadmin.value || (authUser.value?.permission_paths ?? []).includes(path);

    const current = ref<Shift | null>(null);
    const history = ref<Shift[]>([]);
    const viewing = ref<Shift | null>(null);
    const busy = ref(false);

    const openForm = reactive({ opening_float: '0', branch_id: '', note: '' });
    const moveForm = reactive({ type: 'in', amount: '', reason: '' });
    const closeForm = reactive({ counted_cash: '', note: '' });

    const money = (value: unknown): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function errorText(error: unknown): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors;

            return (errors && (Object.values(errors).flat()[0] as string)) || error.response?.data?.message || 'Something went wrong.';
        }

        return 'Something went wrong.';
    }

    async function load() {
        const [currentResponse, historyResponse] = await Promise.all([
            window.axios.get('/api/pos-shifts/current'),
            window.axios.get('/api/pos-shifts'),
        ]);

        current.value = currentResponse.data?.data ?? null;
        history.value = historyResponse.data?.data?.data ?? [];
    }

    async function run(action: () => Promise<void>) {
        busy.value = true;

        try {
            await action();
        } catch (error: unknown) {
            Notify(errorText(error), 'alert');
        } finally {
            busy.value = false;
        }
    }

    const openShift = () => run(async () => {
        const body: Record<string, string | number> = { opening_float: openForm.opening_float, note: openForm.note };

        if (openForm.branch_id !== '') {
            body.branch_id = openForm.branch_id;
        }

        await window.axios.post('/api/pos-shifts/open', body);
        Notify('Shift opened', 'success');
        await load();
    });

    const addMovement = () => run(async () => {
        await window.axios.post(`/api/pos-shifts/${current.value?.id}/movement`, moveForm);
        moveForm.amount = '';
        moveForm.reason = '';
        await load();
    });

    const closeShift = () => run(async () => {
        if (! window.confirm('Close this shift? The counted cash cannot be changed afterwards.')) {
            return;
        }

        const response = await window.axios.post(`/api/pos-shifts/${current.value?.id}/close`, closeForm);
        viewing.value = response.data?.data ?? null;
        closeForm.counted_cash = '';
        closeForm.note = '';
        await load();
    });

    const view = (shift: Shift) => run(async () => {
        viewing.value = (await window.axios.get(`/api/pos-shifts/${shift.id}`)).data?.data ?? null;
    });

    onMounted(() => run(load));
</script>

<template>
    <Head title="POS Shifts" />

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="mb-3">Your drawer</h6>

            <form v-if="! current && can('/posshift/open')" class="row g-2 align-items-end" @submit.prevent="openShift">
                <div class="col-md-3">
                    <label class="form-label">Opening float</label>
                    <input v-model="openForm.opening_float" type="number" min="0" step="0.01" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Branch ID (superadmin / company admin)</label>
                    <input v-model="openForm.branch_id" type="number" class="form-control" placeholder="your branch">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Note</label>
                    <input v-model="openForm.note" class="form-control" maxlength="500">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary" :disabled="busy">Open shift</button>
                </div>
            </form>
            <p v-else-if="! current" class="text-muted mb-0">No open shift.</p>

            <template v-if="current">
                <p class="mb-2">
                    Open since {{ current.opened_at }} · float {{ money(current.opening_float) }}
                    <span class="badge bg-success-subtle text-success ms-2">X report (live)</span>
                </p>

                <table v-if="current.report" class="table table-sm w-auto">
                    <tbody>
                        <tr><td>Sales</td><td class="text-end">{{ current.report.sales_count }} · {{ money(current.report.sales_total) }}</td></tr>
                        <tr v-for="(amount, method) in current.report.payments" :key="method"><td class="text-capitalize">{{ method }} payments</td><td class="text-end">{{ money(amount) }}</td></tr>
                        <tr><td>Cash in / out</td><td class="text-end">{{ money(current.report.pay_in) }} / {{ money(current.report.pay_out) }}</td></tr>
                        <tr class="fw-bold"><td>Expected cash in drawer</td><td class="text-end">{{ money(current.report.expected_cash) }}</td></tr>
                    </tbody>
                </table>

                <form v-if="can('/posshift/movement')" class="row g-2 align-items-end mb-3" @submit.prevent="addMovement">
                    <div class="col-md-2">
                        <select v-model="moveForm.type" class="form-select"><option value="in">Cash in</option><option value="out">Cash out</option></select>
                    </div>
                    <div class="col-md-2"><input v-model="moveForm.amount" type="number" min="0.01" step="0.01" class="form-control" placeholder="Amount" required></div>
                    <div class="col-md-5"><input v-model="moveForm.reason" class="form-control" placeholder="Reason" maxlength="255" required></div>
                    <div class="col-md-3"><button type="submit" class="btn btn-outline-primary" :disabled="busy">Record</button></div>
                </form>

                <form v-if="can('/posshift/close')" class="row g-2 align-items-end" @submit.prevent="closeShift">
                    <div class="col-md-3">
                        <label class="form-label">Cash counted</label>
                        <input v-model="closeForm.counted_cash" type="number" min="0" step="0.01" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Note</label>
                        <input v-model="closeForm.note" class="form-control" maxlength="500">
                    </div>
                    <div class="col-md-3"><button type="submit" class="btn btn-danger" :disabled="busy">Close shift</button></div>
                </form>
            </template>
        </div>
    </div>

    <div v-if="viewing" class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h6>{{ viewing.report_type }} report · shift #{{ viewing.id }} · {{ viewing.cashier }}</h6>
                <button type="button" class="btn-close" aria-label="Close" @click="viewing = null" />
            </div>
            <table v-if="viewing.report" class="table table-sm w-auto">
                <tbody>
                    <tr><td>Opened</td><td class="text-end">{{ viewing.opened_at }}</td></tr>
                    <tr v-if="viewing.closed_at"><td>Closed</td><td class="text-end">{{ viewing.closed_at }}</td></tr>
                    <tr><td>Opening float</td><td class="text-end">{{ money(viewing.report.opening_float) }}</td></tr>
                    <tr><td>Sales</td><td class="text-end">{{ viewing.report.sales_count }} · {{ money(viewing.report.sales_total) }}</td></tr>
                    <tr v-for="(amount, method) in viewing.report.payments" :key="method"><td class="text-capitalize">{{ method }} payments</td><td class="text-end">{{ money(amount) }}</td></tr>
                    <tr><td>Cash in / out</td><td class="text-end">{{ money(viewing.report.pay_in) }} / {{ money(viewing.report.pay_out) }}</td></tr>
                    <tr><td>Expected cash</td><td class="text-end">{{ money(viewing.report.expected_cash) }}</td></tr>
                    <template v-if="viewing.status === 'closed'">
                        <tr><td>Counted cash</td><td class="text-end">{{ money(viewing.counted_cash) }}</td></tr>
                        <tr class="fw-bold"><td>Variance</td><td class="text-end" :class="Number(viewing.variance) === 0 ? '' : Number(viewing.variance) < 0 ? 'text-danger' : 'text-warning'">{{ money(viewing.variance) }}</td></tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h6 class="mb-3">Shift history</h6>
            <p v-if="! history.length" class="text-muted mb-0">No shifts yet.</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr><th>#</th><th>Cashier</th><th>Branch</th><th>Opened</th><th>Closed</th><th>Status</th><th class="text-end">Expected</th><th class="text-end">Counted</th><th class="text-end">Variance</th><th /></tr>
                    </thead>
                    <tbody>
                        <tr v-for="shift in history" :key="shift.id">
                            <td>{{ shift.id }}</td>
                            <td>{{ shift.cashier }}</td>
                            <td>{{ shift.branch_name }}</td>
                            <td>{{ shift.opened_at }}</td>
                            <td>{{ shift.closed_at ?? '-' }}</td>
                            <td>{{ shift.status }}</td>
                            <td class="text-end">{{ shift.expected_cash === null ? '-' : money(shift.expected_cash) }}</td>
                            <td class="text-end">{{ shift.counted_cash === null ? '-' : money(shift.counted_cash) }}</td>
                            <td class="text-end">{{ shift.variance === null ? '-' : money(shift.variance) }}</td>
                            <td><button type="button" class="btn btn-outline-secondary btn-sm" @click="view(shift)">{{ shift.status === 'open' ? 'X report' : 'Z report' }}</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
