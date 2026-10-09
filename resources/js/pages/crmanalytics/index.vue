<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import { BarChart3, Target, TrendingUp, Trophy, Users } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'CRM Analytics',
            subtitle: 'Conversion, pipeline, sales activity and salesperson performance',
            breadcrumbs: [
                {
                    title: 'CRM Analytics',
                    href: 'NULL',
                },
            ],
        },
    });

    const { props } = usePage();
    const { Notify, fetchCompany, companiesdata } = useCommons();

    type ConversionStage = { status: string; count: number };
    type ConversionData = { stages: ConversionStage[]; total_leads: number; converted: number; conversion_rate: number };

    type PipelineStageRow = { stage_id: number; stage: string; is_won: boolean; is_lost: boolean; count: number; value: number };
    type PipelineData = { stages: PipelineStageRow[]; open_value: number };

    type ActivityTypeRow = { type: string; count: number };
    type SalesActivityData = { types: ActivityTypeRow[]; total: number; completed: number; pending: number; start_date: string; end_date: string };

    type PerformanceRow = { user_id: number; name: string; leads_assigned: number; open_opportunities: number; won_opportunities: number; won_value: number; activities_logged: number };

    const authUser = computed(() => props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
    } | null);

    const roleName = computed(() => String(authUser.value?.rolename ?? '').toLowerCase().replace(/\s+/g, ''));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');

    const loading = ref(true);
    const selectedCompanyId = ref<string | number>('');
    const startDate = ref('');
    const endDate = ref('');

    const conversion = ref<ConversionData>({ stages: [], total_leads: 0, converted: 0, conversion_rate: 0 });
    const pipeline = ref<PipelineData>({ stages: [], open_value: 0 });
    const salesActivity = ref<SalesActivityData>({ types: [], total: 0, completed: 0, pending: 0, start_date: '', end_date: '' });
    const performance = ref<PerformanceRow[]>([]);

    const conversionMax = computed(() => Math.max(...conversion.value.stages.map((s) => s.count), 1));
    const pipelineMax = computed(() => Math.max(...pipeline.value.stages.map((s) => s.count), 1));
    const activityMax = computed(() => Math.max(...salesActivity.value.types.map((t) => t.count), 1));

    function companyParams() {
        return selectedCompanyId.value ? { company_id: selectedCompanyId.value } : {};
    }

    async function loadAll() {
        loading.value = true;

        try {
            const [conversionRes, pipelineRes, activityRes, performanceRes] = await Promise.all([
                window.axios.get('/api/crmanalytics/conversion', { params: companyParams() }),
                window.axios.get('/api/crmanalytics/pipeline', { params: companyParams() }),
                window.axios.get('/api/crmanalytics/sales-activity', { params: { ...companyParams(), start_date: startDate.value || undefined, end_date: endDate.value || undefined } }),
                window.axios.get('/api/crmanalytics/salesperson-performance', { params: companyParams() }),
            ]);

            conversion.value = conversionRes.data;
            pipeline.value = pipelineRes.data;
            salesActivity.value = activityRes.data;
            startDate.value = activityRes.data.start_date;
            endDate.value = activityRes.data.end_date;
            performance.value = performanceRes.data.data ?? [];
        } catch {
            Notify('Unable to load CRM analytics.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    function formatStatus(status: string): string {
        return status.replace('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    }

    onMounted(async () => {
        selectedCompanyId.value = authUser.value?.company_id ?? '';

        if (isSuperadmin.value) {
            await fetchCompany();
        }

        await loadAll();
    });
</script>

<template>
    <Head title="CRM Analytics" />

    <div class="admin-list-page">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <select
                    v-if="isSuperadmin"
                    class="form-select form-select-sm"
                    style="width: auto;"
                    v-model="selectedCompanyId"
                    @change="loadAll"
                >
                    <option value="">All companies</option>
                    <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                        {{ company.text ?? company.name }}
                    </option>
                </select>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2">
                <label class="small text-muted mb-0">Activity range</label>
                <input type="date" class="form-control form-control-sm" style="width: auto;" v-model="startDate" @change="loadAll" />
                <span class="text-muted">to</span>
                <input type="date" class="form-control form-control-sm" style="width: auto;" v-model="endDate" @change="loadAll" />
            </div>
        </div>

        <Loader v-if="loading" message="Loading CRM analytics…" />

        <div v-else class="row g-3">
            <div class="col-12 col-xl-6">
                <div class="admin-list-card p-3 h-100">
                    <h6 class="d-flex align-items-center gap-2 mb-3">
                        <Target class="h-4 w-4" />
                        Lead Conversion Funnel
                    </h6>
                    <p class="small text-muted mb-3">
                        {{ conversion.total_leads }} leads total, {{ conversion.converted }} converted ({{ conversion.conversion_rate }}%)
                    </p>
                    <div v-for="stage in conversion.stages" :key="stage.status" class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-capitalize">{{ formatStatus(stage.status) }}</span>
                            <span class="text-muted">{{ stage.count }}</span>
                        </div>
                        <div class="dash-country-row__bar">
                            <div class="dash-country-row__bar-fill" :style="{ width: Math.max((stage.count / conversionMax) * 100, 1) + '%', background: stage.status === 'converted' ? '#16a34a' : '#6366f1' }"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="admin-list-card p-3 h-100">
                    <h6 class="d-flex align-items-center gap-2 mb-3">
                        <TrendingUp class="h-4 w-4" />
                        Pipeline / Funnel by Stage
                    </h6>
                    <p class="small text-muted mb-3">Total open deal value: {{ pipeline.open_value }}</p>
                    <div v-for="stage in pipeline.stages" :key="stage.stage_id" class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span>{{ stage.stage }}</span>
                            <span class="text-muted">{{ stage.count }} · {{ stage.value }}</span>
                        </div>
                        <div class="dash-country-row__bar">
                            <div
                                class="dash-country-row__bar-fill"
                                :style="{ width: Math.max((stage.count / pipelineMax) * 100, 1) + '%', background: stage.is_won ? '#16a34a' : stage.is_lost ? '#ef4444' : '#f59e0b' }"
                            ></div>
                        </div>
                    </div>
                    <p v-if="!pipeline.stages.length" class="text-muted small mb-0">No pipeline stages configured yet.</p>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="admin-list-card p-3 h-100">
                    <h6 class="d-flex align-items-center gap-2 mb-3">
                        <BarChart3 class="h-4 w-4" />
                        Sales Activity
                    </h6>
                    <p class="small text-muted mb-3">
                        {{ salesActivity.total }} logged ({{ salesActivity.completed }} completed, {{ salesActivity.pending }} pending)
                    </p>
                    <div v-for="type in salesActivity.types" :key="type.type" class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-capitalize">{{ formatStatus(type.type) }}</span>
                            <span class="text-muted">{{ type.count }}</span>
                        </div>
                        <div class="dash-country-row__bar">
                            <div class="dash-country-row__bar-fill" :style="{ width: Math.max((type.count / activityMax) * 100, 1) + '%', background: '#0ea5e9' }"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="admin-list-card p-3 h-100">
                    <h6 class="d-flex align-items-center gap-2 mb-3">
                        <Trophy class="h-4 w-4" />
                        Salesperson Performance
                    </h6>

                    <div v-if="!performance.length" class="d-flex flex-column align-items-center text-muted small py-4">
                        <Users class="h-5 w-5 mb-2" />
                        No leads, opportunities or activities are assigned to anyone yet.
                    </div>

                    <div v-else class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr class="text-muted small">
                                    <th>Salesperson</th>
                                    <th class="text-end">Leads</th>
                                    <th class="text-end">Open Deals</th>
                                    <th class="text-end">Won</th>
                                    <th class="text-end">Won Value</th>
                                    <th class="text-end">Activities</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in performance" :key="row.user_id">
                                    <td>{{ row.name }}</td>
                                    <td class="text-end">{{ row.leads_assigned }}</td>
                                    <td class="text-end">{{ row.open_opportunities }}</td>
                                    <td class="text-end">{{ row.won_opportunities }}</td>
                                    <td class="text-end">{{ row.won_value }}</td>
                                    <td class="text-end">{{ row.activities_logged }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
    .dash-country-row__bar {
        width: 100%;
        height: 8px;
        border-radius: 4px;
        background: #f1f5f9;
        overflow: hidden;
    }

    .dash-country-row__bar-fill {
        height: 100%;
        border-radius: 4px;
    }
</style>
