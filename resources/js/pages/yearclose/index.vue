<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { onMounted, ref } from 'vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Year-End Close',
            subtitle: 'Carry the closing year into the new one, record the closing and make the new year active',
            breadcrumbs: [{ title: 'Year-End Close', href: 'NULL' }],
        },
    });

    type Year = { id: number; name: string; start_date: string; end_date: string; status: boolean };
    type Line = { code: string; name: string; balance: number; closing_side: string; closing_amount: number };
    type Preview = {
        from: { name: string }; to: { name: string }; blockers: string[]; can_run: boolean; closed_before: boolean;
        statement: { net_result: number; branches: { branch_id: number; branch_name: string | null; net_result: number; retained_earnings: { code: string; name: string } | null; lines: Line[] }[] };
    };
    type Closing = { id: number; from_year: string | null; to_year: string | null; closed_on: string; net_result: number };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const years = ref<Year[]>([]);
    const closings = ref<Closing[]>([]);
    const form = ref({ from_year_id: '' as number | '', to_year_id: '' as number | '' });
    const preview = ref<Preview | null>(null);

    async function load() {
        const result = (await run(() => window.axios.get('/api/year-close', { params: scope() })))?.data;
        years.value = result?.years ?? [];
        closings.value = result?.data ?? [];
    }

    async function check() {
        preview.value = (await run(() => window.axios.get('/api/year-close/preview', { params: { ...scope(), ...form.value } })))?.data?.data ?? null;
    }

    async function close() {
        if (! window.confirm('Close the year? Opening balances of the new year are written and the new year becomes the active one.')) {
            return;
        }

        if (await run(() => window.axios.post('/api/year-close', { ...scope(), ...form.value }), 'Year closed')) {
            preview.value = null;
            await load();
        }
    }

    onMounted(load);
</script>

<template>
    <Head title="Year-End Close" />

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-end">
            <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />
            <div>
                <label class="form-label small" for="yc-from">Closing year</label>
                <select id="yc-from" v-model="form.from_year_id" class="form-select form-select-sm"><option value="">Choose...</option><option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}</option></select>
            </div>
            <div>
                <label class="form-label small" for="yc-to">New year</label>
                <select id="yc-to" v-model="form.to_year_id" class="form-select form-select-sm"><option value="">Choose...</option><option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}</option></select>
            </div>
            <button type="button" class="btn btn-outline-dark btn-sm" @click="check">Preview</button>
        </div>

        <div v-if="preview" class="border rounded p-3 mb-4">
            <h6>{{ preview.from.name }} &rarr; {{ preview.to.name }}</h6>
            <ul v-if="preview.blockers.length" class="text-danger small"><li v-for="blocker in preview.blockers" :key="blocker">{{ blocker }}</li></ul>
            <p>Result carried to retained earnings: <strong>{{ money(preview.statement.net_result) }}</strong></p>

            <div v-for="branch in preview.statement.branches" :key="branch.branch_id" class="mb-3">
                <strong>{{ branch.branch_name ?? 'Branch ' + branch.branch_id }}</strong>
                <span class="text-muted"> to {{ branch.retained_earnings?.name ?? 'no retained earnings account mapped' }}: {{ money(branch.net_result) }}</span>
                <table class="table table-sm mt-1">
                    <thead><tr><th>Account</th><th class="text-end">Year balance</th><th>Closing entry</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        <tr v-for="line in branch.lines" :key="line.code"><td>{{ line.code }} {{ line.name }}</td><td class="text-end">{{ money(line.balance) }}</td><td class="text-uppercase">{{ line.closing_side }}</td><td class="text-end">{{ money(line.closing_amount) }}</td></tr>
                        <tr v-if="! branch.lines.length"><td colspan="4" class="text-center text-muted">No revenue or cost accounts moved.</td></tr>
                    </tbody>
                </table>
            </div>

            <p class="small text-muted">The closing is a record: no entry is posted to the ledger, because the rollover already carries the result into the new year's opening balances.</p>
            <button v-if="can('/yearclose/run')" type="button" class="btn btn-dark btn-sm" :disabled="! preview.can_run" @click="close">{{ preview.closed_before ? 'Close again' : 'Close year' }}</button>
        </div>

        <h6>Closings</h6>
        <table class="table table-sm">
            <thead><tr><th>Year</th><th>New year</th><th>Closed on</th><th class="text-end">Result</th></tr></thead>
            <tbody>
                <tr v-for="closing in closings" :key="closing.id"><td>{{ closing.from_year }}</td><td>{{ closing.to_year }}</td><td>{{ closing.closed_on?.slice(0, 10) }}</td><td class="text-end">{{ money(closing.net_result) }}</td></tr>
                <tr v-if="! closings.length"><td colspan="4" class="text-center text-muted">No year has been closed here yet.</td></tr>
            </tbody>
        </table>
    </div>
</template>
