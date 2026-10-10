<script setup lang="ts">
    import { computed, onMounted, reactive, ref, watch } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    const props = defineProps({
        companyId: { type: [String, Number], default: '' },
    });

    type HistoryRow = { id: number; action: string; locked_through: string | null; reason: string | null; by: string | null; at: string | null };
    type LockState = {
        locked_through: string | null;
        reason: string | null;
        locked_by: string | null;
        locked_at: string | null;
        history: HistoryRow[];
        rules: { max_backdate_days: number | null; allow_future_dates: boolean | null };
        can: { add: boolean; reopen: boolean; override: boolean; rollover: boolean };
    };

    const { Notify } = useCommons();

    const state = ref<LockState | null>(null);
    const loading = ref(false);
    const saving = ref(false);
    const denied = ref(false);

    const form = reactive({ locked_through: '', reason: '' });
    const rules = reactive({ max_backdate_days: '' as string | number, allow_future_dates: '' as string | boolean, reason: '' });

    const today = new Date().toISOString().slice(0, 10);
    const canAct = computed(() => Boolean(state.value?.can.add || state.value?.can.reopen));
    const actionLabels: Record<string, string> = { lock: 'Locked', advance: 'Moved forward', reopen: 'Reopened', clear: 'Cleared' };

    function message(error: unknown, fallback: string): string {
        if (window.axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors as Record<string, string[]> | undefined;

            return (errors ? Object.values(errors)[0]?.[0] : null) || error.response?.data?.message || fallback;
        }

        return fallback;
    }

    async function load() {
        if (!props.companyId) {
            state.value = null;

            return;
        }

        loading.value = true;
        denied.value = false;

        try {
            const response = await window.axios.get(API_ENDPOINTS.periodLocks, { params: { company_id: props.companyId } });
            state.value = response.data;
            rules.max_backdate_days = response.data.rules.max_backdate_days ?? '';
            rules.allow_future_dates = response.data.rules.allow_future_dates === null ? '' : response.data.rules.allow_future_dates;
        } catch (error: unknown) {
            state.value = null;
            denied.value = window.axios.isAxiosError(error) && error.response?.status === 403;
        } finally {
            loading.value = false;
        }
    }

    async function save(clear = false) {
        saving.value = true;

        try {
            const response = await window.axios.post(API_ENDPOINTS.periodLocks, {
                company_id: props.companyId,
                locked_through: clear ? null : form.locked_through || null,
                reason: form.reason,
            });
            Notify(response.data.message || 'Period lock saved', 'success');
            form.locked_through = '';
            form.reason = '';
            await load();
        } catch (error: unknown) {
            Notify(message(error, 'Unable to save the period lock'), 'alert');
        } finally {
            saving.value = false;
        }
    }

    async function saveRules() {
        saving.value = true;

        try {
            await window.axios.put(`${API_ENDPOINTS.periodLocks}/rules`, {
                company_id: props.companyId,
                max_backdate_days: rules.max_backdate_days === '' ? null : Number(rules.max_backdate_days),
                allow_future_dates: rules.allow_future_dates === '' ? null : rules.allow_future_dates === true || rules.allow_future_dates === 'true',
                reason: rules.reason,
            });
            Notify('Dating rules saved', 'success');
            rules.reason = '';
            await load();
        } catch (error: unknown) {
            Notify(message(error, 'Unable to save the dating rules'), 'alert');
        } finally {
            saving.value = false;
        }
    }

    onMounted(load);
    watch(() => props.companyId, load);
    defineExpose({ reload: load, state });
</script>

<template>
    <section v-if="state" class="company-setting-tab-panel period-lock-card">
        <div class="company-setting-tab-toolbar">
            <div>
                <h6 class="mb-1">Period lock</h6>
                <p class="company-setting-section-help mb-0">
                    <template v-if="state.locked_through">
                        Books are locked through <strong>{{ state.locked_through }}</strong>
                        <span v-if="state.locked_by"> by {{ state.locked_by }}</span>
                        <span v-if="state.reason"> - {{ state.reason }}</span>.
                        Nothing dated on or before that day can be created, changed, deleted or restored.
                    </template>
                    <template v-else>The books are open: nothing is locked.</template>
                </p>
            </div>
        </div>

        <div v-if="canAct" class="row g-2 align-items-end mb-3">
            <div class="col-md-3">
                <label class="form-label" for="period-lock-date">Lock through</label>
                <input id="period-lock-date" v-model="form.locked_through" type="date" class="form-control form-control-sm" :max="today" />
            </div>
            <div class="col-md-5">
                <label class="form-label" for="period-lock-reason">Reason</label>
                <input id="period-lock-reason" v-model="form.reason" type="text" maxlength="500" class="form-control form-control-sm" placeholder="e.g. August closed and reviewed" />
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="button" class="btn btn-sm btn-primary" :disabled="saving || !form.locked_through || form.reason.length < 3" @click="save()">
                    {{ state.locked_through ? 'Move lock' : 'Lock period' }}
                </button>
                <button
                    v-if="state.can.reopen && state.locked_through"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    :disabled="saving || form.reason.length < 3"
                    @click="save(true)"
                >
                    Clear lock
                </button>
            </div>
            <div class="col-12">
                <small class="text-muted">
                    Moving the lock forward is open to the accountant; moving it back or clearing it is for administrators. Every change is kept below and in the audit log.
                </small>
            </div>
        </div>

        <div v-if="state.can.reopen" class="row g-2 align-items-end mb-3">
            <div class="col-12"><h6 class="mb-0">Dating rules</h6></div>
            <div class="col-md-3">
                <label class="form-label" for="period-rule-days">Not older than (days)</label>
                <input id="period-rule-days" v-model="rules.max_backdate_days" type="number" min="0" class="form-control form-control-sm" placeholder="No limit" />
            </div>
            <div class="col-md-3">
                <label class="form-label" for="period-rule-future">Future dates</label>
                <select id="period-rule-future" v-model="rules.allow_future_dates" class="form-select form-select-sm">
                    <option value="">No restriction</option>
                    <option :value="false">Not allowed</option>
                    <option :value="true">Allowed</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="period-rule-reason">Reason</label>
                <input id="period-rule-reason" v-model="rules.reason" type="text" maxlength="500" class="form-control form-control-sm" />
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-sm btn-outline-primary" :disabled="saving || rules.reason.length < 3" @click="saveRules">Save rules</button>
            </div>
        </div>

        <div class="table-responsive company-setting-table-wrap">
            <table class="table table-sm align-middle company-setting-table">
                <thead>
                    <tr><th>When</th><th>What</th><th>Locked through</th><th>By</th><th>Reason</th></tr>
                </thead>
                <tbody>
                    <tr v-if="state.history.length === 0"><td colspan="5" class="text-center text-muted">No lock has been set yet</td></tr>
                    <tr v-for="row in state.history" :key="row.id">
                        <td>{{ row.at }}</td>
                        <td>{{ actionLabels[row.action] || row.action }}</td>
                        <td>{{ row.locked_through || '-' }}</td>
                        <td>{{ row.by || '-' }}</td>
                        <td>{{ row.reason || '-' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
    <p v-else-if="loading" class="text-muted small">Loading period lock...</p>
</template>
