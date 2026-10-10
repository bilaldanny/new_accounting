<script setup lang="ts">
    import { computed, onMounted, ref, watch } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    const props = defineProps({
        companyId: { type: [String, Number], default: '' },
    });

    type Year = { id: number; name: string; start_date: string; end_date: string };
    type Row = { coa_id: number; code: string; name: string; class: string; opening: number; nature: string; current: { opening: number; nature: string } | null };
    type Branch = {
        branch_id: number;
        branch_name: string | null;
        retained_earnings: { code: string; name: string } | null;
        net_profit: number;
        difference: number;
        rows: Row[];
        warnings: string[];
    };
    type Preview = { from: Year; to: Year; locked_through: string | null; branches: Branch[]; blockers: string[]; can_run: boolean; accounts: number; note: string };

    const { Notify } = useCommons();

    const years = ref<Year[]>([]);
    const fromYear = ref('');
    const toYear = ref('');
    const preview = ref<Preview | null>(null);
    const allowed = ref(true);
    const loading = ref(false);
    const running = ref(false);

    const ready = computed(() => Boolean(fromYear.value && toYear.value && fromYear.value !== toYear.value));

    function money(value: number): string {
        return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function message(error: unknown, fallback: string): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors as Record<string, string[]> | undefined;

            return (errors ? Object.values(errors)[0]?.[0] : null) || error.response?.data?.message || fallback;
        }

        return fallback;
    }

    async function loadYears() {
        if (!props.companyId) {
            years.value = [];

            return;
        }

        try {
            const response = await window.axios.get(API_ENDPOINTS.financialYears, {
                params: { company_id: props.companyId, show_record: 100, sort_by: 'start_date', sort_type: 'asc' },
            });
            years.value = (response.data?.data ?? []) as Year[];
        } catch {
            years.value = [];
        }
    }

    async function loadPreview() {
        preview.value = null;

        if (!ready.value) {
            return;
        }

        loading.value = true;

        try {
            const response = await window.axios.get('/api/year-rollover/preview', {
                params: { company_id: props.companyId, from_year_id: fromYear.value, to_year_id: toYear.value },
            });
            preview.value = response.data.data;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error) && error.response?.status === 403) {
                allowed.value = false;
            } else {
                Notify(message(error, 'Unable to prepare the rollover'), 'alert');
            }
        } finally {
            loading.value = false;
        }
    }

    async function run() {
        running.value = true;

        try {
            const response = await window.axios.post('/api/year-rollover', {
                company_id: props.companyId,
                from_year_id: fromYear.value,
                to_year_id: toYear.value,
            });
            Notify(response.data.message || 'Opening balances written', 'success');
            await loadPreview();
        } catch (error: unknown) {
            Notify(message(error, 'Unable to run the rollover'), 'alert');
        } finally {
            running.value = false;
        }
    }

    onMounted(loadYears);
    watch(() => props.companyId, loadYears);
    watch([fromYear, toYear], loadPreview);
</script>

<template>
    <section v-if="allowed && years.length > 1" class="company-setting-tab-panel year-rollover-card">
        <div class="company-setting-tab-toolbar">
            <div>
                <h6 class="mb-1">Year rollover</h6>
                <p class="company-setting-section-help mb-0">
                    Carry the closing balances of a locked year into the next year as its opening balances. The profit of the year goes to the
                    retained earnings account. Nothing is posted; it can be run again if a late entry was let in.
                </p>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <label class="form-label" for="rollover-from">Closing year</label>
                <select id="rollover-from" v-model="fromYear" class="form-select form-select-sm">
                    <option value="">Select year</option>
                    <option v-for="year in years" :key="year.id" :value="String(year.id)">{{ year.name }} ({{ year.start_date?.slice(0, 10) }} to {{ year.end_date?.slice(0, 10) }})</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="rollover-to">New year</label>
                <select id="rollover-to" v-model="toYear" class="form-select form-select-sm">
                    <option value="">Select year</option>
                    <option v-for="year in years" :key="year.id" :value="String(year.id)">{{ year.name }} ({{ year.start_date?.slice(0, 10) }} to {{ year.end_date?.slice(0, 10) }})</option>
                </select>
            </div>
        </div>

        <p v-if="loading" class="text-muted small">Preparing the preview...</p>

        <template v-if="preview">
            <div v-if="preview.blockers.length" class="alert alert-warning py-2">
                <div v-for="blocker in preview.blockers" :key="blocker">{{ blocker }}</div>
            </div>

            <div v-for="branch in preview.branches" :key="branch.branch_id" class="mb-3">
                <div class="d-flex flex-wrap gap-3 align-items-baseline mb-1">
                    <strong>{{ branch.branch_name || `Branch ${branch.branch_id}` }}</strong>
                    <span class="text-muted small">
                        Profit for the year {{ money(branch.net_profit) }}
                        <template v-if="branch.retained_earnings"> to {{ branch.retained_earnings.code }} {{ branch.retained_earnings.name }}</template>
                    </span>
                    <span v-if="branch.difference !== 0" class="text-danger small">Out of balance by {{ money(branch.difference) }}</span>
                </div>
                <div v-for="warning in branch.warnings" :key="warning" class="text-warning small">{{ warning }}</div>
                <div class="table-responsive company-setting-table-wrap">
                    <table class="table table-sm align-middle company-setting-table">
                        <thead>
                            <tr><th>Account</th><th>Class</th><th class="text-end">New opening</th><th>Side</th><th class="text-end">Opening now</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in branch.rows" :key="row.coa_id">
                                <td>{{ row.code }} {{ row.name }}</td>
                                <td>{{ row.class }}</td>
                                <td class="text-end">{{ money(row.opening) }}</td>
                                <td>{{ row.nature === 'cr' ? 'Cr' : 'Dr' }}</td>
                                <td class="text-end">{{ row.current ? `${money(row.current.opening)} ${row.current.nature === 'cr' ? 'Cr' : 'Dr'}` : '-' }}</td>
                            </tr>
                            <tr v-if="branch.rows.length === 0"><td colspan="5" class="text-center text-muted">Nothing to carry over</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="text-muted small mb-2">{{ preview.note }}</p>
            <button type="button" class="btn btn-sm btn-primary" :disabled="!preview.can_run || running" @click="run">
                Write opening balances of {{ preview.to.name }}
            </button>
        </template>
    </section>
</template>
