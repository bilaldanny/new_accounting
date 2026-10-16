<script setup lang="ts">
    import { Head, usePage } from '@inertiajs/vue3';
    import ClientTable from '@/components/ClientTable.vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    /**
     * A self-contained page, the same shape as "Settings > API Keys" and Webhooks — every action here
     * (change status, change plan) is a single inline field per row, not a multi-step form, so a
     * separate add/edit Vueform page would only add weight.
     */
    defineOptions({
        layout: {
            title: 'Tenant Directory',
            subtitle: 'Every company as a SaaS tenant — its plan, status and usage',
            breadcrumbs: [
                { title: 'Tenant Directory', href: 'NULL' },
            ],
        },
    });

    const STATUSES = ['trial', 'active', 'suspended', 'cancelled'];

    type Tenant = {
        id: number;
        name: string;
        tenant_status: string;
        plan_name: string | null;
        subscription_plan_id: number | null;
        users_count: number;
        branches_count: number;
        max_users: number;
        max_branches: number;
    };

    type Plan = { id: number; name: string; text?: string };

    const page = usePage();
    const { Notify } = useCommons();

    const loading = ref(true);
    const tenants = ref<Tenant[]>([]);
    const plans = ref<Plan[]>([]);

    const user = computed(() => page.props.auth?.user as { rolename?: string } | null);
    const isSuperadmin = computed(() => String(user.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');

    function errorMessage(error: unknown): string {
        if (! window.axios.isAxiosError(error)) {
            return 'Unexpected error occurred';
        }

        const errors = Object.values(error.response?.data?.errors ?? {}).flat() as string[];

        return errors[0] || error.response?.data?.message || 'The request failed.';
    }

    async function load() {
        loading.value = true;

        try {
            const [tenantsResponse, plansResponse] = await Promise.all([
                window.axios.get(API_ENDPOINTS.tenants),
                window.axios.get(API_ENDPOINTS.fetchSubscriptionPlans),
            ]);
            tenants.value = tenantsResponse.data?.data?.data ?? tenantsResponse.data?.data ?? [];
            plans.value = plansResponse.data ?? [];
        } catch {
            Notify('Unable to load the tenant directory.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function changeStatus(tenant: Tenant, status: string) {
        try {
            await window.axios.post(API_ENDPOINTS.tenantStatus(tenant.id), { status });
            tenant.tenant_status = status;
            Notify('Tenant status updated', 'success');
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
            await load();
        }
    }

    async function changePlan(tenant: Tenant, planId: number | string) {
        try {
            await window.axios.post(API_ENDPOINTS.tenantPlan(tenant.id), { subscription_plan_id: planId });
            Notify('Tenant plan updated', 'success');
            await load();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        }
    }

    onMounted(() => {
        if (isSuperadmin.value) {
            load();
        } else {
            loading.value = false;
        }
    });
</script>

<template>
    <Head title="Tenant Directory" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__body p-3 p-md-4">
                <div v-if="! isSuperadmin" class="alert alert-warning small">
                    Only the superadmin can view the tenant directory.
                </div>

                <template v-else>
                    <Loader v-if="loading" message="Loading tenants…" />

                    <div v-else class="table-responsive">
                        <ClientTable  :columns="[{ key: 'c0', label: 'Company' }, { key: 'c1', label: 'Plan' }, { key: 'c2', label: 'Status' }, { key: 'c3', label: 'Users' }, { key: 'c4', label: 'Branches' }]" :rows="(tenants).map((tenant) => ({ __s: { tenant }, c0: (tenant.name), c1: '', c2: '', c3: `${tenant.users_count} / ${tenant.max_users}`, c4: `${tenant.branches_count} / ${tenant.max_branches}` }))" export-name="company">
<template #cell-c1="{ row: __m }"><template v-for="{ tenant } in [__m.__s]" :key="0">
                                        <select
                                            class="form-select form-select-sm"
                                            :value="tenant.subscription_plan_id ?? ''"
                                            @change="changePlan(tenant, ($event.target as HTMLSelectElement).value)"
                                        >
                                            <option value="" disabled>No plan</option>
                                            <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.text ?? plan.name }}</option>
                                        </select>
                                    </template></template>
<template #cell-c2="{ row: __m }"><template v-for="{ tenant } in [__m.__s]" :key="0">
                                        <select
                                            class="form-select form-select-sm"
                                            :value="tenant.tenant_status"
                                            @change="changeStatus(tenant, ($event.target as HTMLSelectElement).value)"
                                        >
                                            <option v-for="status in STATUSES" :key="status" :value="status">{{ status }}</option>
                                        </select>
                                    </template></template>
</ClientTable>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
